<?php

declare(strict_types=1);

namespace App\Domain;

/** Business-rule validation failure carrying field errors. */
final class ValidationException extends \RuntimeException
{
    /** @param array<string,string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct((string) (array_values($errors)[0] ?? 'Validation failed'));
    }
}
