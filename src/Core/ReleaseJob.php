<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Throw from a job to put it back on the queue after $delaySeconds WITHOUT counting
 * an attempt (used for throttling and paused campaigns).
 */
final class ReleaseJob extends \RuntimeException
{
    public function __construct(public readonly int $delaySeconds, string $reason = '')
    {
        parent::__construct($reason);
    }
}
