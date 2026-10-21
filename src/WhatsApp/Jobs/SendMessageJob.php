<?php

declare(strict_types=1);

namespace App\WhatsApp\Jobs;

use App\Core\App;
use App\Core\Job;
use App\Core\Logger;
use App\Core\ReleaseJob;
use App\Domain\Campaigns\CampaignService;
use App\WhatsApp\TemplateProvider;
use App\WhatsApp\AccountRepository;
use App\WhatsApp\TokenMonitor;

/**
 * Dispatches one queued outbound message to its provider.
 * Idempotent: only messages still in "queued" are sent. Status becomes "submitted"
 * only after the provider accepts the request; delivery/read come from webhooks.
 */
final class SendMessageJob implements Job
{
    public function maxAttempts(): int
    {
        return 5;
    }

    public function backoff(int $attempt): int
    {
        return min(900, 10 * (2 ** ($attempt - 1))); // 10s, 20s, 40s, 80s …
    }

    public function handle(App $app, array $data): void
    {
        $tenantId = (int) ($data['tenant_id'] ?? 0);
        $messageId = (int) ($data['message_id'] ?? 0);
        $attempt = (int) ($data['_attempt'] ?? 1);
        $db = $app->db;
        $msg = $db->first('SELECT * FROM messages WHERE id = ? AND tenant_id = ?', [$messageId, $tenantId]);
        if ($msg === null || $msg['status'] !== 'queued' || $msg['direction'] !== 'out') {
            return;
        }
        $account = $db->first('SELECT * FROM whatsapp_accounts WHERE id = ? AND tenant_id = ?', [$msg['whatsapp_account_id'], $tenantId]);
        $contact = $db->first(
            'SELECT c.* FROM contacts c JOIN conversations cv ON cv.contact_id = c.id AND cv.tenant_id = c.tenant_id
             WHERE cv.id = ? AND c.tenant_id = ?',
            [$msg['conversation_id'], $tenantId]
        );
        $tenantActive = $db->scalar('SELECT status FROM tenants WHERE id = ?', [$tenantId]) === 'active';
        if ($account === null || $contact === null || !$tenantActive) {
            $this->markFailed($app, $msg, 'config', 'Account, contact or company no longer available.');
            return;
        }
        if ($contact['opted_out_at'] !== null) {
            $this->markFailed($app, $msg, 'opted_out', 'Contact opted out before the message was sent.');
            return;
        }
        if ($msg['campaign_id'] !== null) {
            $cstatus = $db->scalar('SELECT status FROM campaigns WHERE id = ? AND tenant_id = ?', [$msg['campaign_id'], $tenantId]);
            if ($cstatus === 'cancelled' || $cstatus === null) {
                $this->markFailed($app, $msg, 'cancelled', 'Campaign was cancelled before this message was sent.');
                return;
            }
            if ($cstatus === 'paused') {
                throw new ReleaseJob(60, 'Campaign paused');
            }
        }
        if ($account['status'] === 'needs_reconnect') {
            $this->markFailed($app, $msg, 'reconnect', 'The WhatsApp account must be reconnected with Facebook before messages can be sent.');
            return;
        }
        // Per-account throttle (messages/second) so we never exceed provider limits.
        $perSecond = max(1, (int) env('WHATSAPP_SEND_RATE_PER_SECOND', 20));
        if (!$app->limiter->hit('wa-send:' . $account['id'] . ':' . time(), $perSecond, 2)) {
            throw new ReleaseJob(1, 'Per-account send rate reached');
        }
        $provider = $app->providers->get((string) $account['provider']);
        $replyTo = null;
        if ($msg['reply_to_message_id'] !== null) {
            $replyTo = $db->scalar('SELECT provider_message_id FROM messages WHERE id = ? AND tenant_id = ?', [$msg['reply_to_message_id'], $tenantId]);
        }
        $credentials = AccountRepository::credentials($account);
        if ($msg['type'] === 'template') {
            $template = $db->first('SELECT * FROM message_templates WHERE id = ? AND tenant_id = ?', [$msg['template_id'], $tenantId]);
            if ($template === null || !$provider instanceof TemplateProvider) {
                $this->markFailed($app, $msg, 'template', 'Template not found or not supported by this provider.');
                return;
            }
            if ($template['status'] !== 'approved') {
                $this->markFailed($app, $msg, 'template', 'Template is ' . $template['status'] . ' and can no longer be sent.');
                return;
            }
            $result = $provider->sendTemplate($account, $credentials, (string) $contact['phone_e164'], (string) $template['name'],
                (string) $template['language'], json_decode((string) $msg['template_params'], true) ?: []);
        } else {
            $result = $msg['type'] === 'text'
                ? $provider->sendText($account, $credentials, (string) $contact['phone_e164'], (string) $msg['body'], $replyTo !== null ? (string) $replyTo : null)
                : $provider->sendMedia($account, $credentials, (string) $contact['phone_e164'], (string) $msg['type'], (string) $msg['media_url'], $msg['body']);
        }

        if ($result->success) {
            $db->transaction(function () use ($db, $msg, $tenantId, $result): void {
                $db->update('messages', [
                    'status' => 'submitted', 'provider_message_id' => $result->providerMessageId,
                    'error_code' => null, 'error_message' => null, 'updated_at' => now(),
                ], ['id' => (int) $msg['id'], 'tenant_id' => $tenantId]);
                $db->insert('message_status_events', [
                    'tenant_id' => $tenantId, 'message_id' => (int) $msg['id'], 'status' => 'submitted',
                    'occurred_at' => now(), 'created_at' => now(),
                ]);
                if ($msg['campaign_id'] !== null) {
                    CampaignService::mirror($db, $tenantId, (int) $msg['id']);
                }
            });
            return;
        }
        Logger::warning('WhatsApp send failed', [
            'message_id' => $msg['id'], 'account_id' => $account['id'], 'error_code' => $result->errorCode,
            'retryable' => $result->retryable, 'attempt' => $attempt,
        ]);
        $db->update('whatsapp_accounts', ['last_error' => mb_substr((string) $result->errorMessage, 0, 500), 'last_error_at' => now()], ['id' => (int) $account['id']]);
        if ($account['provider'] === 'meta_cloud' && TokenMonitor::isAuthError($result->errorCode)) {
            (new TokenMonitor($db, $app->http, $app->mailer))->markNeedsReconnect((int) $account['id'], 'Meta rejected the access token: ' . $result->errorMessage);
        }
        if ($result->retryable && $attempt < $this->maxAttempts()) {
            throw new \RuntimeException('Retryable provider error ' . $result->errorCode);
        }
        $this->markFailed($app, $msg, (string) $result->errorCode, (string) $result->errorMessage);
    }

    private function markFailed(App $app, array $msg, string $code, string $message): void
    {
        $app->db->transaction(function () use ($app, $msg, $code, $message): void {
            $app->db->update('messages', [
                'status' => 'failed', 'error_code' => mb_substr($code, 0, 40), 'error_message' => mb_substr($message, 0, 500), 'updated_at' => now(),
            ], ['id' => (int) $msg['id'], 'tenant_id' => (int) $msg['tenant_id']]);
            $app->db->insert('message_status_events', [
                'tenant_id' => (int) $msg['tenant_id'], 'message_id' => (int) $msg['id'], 'status' => 'failed',
                'error_code' => mb_substr($code, 0, 40), 'error_message' => mb_substr($message, 0, 500),
                'occurred_at' => now(), 'created_at' => now(),
            ]);
            if ($msg['campaign_id'] !== null) {
                CampaignService::mirror($app->db, (int) $msg['tenant_id'], (int) $msg['id']);
            }
        });
    }
}
