<?php

declare(strict_types=1);

namespace App\Core;

/** Throw from a job to skip retries (e.g. provider rejected the request as invalid). */
final class PermanentFailure extends \RuntimeException
{
}
