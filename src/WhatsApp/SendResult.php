<?php

declare(strict_types=1);

namespace App\WhatsApp;

/** Outcome of a send attempt. success=true only when the provider accepted the message. */
final class SendResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?string $providerMessageId,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
        public readonly bool $retryable,
    ) {
    }

    public static function accepted(string $providerMessageId): self
    {
        return new self(true, $providerMessageId, null, null, false);
    }

    public static function failed(string $code, string $message, bool $retryable): self
    {
        return new self(false, null, mb_substr($code, 0, 40), mb_substr($message, 0, 500), $retryable);
    }
}
