<?php

declare(strict_types=1);

namespace App\WhatsApp;

/** Provider-independent inbound event. */
final class NormalizedEvent
{
    public const MESSAGE = 'message';
    public const STATUS = 'status';
    public const TEMPLATE_STATUS = 'template_status';

    public function __construct(
        public readonly string $kind,
        /** Unique per provider; used for dedupe (webhook_events.event_key). */
        public readonly string $eventKey,
        public readonly string $providerMessageId,
        public readonly int $timestamp,
        // message fields
        public readonly ?string $fromPhone = null,
        public readonly ?string $contactName = null,
        public readonly string $messageType = 'text',
        public readonly ?string $body = null,
        public readonly ?string $mediaRef = null,
        public readonly ?string $mediaMime = null,
        public readonly ?string $replyToProviderId = null,
        // status fields
        public readonly ?string $status = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        // template status fields
        public readonly ?string $templateName = null,
        public readonly ?string $templateLanguage = null,
    ) {
    }
}
