<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Runs background work (queued jobs + scheduled maintenance) for a limited time, for hosts where a
 * permanent worker process isn't possible (shared hosting). Called from:
 *   - `php bin/console cron` every minute (about 50 s per run),
 *   - the web cron URL /cron?key=... (for panels that can only call a URL),
 *   - the end of normal web requests when QUEUE_MODE=inline (after the response has been sent).
 * A non-blocking file lock guarantees only one runner at a time; extra callers return immediately.
 */
final class BackgroundRunner
{
    public const LOCK = 'storage/runner.lock';
    public const LAST = 'storage/cron.last';
    public const QUEUES = ['webhooks', 'messages', 'default'];

    /** @return array{locked:bool,jobs:int,scheduled:bool,seconds:float} */
    public static function run(int $seconds, string $source, ?Database $db = null): array
    {
        $start = microtime(true);
        $result = ['locked' => false, 'jobs' => 0, 'scheduled' => false, 'seconds' => 0.0];
        $fh = @fopen(base_path(self::LOCK), 'c');
        if ($fh === false || !flock($fh, LOCK_EX | LOCK_NB)) {
            if ($fh !== false) {
                fclose($fh);
            }
            $result['locked'] = true;
            return $result;
        }
        @set_time_limit($seconds + 30);
        $db ??= Database::connection();
        $workerId = $source . ':' . (gethostname() ?: 'host') . ':' . getmypid();
        try {
            if ($source !== 'web') {
                self::migrateIfNeeded($db);
            }
            if ($source !== 'web' || Scheduler::due()) {
                $result['scheduled'] = Scheduler::run($db)['ran'];
            }
            $deadline = $start + $seconds;
            while (microtime(true) < $deadline) {
                self::heartbeat($source);
                $did = false;
                foreach (self::QUEUES as $q) {
                    // A fresh App per job: no tenant context or caches leak between jobs.
                    $app = new App($db, Session::memory());
                    if ($app->queue->work($app, $q, $workerId)) {
                        $did = true;
                        $result['jobs']++;
                        break;
                    }
                }
                if (!$did) {
                    // Web requests stop as soon as there's nothing to do; cron keeps polling briefly.
                    if ($source === 'web') {
                        break;
                    }
                    if (Scheduler::due()) {
                        $result['scheduled'] = Scheduler::run($db)['ran'] || $result['scheduled'];
                    }
                    usleep(1_000_000);
                }
            }
        } catch (Throwable $e) {
            Logger::error('Background runner failed', ['source' => $source, 'message' => $e->getMessage()]);
        } finally {
            self::heartbeat($source);
            flock($fh, LOCK_UN);
            fclose($fh);
        }
        $result['seconds'] = round(microtime(true) - $start, 2);
        return $result;
    }

    /** After an upload of a new version, apply its database migrations (runs inside the runner lock). */
    public static function migrateIfNeeded(Database $db): array
    {
        $dir = base_path('database/migrations');
        $files = count(glob($dir . '/*.php') ?: []);
        try {
            $done = (int) $db->scalar('SELECT COUNT(*) FROM migrations');
        } catch (Throwable) {
            $done = 0;
        }
        if ($files <= $done) {
            return [];
        }
        $applied = (new Migrator($db, $dir))->migrate();
        if ($applied !== []) {
            \App\Tenancy\TenantProvisioner::syncPermissions($db);
            Logger::info('Applied database migrations', ['migrations' => $applied]);
        }
        return $applied;
    }

    private static function heartbeat(string $source): void
    {
        @file_put_contents(base_path('storage/worker.heartbeat'), (string) time());
        @file_put_contents(base_path(self::LAST), time() . ' ' . $source);
    }

    /** Cheap check used after web requests: is there anything to do right now? */
    public static function hasWork(Database $db): bool
    {
        if (Scheduler::due()) {
            return true;
        }
        return $db->scalar('SELECT 1 FROM jobs WHERE available_at <= ? AND reserved_at IS NULL LIMIT 1', [time()]) !== null;
    }

    /** @return array{at:int,source:string}|null */
    public static function lastRun(): ?array
    {
        $f = base_path(self::LAST);
        if (!is_file($f)) {
            return null;
        }
        [$at, $source] = array_pad(explode(' ', trim((string) file_get_contents($f)), 2), 2, '');
        return ['at' => (int) $at, 'source' => $source];
    }

    /**
     * Inline mode: finish the HTTP response first, then do background work in the same PHP process.
     * Only when the server can end the response early (PHP-FPM or LiteSpeed), so visitors never wait.
     */
    public static function afterResponse(): void
    {
        if (strtolower((string) env('QUEUE_MODE', 'worker')) !== 'inline') {
            return;
        }
        $finish = function_exists('fastcgi_finish_request') ? 'fastcgi_finish_request'
            : (function_exists('litespeed_finish_request') ? 'litespeed_finish_request' : null);
        if ($finish === null) {
            return;
        }
        try {
            $db = Database::connection();
            if (!self::hasWork($db)) {
                return;
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            ignore_user_abort(true);
            $finish();
            self::run((int) env('QUEUE_INLINE_SECONDS', 20), 'web', $db);
        } catch (Throwable $e) {
            Logger::error('Inline background work failed', ['message' => $e->getMessage()]);
        }
    }
}
