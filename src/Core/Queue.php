<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/** Database-backed job queue with retries, exponential backoff and failed-job storage. */
final class Queue
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @param class-string<Job> $jobClass
     * @param array<string,mixed> $data
     */
    public function push(string $jobClass, array $data, string $queue = 'default', int $delaySeconds = 0): int
    {
        if (!is_subclass_of($jobClass, Job::class)) {
            throw new \InvalidArgumentException("$jobClass is not a Job");
        }
        return $this->db->insert('jobs', [
            'queue' => $queue,
            'payload' => json_encode(['job' => $jobClass, 'data' => $data], JSON_THROW_ON_ERROR),
            'attempts' => 0,
            'available_at' => time() + $delaySeconds,
            'reserved_at' => null,
            'created_at' => now(),
        ]);
    }

    /** Atomically reserve the next available job. Reservation expires after $visibility seconds (crash recovery). */
    public function reserve(string $queue, string $workerId, int $visibility = 300): ?array
    {
        $now = time();
        for ($try = 0; $try < 5; $try++) {
            $job = $this->db->first(
                'SELECT * FROM jobs WHERE queue = ? AND available_at <= ? AND (reserved_at IS NULL OR reserved_at < ?) ORDER BY id LIMIT 1',
                [$queue, $now, $now - $visibility]
            );
            if ($job === null) {
                return null;
            }
            // Optimistic claim: only succeeds if nobody else claimed it meanwhile.
            $claimed = $this->db->run(
                'UPDATE jobs SET reserved_at = ?, reserved_by = ?, attempts = attempts + 1 WHERE id = ? AND attempts = ?',
                [$now, $workerId, $job['id'], $job['attempts']]
            )->rowCount();
            if ($claimed === 1) {
                $job['attempts'] = (int) $job['attempts'] + 1;
                return $job;
            }
        }
        return null;
    }

    /** Process at most one job. Returns false when the queue is empty. */
    public function work(App $app, string $queue = 'default', string $workerId = 'worker'): bool
    {
        $job = $this->reserve($queue, $workerId);
        if ($job === null) {
            return false;
        }
        $payload = json_decode((string) $job['payload'], true);
        $class = is_array($payload) ? (string) ($payload['job'] ?? '') : '';
        if ($class === '' || !is_subclass_of($class, Job::class)) {
            $this->fail($job, 'Invalid job class');
            return true;
        }
        /** @var Job $handler */
        $handler = new $class();
        try {
            $handler->handle($app, ['_attempt' => (int) $job['attempts']] + (array) ($payload['data'] ?? []));
            $this->db->run('DELETE FROM jobs WHERE id = ?', [$job['id']]);
        } catch (ReleaseJob $r) {
            $this->db->run(
                'UPDATE jobs SET reserved_at = NULL, reserved_by = NULL, attempts = attempts - 1, available_at = ? WHERE id = ?',
                [time() + max(1, $r->delaySeconds), $job['id']]
            );
        } catch (Throwable $e) {
            $attempt = (int) $job['attempts'];
            if ($e instanceof PermanentFailure || $attempt >= $handler->maxAttempts()) {
                $this->fail($job, $e::class . ': ' . $e->getMessage());
            } else {
                $this->db->run(
                    'UPDATE jobs SET reserved_at = NULL, reserved_by = NULL, available_at = ? WHERE id = ?',
                    [time() + $handler->backoff($attempt), $job['id']]
                );
                Logger::warning('Job failed, will retry', ['job' => $class, 'attempt' => $attempt, 'error' => $e->getMessage()]);
            }
        }
        return true;
    }

    private function fail(array $job, string $reason): void
    {
        $this->db->transaction(function () use ($job, $reason): void {
            $this->db->insert('failed_jobs', [
                'queue' => $job['queue'],
                'payload' => $job['payload'],
                'exception' => mb_substr($reason, 0, 2000),
                'failed_at' => now(),
            ]);
            $this->db->run('DELETE FROM jobs WHERE id = ?', [$job['id']]);
        });
        Logger::error('Job permanently failed', ['job_id' => $job['id'], 'reason' => $reason]);
    }

    public function retryFailed(int $failedId): bool
    {
        $row = $this->db->first('SELECT * FROM failed_jobs WHERE id = ?', [$failedId]);
        if ($row === null) {
            return false;
        }
        $this->db->transaction(function () use ($row): void {
            $this->db->insert('jobs', [
                'queue' => $row['queue'], 'payload' => $row['payload'], 'attempts' => 0,
                'available_at' => time(), 'reserved_at' => null, 'created_at' => now(),
            ]);
            $this->db->run('DELETE FROM failed_jobs WHERE id = ?', [$row['id']]);
        });
        return true;
    }
}
