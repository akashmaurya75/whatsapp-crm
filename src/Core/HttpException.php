<?php

declare(strict_types=1);

namespace App\Core;

final class HttpException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message = '', public readonly string $errorCode = 'error')
    {
        parent::__construct($message !== '' ? $message : match ($status) {
            401 => 'Unauthenticated.',
            403 => 'You do not have permission to do this.',
            404 => 'Not found.',
            419 => 'Your session expired. Please reload the page and try again.',
            429 => 'Too many requests. Please slow down.',
            default => 'Error',
        });
    }

    public static function notFound(): self { return new self(404, '', 'not_found'); }
    public static function forbidden(): self { return new self(403, '', 'forbidden'); }
}
