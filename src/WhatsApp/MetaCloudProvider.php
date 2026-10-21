<?php

declare(strict_types=1);

namespace App\WhatsApp;

use App\Core\HttpClient;
use App\Core\Request;
use App\Domain\Contacts\PhoneNormalizer;
use Throwable;

/**
 * Official Meta WhatsApp Business Platform (Cloud API).
 * Docs: https://developers.facebook.com/docs/whatsapp/cloud-api
 * Credentials: access_token (system-user token), app_secret (for X-Hub-Signature-256), verify_token.
 * Account columns: phone_number_id, business_account_id.
 */
final class MetaCloudProvider implements Provider, TemplateProvider
{
    /** Graph error codes that are safe to retry (throttling / transient). */
    private const RETRYABLE_CODES = ['1', '2', '4', '17', '80007', '130429', '131016', '131048', '131056'];

    public function __construct(private HttpClient $http)
    {
    }

    public function key(): string { return 'meta_cloud'; }
    public function label(): string { return 'Meta WhatsApp Cloud API (official)'; }
    public function isOfficial(): bool { return true; }

    public function capabilities(): array
    {
        return [Capability::Text, Capability::Media, Capability::Templates, Capability::Interactive,
            Capability::Reactions, Capability::Replies, Capability::ReadReceipts];
    }

    public function credentialFields(): array
    {
        // name => [label, secret?, required?]
        return [
            'access_token' => ['Permanent access token (System User)', true, true],
            'app_secret' => ['App secret (verifies webhook signatures)', true, true],
            'verify_token' => ['Webhook verify token (any random string you also enter in Meta)', true, true],
            'app_id' => ['Meta App ID (needed only for image/video/document template headers)', false, false],
        ];
    }

    // ------------------------------------------------------------------ templates

    /** Graph call returning [status, decoded json]; never throws. */
    private function graph(string $method, string $url, array $credentials, ?string $body = null, array $headers = []): array
    {
        try {
            $res = $this->http->request($method, $url, $headers + [
                'Authorization' => 'Bearer ' . ($credentials['access_token'] ?? ''),
            ] + ($body !== null && !isset($headers['Content-Type']) ? ['Content-Type' => 'application/json'] : []), $body);
        } catch (Throwable $e) {
            return [0, ['error' => ['message' => 'Could not reach Meta: ' . $e->getMessage()]]];
        }
        return [$res['status'], json_decode($res['body'], true) ?: []];
    }

    private static function graphError(int $status, array $json): string
    {
        $e = $json['error'] ?? [];
        $msg = (string) ($e['error_user_msg'] ?? $e['error_data']['details'] ?? $e['message'] ?? 'HTTP ' . $status);
        $title = (string) ($e['error_user_title'] ?? '');
        return mb_substr(($title !== '' ? $title . ': ' : '') . $msg, 0, 500);
    }

    private function waba(array $account): ?string
    {
        $id = (string) ($account['business_account_id'] ?? '');
        return preg_match('/^\d+$/', $id) ? $id : null;
    }

