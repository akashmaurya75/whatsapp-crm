<?php

declare(strict_types=1);

namespace App\Core;

/** Fixed-window rate limiter backed by the rate_limits table. */
final class RateLimiter
{
    public function __construct(private Database $db)
    {
    }

    /** Record a hit; returns true if still within the limit. */
    public function hit(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        $key = substr(hash('sha256', $key), 0, 64);
        $now = time();
        return $this->db->transaction(function () use ($key, $maxAttempts, $decaySeconds, $now): bool {
            $row = $this->db->first('SELECT hits, reset_at FROM rate_limits WHERE rl_key = ?', [$key]);
            if ($row === null || (int) $row['reset_at'] <= $now) {
                $this->db->run('DELETE FROM rate_limits WHERE rl_key = ?', [$key]);
                $this->db->insert('rate_limits', ['rl_key' => $key, 'hits' => 1, 'reset_at' => $now + $decaySeconds]);
                return 1 <= $maxAttempts;
            }
            $this->db->run('UPDATE rate_limits SET hits = hits + 1 WHERE rl_key = ?', [$key]);
            return ((int) $row['hits'] + 1) <= $maxAttempts;
        });
    }

    public function tooMany(string $key, int $maxAttempts): bool
    {
        $row = $this->db->first('SELECT hits, reset_at FROM rate_limits WHERE rl_key = ?', [substr(hash('sha256', $key), 0, 64)]);
        return $row !== null && (int) $row['reset_at'] > time() && (int) $row['hits'] >= $maxAttempts;
    }

    public function availableIn(string $key): int
    {
        $row = $this->db->first('SELECT reset_at FROM rate_limits WHERE rl_key = ?', [substr(hash('sha256', $key), 0, 64)]);
        return $row ? max(0, (int) $row['reset_at'] - time()) : 0;
    }

    public function clear(string $key): void
    {
        $this->db->run('DELETE FROM rate_limits WHERE rl_key = ?', [substr(hash('sha256', $key), 0, 64)]);
    }
}
