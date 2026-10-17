<?php

declare(strict_types=1);

namespace App\WhatsApp;

use App\Core\HttpClient;
use App\Core\Request;
use App\Domain\Contacts\PhoneNormalizer;
use Throwable;

/**
 * OPTIONAL, UNOFFICIAL adapter for a self-hosted OpenWA (open-wa/wa-automate) EASY API gateway.
 * It automates a WhatsApp Web session. This is NOT the Meta Business Platform and may violate
 * WhatsApp's Terms of Service; numbers can be banned. Use only where you accept that risk.
 *
 * Contract used (open-wa EASY API): POST {base_url}/{method} with {"args":{...}}, header "api_key".
 * Webhook: gateway started with a webhook URL pointing at /webhooks/whatsapp/{account token}.
 * OpenWA does not sign webhooks natively: requests are authenticated by the unguessable
 * per-account URL token, plus an HMAC (X-Signature-256) when a signing secret is configured
 * (e.g. added by a reverse proxy).
 */
final class OpenWaProvider implements Provider
{
    public function __construct(private HttpClient $http)
    {
    }

    public function key(): string { return 'openwa'; }
    public function label(): string { return 'OpenWA-compatible gateway (unofficial)'; }
    public function isOfficial(): bool { return false; }

    public function capabilities(): array
    {
        return [Capability::Text, Capability::Media, Capability::Replies, Capability::Reactions,
            Capability::Groups, Capability::Typing, Capability::QrSession, Capability::ReadReceipts];
    }

    public function credentialFields(): array
    {
        return [
            'api_key' => ['Gateway API key', true, true],
            'signing_secret' => ['Webhook signing secret (optional, HMAC-SHA256)', true, false],
        ];
    }

    /** @return array{0:int,1:array} */
    private function call(array $account, array $credentials, string $method, array $args): array
    {
        $base = rtrim((string) ($account['base_url'] ?? ''), '/');
        if (!preg_match('#^https?://#i', $base)) {
            throw new \RuntimeException('Gateway base URL is not configured.');
        }
        $res = $this->http->request('POST', "$base/$method", [
            'Content-Type' => 'application/json',
            'api_key' => (string) ($credentials['api_key'] ?? ''),
        ], (string) json_encode(['args' => $args], JSON_UNESCAPED_UNICODE));
        return [$res['status'], json_decode($res['body'], true) ?: []];
    }

    private function chatId(string $e164): string
    {
        return PhoneNormalizer::toWaId($e164) . '@c.us';
    }

    private function toResult(int $status, array $json): SendResult
    {
        $resp = $json['response'] ?? null;
        if ($status >= 200 && $status < 300 && ($json['success'] ?? false) && is_string($resp) && $resp !== '' && !str_starts_with($resp, 'ERROR')) {
            return SendResult::accepted($resp);
        }
        $msg = is_string($resp) ? $resp : (string) ($json['error'] ?? 'HTTP ' . $status);
        return SendResult::failed((string) $status, $msg, $status === 429 || $status >= 500);
    }

    public function sendText(array $account, array $credentials, string $toE164, string $body, ?string $replyToProviderId = null): SendResult
    {
        try {
            [$status, $json] = $replyToProviderId !== null
                ? $this->call($account, $credentials, 'reply', ['to' => $this->chatId($toE164), 'content' => $body, 'quotedMsgId' => $replyToProviderId])
                : $this->call($account, $credentials, 'sendText', ['to' => $this->chatId($toE164), 'content' => $body]);
        } catch (Throwable $e) {
            return SendResult::failed('network', 'Gateway unreachable: ' . $e->getMessage(), true);
        }
        return $this->toResult($status, $json);
    }

    public function sendMedia(array $account, array $credentials, string $toE164, string $type, string $mediaUrl, ?string $caption = null): SendResult
    {
        $filename = basename((string) parse_url($mediaUrl, PHP_URL_PATH)) ?: 'file';
        try {
            [$status, $json] = $type === 'image'
                ? $this->call($account, $credentials, 'sendImage', ['to' => $this->chatId($toE164), 'file' => $mediaUrl, 'filename' => $filename, 'caption' => (string) $caption])
                : $this->call($account, $credentials, 'sendFile', ['to' => $this->chatId($toE164), 'file' => $mediaUrl, 'filename' => $filename, 'caption' => (string) $caption]);
        } catch (Throwable $e) {
            return SendResult::failed('network', 'Gateway unreachable: ' . $e->getMessage(), true);
        }
        return $this->toResult($status, $json);
    }

    public function checkHealth(array $account, array $credentials): array
    {
        try {
            [$status, $json] = $this->call($account, $credentials, 'getConnectionState', []);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Gateway unreachable: ' . $e->getMessage()];
        }
        $state = (string) ($json['response'] ?? '');
        if ($status === 200 && $state === 'CONNECTED') {
            return ['ok' => true, 'message' => 'Session connected (unofficial gateway).'];
        }
        return ['ok' => false, 'message' => $state !== '' ? "Session state: $state — scan the QR code in your gateway." : 'HTTP ' . $status];
    }

    public function verificationChallenge(array $credentials, Request $request): ?string
    {
        return null;
    }

    public function verifySignature(array $credentials, Request $request): bool
    {
        $secret = (string) ($credentials['signing_secret'] ?? '');
        if ($secret === '') {
            return true; // URL token already authenticated the request (see class doc).
        }
        $header = $request->header('x-signature-256');
        return str_starts_with($header, 'sha256=')
            && hash_equals('sha256=' . hash_hmac('sha256', $request->body, $secret), $header);
    }

    public function parseWebhook(array $account, array $payload): array
    {
        $event = (string) ($payload['event'] ?? '');
        $d = (array) ($payload['data'] ?? []);
        if ($event === 'onMessage') {
            $id = (string) ($d['id'] ?? '');
            if ($id === '' || !empty($d['fromMe']) || !empty($d['isGroupMsg'])) {
                return []; // group inbox is not part of this release
            }
            $type = (string) ($d['type'] ?? 'chat');
            $isMedia = in_array($type, ['image', 'document', 'audio', 'ptt', 'video', 'sticker'], true);
            return [new NormalizedEvent(
                kind: NormalizedEvent::MESSAGE,
                eventKey: 'msg:' . $id,
                providerMessageId: $id,
                timestamp: (int) ($d['t'] ?? time()),
                fromPhone: PhoneNormalizer::normalize((string) ($d['from'] ?? ''), ''),
                contactName: isset($d['sender']['pushname']) ? (string) $d['sender']['pushname'] : null,
                messageType: $type === 'chat' ? 'text' : ($type === 'ptt' ? 'audio' : $type),
                body: $isMedia ? (isset($d['caption']) ? (string) $d['caption'] : null) : (string) ($d['body'] ?? ''),
                mediaMime: $isMedia ? (string) ($d['mimetype'] ?? '') : null,
                replyToProviderId: isset($d['quotedMsg']['id']) ? (string) $d['quotedMsg']['id'] : null,
            )];
        }
        if ($event === 'onAck') {
            $id = (string) ($d['id'] ?? '');
            $status = match ((int) ($d['ack'] ?? 0)) {
                -1 => 'failed', 1 => 'sent', 2 => 'delivered', 3, 4 => 'read', default => null,
            };
            if ($id === '' || $status === null) {
                return [];
            }
            return [new NormalizedEvent(
                kind: NormalizedEvent::STATUS,
                eventKey: 'st:' . $id . ':' . $status,
                providerMessageId: $id,
                timestamp: (int) ($payload['ts'] ?? time()),
                status: $status,
            )];
        }
        return [];
    }
}
