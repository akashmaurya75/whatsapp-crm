<?php

declare(strict_types=1);

namespace App\Core;

/** A queued unit of work. Jobs must be safe to retry (idempotent). */
interface Job
{
    /** @param array<string,mixed> $data */
    public function handle(App $app, array $data): void;

    /** Maximum attempts before the job is moved to failed_jobs. */
    public function maxAttempts(): int;

    /** Seconds to wait before retry N (1-based). */
    public function backoff(int $attempt): int;
}