    public function createTemplate(array $account, array $credentials, array $payload): array
    {
        $waba = $this->waba($account);
        if ($waba === null) {
            return ['ok' => false, 'error' => 'Add the WhatsApp Business Account ID to this account first.'];
        }
        [$status, $json] = $this->graph('POST', $this->endpoint($account, "$waba/message_templates"), $credentials,
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($status >= 200 && $status < 300 && isset($json['id'])) {
            return ['ok' => true, 'id' => (string) $json['id'], 'status' => strtolower((string) ($json['status'] ?? 'pending'))];
        }
        return ['ok' => false, 'error' => self::graphError($status, $json)];
    }

    public function listTemplates(array $account, array $credentials): array
    {
        $waba = $this->waba($account);
        if ($waba === null) {
            return ['ok' => false, 'error' => 'Add the WhatsApp Business Account ID to this account first.'];
        }
        $base = rtrim((string) env('META_GRAPH_URL', 'https://graph.facebook.com'), '/');
        $url = $this->endpoint($account, "$waba/message_templates?limit=100&fields=id,name,status,category,language,components,rejected_reason,quality_score");
        $all = [];
        for ($page = 0; $page < 30 && $url !== null; $page++) {
            [$status, $json] = $this->graph('GET', $url, $credentials);
            if ($status !== 200) {
                return ['ok' => false, 'error' => self::graphError($status, $json)];
            }
            foreach ((array) ($json['data'] ?? []) as $t) {
                $all[] = $t;
            }
            $next = (string) ($json['paging']['next'] ?? '');
            // Follow pagination only on the Graph host (never an arbitrary URL from a response).
            $url = $next !== '' && str_starts_with($next, $base . '/') ? $next : null;
        }
        return ['ok' => true, 'templates' => $all];
    }

    public function deleteTemplate(array $account, array $credentials, string $name, ?string $templateId): array
    {
        $waba = $this->waba($account);
        if ($waba === null) {
            return ['ok' => false, 'error' => 'WhatsApp Business Account ID missing.'];
        }
        $q = 'name=' . rawurlencode($name) . ($templateId !== null && preg_match('/^\d+$/', $templateId) ? '&hsm_id=' . $templateId : '');
        [$status, $json] = $this->graph('DELETE', $this->endpoint($account, "$waba/message_templates?$q"), $credentials);
        return ($status === 200 && !empty($json['success'])) ? ['ok' => true] : ['ok' => false, 'error' => self::graphError($status, $json)];
    }

    public function uploadTemplateSample(array $account, array $credentials, string $path, string $mime, string $filename): array
    {
        $appId = (string) ($credentials['app_id'] ?? '');
        if (!preg_match('/^\d+$/', $appId)) {
            return ['ok' => false, 'error' => 'Add your Meta App ID to this WhatsApp account to use image, video or document headers.'];
        }
        $size = (int) @filesize($path);
        $url = $this->endpoint($account, "$appId/uploads?file_name=" . rawurlencode($filename) . "&file_length=$size&file_type=" . rawurlencode($mime));
        [$status, $json] = $this->graph('POST', $url, $credentials, '');
        $session = (string) ($json['id'] ?? '');
        if ($status !== 200 || !str_starts_with($session, 'upload:')) {
            return ['ok' => false, 'error' => self::graphError($status, $json)];
        }
        [$status, $json] = $this->graph('POST', $this->endpoint($account, $session), $credentials, (string) file_get_contents($path), [
            'Authorization' => 'OAuth ' . ($credentials['access_token'] ?? ''), 'file_offset' => '0', 'Content-Type' => 'application/octet-stream',
        ]);
        return ($status === 200 && isset($json['h'])) ? ['ok' => true, 'handle' => (string) $json['h']] : ['ok' => false, 'error' => self::graphError($status, $json)];
    }

    public function uploadMedia(array $account, array $credentials, string $path, string $mime, string $filename): array
    {
        $pnid = (string) ($account['phone_number_id'] ?? '');
        if (!preg_match('/^\d+$/', $pnid)) {
            return ['ok' => false, 'error' => 'Phone number ID is not configured.'];
        }
        $boundary = '----wcrm' . bin2hex(random_bytes(8));
        $safeName = preg_replace('/[^A-Za-z0-9._\-]/', '_', $filename) ?: 'file';
        $body = "--$boundary\r\nContent-Disposition: form-data; name=\"messaging_product\"\r\n\r\nwhatsapp\r\n"
            . "--$boundary\r\nContent-Disposition: form-data; name=\"type\"\r\n\r\n$mime\r\n"
            . "--$boundary\r\nContent-Disposition: form-data; name=\"file\"; filename=\"$safeName\"\r\nContent-Type: $mime\r\n\r\n"
            . file_get_contents($path) . "\r\n--$boundary--\r\n";
        [$status, $json] = $this->graph('POST', $this->endpoint($account, "$pnid/media"), $credentials, $body,
            ['Content-Type' => "multipart/form-data; boundary=$boundary"]);
        return ($status === 200 && isset($json['id'])) ? ['ok' => true, 'id' => (string) $json['id']] : ['ok' => false, 'error' => self::graphError($status, $json)];
    }

    public function sendTemplate(array $account, array $credentials, string $toE164, string $name, string $language, array $components): SendResult
    {
        $template = ['name' => $name, 'language' => ['code' => $language]];
        if ($components !== []) {
            $template['components'] = $components;
        }
        return $this->post($account, $credentials, [
            'messaging_product' => 'whatsapp', 'recipient_type' => 'individual',
            'to' => PhoneNormalizer::toWaId($toE164), 'type' => 'template', 'template' => $template,
        ]);
    }

    private function endpoint(array $account, string $path): string
    {
        $base = rtrim((string) env('META_GRAPH_URL', 'https://graph.facebook.com'), '/');
        $version = MetaPlatform::graphVersion();
        return "$base/$version/" . ltrim($path, '/');
    }

    private function post(array $account, array $credentials, array $payload): SendResult
    {
        $phoneNumberId = (string) ($account['phone_number_id'] ?? '');
        if (!preg_match('/^\d+$/', $phoneNumberId)) {
            return SendResult::failed('config', 'Phone number ID is not configured.', false);
        }
        try {
            $res = $this->http->request('POST', $this->endpoint($account, "$phoneNumberId/messages"), [
                'Authorization' => 'Bearer ' . ($credentials['access_token'] ?? ''),
                'Content-Type' => 'application/json',
            ], (string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        } catch (Throwable $e) {
            return SendResult::failed('network', 'Could not reach Meta: ' . $e->getMessage(), true);
        }
        $json = json_decode($res['body'], true);
        if ($res['status'] >= 200 && $res['status'] < 300 && isset($json['messages'][0]['id'])) {
            return SendResult::accepted((string) $json['messages'][0]['id']);
        }
        $code = (string) ($json['error']['code'] ?? $res['status']);
        $message = (string) ($json['error']['error_data']['details'] ?? $json['error']['message'] ?? ('HTTP ' . $res['status']));
        $retryable = $res['status'] === 429 || $res['status'] >= 500 || in_array($code, self::RETRYABLE_CODES, true);
        return SendResult::failed($code, $message, $retryable);
    }

    public function sendText(array $account, array $credentials, string $toE164, string $body, ?string $replyToProviderId = null): SendResult
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => PhoneNormalizer::toWaId($toE164),
            'type' => 'text',
            'text' => ['body' => $body, 'preview_url' => false],
        ];
        if ($replyToProviderId !== null) {
            $payload['context'] = ['message_id' => $replyToProviderId];
        }
        return $this->post($account, $credentials, $payload);
    }

    public function sendMedia(array $account, array $credentials, string $toE164, string $type, string $mediaUrl, ?string $caption = null): SendResult
    {
        if (!in_array($type, ['image', 'document', 'audio', 'video'], true)) {
            return SendResult::failed('unsupported', "Unsupported media type $type", false);
        }
        $media = ['link' => $mediaUrl];
        if ($caption !== null && $caption !== '' && $type !== 'audio') {
            $media['caption'] = $caption;
        }
        return $this->post($account, $credentials, [
            'messaging_product' => 'whatsapp', 'recipient_type' => 'individual',
            'to' => PhoneNormalizer::toWaId($toE164), 'type' => $type, $type => $media,
        ]);
    }

    public function checkHealth(array $account, array $credentials): array
    {
        $id = (string) ($account['phone_number_id'] ?? '');
        if (!preg_match('/^\d+$/', $id) || empty($credentials['access_token'])) {
            return ['ok' => false, 'message' => 'Phone number ID and access token are required.'];
        }
        try {
            $res = $this->http->request(
                'GET',
                $this->endpoint($account, $id . '?fields=display_phone_number,verified_name,quality_rating'),
                ['Authorization' => 'Bearer ' . $credentials['access_token']]
            );
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Could not reach Meta: ' . $e->getMessage()];
        }
        $json = json_decode($res['body'], true) ?: [];
        if ($res['status'] === 200 && isset($json['display_phone_number'])) {
            return ['ok' => true, 'message' => 'Connected: ' . ($json['verified_name'] ?? '') . ' ' . $json['display_phone_number'], 'details' => $json];
        }
        return ['ok' => false, 'message' => (string) ($json['error']['message'] ?? 'HTTP ' . $res['status']),
            'code' => (string) ($json['error']['code'] ?? $res['status'])];
    }

    public function verificationChallenge(array $credentials, Request $request): ?string
    {
        $mode = (string) ($request->query['hub_mode'] ?? $request->query['hub.mode'] ?? '');
        $token = (string) ($request->query['hub_verify_token'] ?? $request->query['hub.verify_token'] ?? '');
        $challenge = (string) ($request->query['hub_challenge'] ?? $request->query['hub.challenge'] ?? '');
        $expected = (string) ($credentials['verify_token'] ?? '');
        if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, $token) && preg_match('/^[A-Za-z0-9_\-]{1,200}$/', $challenge)) {
            return $challenge;
        }
        return null;
    }

    public function verifySignature(array $credentials, Request $request): bool
    {
        $secret = (string) ($credentials['app_secret'] ?? '');
        $header = $request->header('x-hub-signature-256');
        if ($secret === '' || !str_starts_with($header, 'sha256=')) {
            return false;
        }
        return hash_equals('sha256=' . hash_hmac('sha256', $request->body, $secret), $header);
    }

    public function parseWebhook(array $account, array $payload): array
    {
        if (($payload['object'] ?? '') !== 'whatsapp_business_account') {
            return [];
        }
        $events = [];
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = (array) ($change['value'] ?? []);
                // Template approval / rejection (WABA-level event; entry.id is the WABA id).
                if (($change['field'] ?? '') === 'message_template_status_update') {
                    $tplId = (string) ($value['message_template_id'] ?? '');
                    $event = strtolower((string) ($value['event'] ?? ''));
                    if ($tplId !== '' && $event !== '' && (string) ($entry['id'] ?? '') === (string) $account['business_account_id']) {
                        $events[] = new NormalizedEvent(
                            kind: NormalizedEvent::TEMPLATE_STATUS,
                            eventKey: 'tpl:' . $account['id'] . ':' . $tplId . ':' . $event . ':' . (int) ($entry['time'] ?? 0),
                            providerMessageId: $tplId,
                            timestamp: (int) ($entry['time'] ?? time()),
                            status: $event,
                            errorMessage: isset($value['reason']) && $value['reason'] !== 'NONE' ? (string) $value['reason'] : null,
                            templateName: (string) ($value['message_template_name'] ?? ''),
                            templateLanguage: (string) ($value['message_template_language'] ?? ''),
                        );
                    }
                    continue;
                }
                // Ignore events addressed to a different phone number than this account.
                $pnid = (string) ($value['metadata']['phone_number_id'] ?? '');
                if ($pnid === '' || $pnid !== (string) $account['phone_number_id']) {
                    continue;
                }
                $names = [];
                foreach ((array) ($value['contacts'] ?? []) as $c) {
                    $names[(string) ($c['wa_id'] ?? '')] = (string) ($c['profile']['name'] ?? '');
                }
                foreach ((array) ($value['messages'] ?? []) as $m) {
                    $id = (string) ($m['id'] ?? '');
                    $from = (string) ($m['from'] ?? '');
                    if ($id === '' || $from === '') {
                        continue;
                    }
                    $type = (string) ($m['type'] ?? 'unknown');
                    [$body, $mediaRef, $mime] = [null, null, null];
                    if ($type === 'text') {
                        $body = (string) ($m['text']['body'] ?? '');
                    } elseif (in_array($type, ['image', 'document', 'audio', 'video', 'sticker'], true)) {
                        $mediaRef = (string) ($m[$type]['id'] ?? '');
                        $mime = (string) ($m[$type]['mime_type'] ?? '');
                        $body = isset($m[$type]['caption']) ? (string) $m[$type]['caption'] : null;
                    } elseif ($type === 'button') {
                        $body = (string) ($m['button']['text'] ?? '');
                    } elseif ($type === 'interactive') {
                        $body = (string) ($m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? '');
                    } elseif ($type === 'reaction') {
                        $body = (string) ($m['reaction']['emoji'] ?? '');
                    } elseif ($type === 'location') {
                        $body = sprintf('Location: %s, %s', $m['location']['latitude'] ?? '?', $m['location']['longitude'] ?? '?');
                    } else {
                        $body = '[Unsupported message type: ' . $type . ']';
                    }
                    $events[] = new NormalizedEvent(
                        kind: NormalizedEvent::MESSAGE,
                        eventKey: 'msg:' . $id,
                        providerMessageId: $id,
                        timestamp: (int) ($m['timestamp'] ?? time()),
                        fromPhone: PhoneNormalizer::normalize('+' . $from),
                        contactName: $names[$from] ?? null,
                        messageType: $type,
                        body: $body,
                        mediaRef: $mediaRef,
                        mediaMime: $mime,
                        replyToProviderId: isset($m['context']['id']) ? (string) $m['context']['id'] : null,
                    );
                }
                foreach ((array) ($value['statuses'] ?? []) as $s) {
                    $id = (string) ($s['id'] ?? '');
                    $status = (string) ($s['status'] ?? '');
                    if ($id === '' || !in_array($status, ['sent', 'delivered', 'read', 'failed'], true)) {
                        continue;
                    }
                    $err = $s['errors'][0] ?? null;
                    $events[] = new NormalizedEvent(
                        kind: NormalizedEvent::STATUS,
                        eventKey: 'st:' . $id . ':' . $status,
                        providerMessageId: $id,
                        timestamp: (int) ($s['timestamp'] ?? time()),
                        status: $status,
                        errorCode: $err !== null ? (string) ($err['code'] ?? '') : null,
                        errorMessage: $err !== null ? (string) ($err['error_data']['details'] ?? $err['title'] ?? $err['message'] ?? '') : null,
                    );
                }
            }
        }
        return $events;
    }
}
