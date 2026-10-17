<?php

declare(strict_types=1);

namespace App\WhatsApp\Jobs;

use App\Core\App;
use App\Core\Job;
use App\Core\Logger;
use App\Domain\Automation\AutomationService;
use App\Domain\Conversations\InboxService;
use App\Domain\Templates\TemplateService;
use App\Tenancy\TenantContext;
use App\WhatsApp\NormalizedEvent;

/**
 * Parses a verified webhook payload and applies each event exactly once.
 * Tenant comes from the account row resolved by the webhook URL token — never from the payload.
 */
final class ProcessWebhookJob implements Job
{
    public function maxAttempts(): int
    {
        return 5;
    }

    public function backoff(int $attempt): int
    {
        return min(600, 5 * (2 ** ($attempt - 1)));
    }

    public function handle(App $app, array $data): void
    {
        $account = $app->db->first('SELECT * FROM whatsapp_accounts WHERE id = ?', [(int) ($data['account_id'] ?? 0)]);
        if ($account === null) {
            return;
        }
        $payload = json_decode((string) ($data['payload'] ?? ''), true);
        if (!is_array($payload)) {
            return;
        }
        $tenant = new TenantContext();
        $tenant->set((int) $account['tenant_id']);
        $inbox = new InboxService($app->db, $tenant, $app->queue);
        $provider = $app->providers->get((string) $account['provider']);

        $events = $provider->parseWebhook($account, $payload);
        if ($events === [] && $account['provider'] === 'meta_cloud') {
            // Explain silent drops on the account page: usually a wrong Phone number ID.
            $other = [];
            foreach ((array) ($payload['entry'] ?? []) as $entry) {
                foreach ((array) ($entry['changes'] ?? []) as $ch) {
                    $pn = (string) ($ch['value']['metadata']['phone_number_id'] ?? '');
                    if ($pn !== '' && $pn !== (string) $account['phone_number_id'] && !empty($ch['value']['messages'])) {
                        $other[] = $pn;
                    }
                }
            }
            if ($other !== []) {
                $app->db->update('whatsapp_accounts', ['last_webhook_result' => mb_substr('ignored: message was for phone number ID '
                    . implode(', ', array_unique($other)) . ', but this account has ' . $account['phone_number_id'], 0, 255)], ['id' => $account['id']]);
            }
        }
        foreach ($events as $event) {
            // Dedupe: claim the event key first. A replayed/duplicate delivery is skipped.
            try {
                $app->db->insert('webhook_events', [
                    'whatsapp_account_id' => (int) $account['id'], 'provider' => (string) $account['provider'],
                    'event_key' => mb_substr($event->eventKey, 0, 191), 'received_at' => now(),
                ]);
            } catch (\PDOException $e) {
                if ($app->db->isUniqueViolation($e)) {
                    continue;
                }
                throw $e;
            }
            try {
                if ($event->kind === NormalizedEvent::MESSAGE) {
                    $messageId = $inbox->recordInbound($account, $event);
                    $app->db->update('whatsapp_accounts', ['last_message_in_at' => now()], ['id' => $account['id']]);
                    if ($messageId !== null) {
                        // Auto-replies run after the message is safely stored; failures never block ingestion.
                        try {
                            (new AutomationService($app->db, $tenant, $app->queue))->handleInbound($account, $messageId);
                        } catch (\Throwable $ae) {
                            Logger::error('Automation failed', ['message_id' => $messageId, 'error' => $ae->getMessage()]);
                        }
                    }
                } elseif ($event->kind === NormalizedEvent::STATUS) {
                    $inbox->applyStatus($account, $event);
                } elseif ($event->kind === NormalizedEvent::TEMPLATE_STATUS) {
                    (new TemplateService($app->db, $tenant))->applyStatusEvent($account, $event);
                }
            } catch (\Throwable $e) {
                // Release the claim so the retry can process this event.
                $app->db->run('DELETE FROM webhook_events WHERE provider = ? AND event_key = ?', [$account['provider'], mb_substr($event->eventKey, 0, 191)]);
                throw $e;
            }
        }
        $app->db->update('whatsapp_accounts', ['last_synced_at' => now(), 'status' => 'connected'], ['id' => (int) $account['id']]);
        Logger::info('Webhook processed', ['account_id' => $account['id']]);
    }
}
