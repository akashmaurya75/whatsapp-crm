<?php

declare(strict_types=1);

namespace App\WhatsApp;

use App\Core\Request;

/**
 * Adapter contract for WhatsApp providers.
 * $account is a whatsapp_accounts row; $credentials its decrypted credential array.
 */
interface Provider
{
    public function key(): string;

    public function label(): string;

    /** True only for Meta's official WhatsApp Business Platform. */
    public function isOfficial(): bool;

    /** @return list<Capability> */
    public function capabilities(): array;

    /** Credential fields the UI must collect: name => [label, secret?] */
    public function credentialFields(): array;

    public function sendText(array $account, array $credentials, string $toE164, string $body, ?string $replyToProviderId = null): SendResult;

    /** @param string $type image|document|audio|video */
    public function sendMedia(array $account, array $credentials, string $toE164, string $type, string $mediaUrl, ?string $caption = null): SendResult;

    /** @return array{ok:bool,message:string,details?:array} */
    public function checkHealth(array $account, array $credentials): array;

    /** Provider-specific GET handshake (e.g. Meta hub.challenge). Returns the body to echo, or null if not applicable/invalid. */
    public function verificationChallenge(array $credentials, Request $request): ?string;

    /** Validate the webhook signature over the raw body. */
    public function verifySignature(array $credentials, Request $request): bool;

    /** @return list<NormalizedEvent> */
    public function parseWebhook(array $account, array $payload): array;
}
