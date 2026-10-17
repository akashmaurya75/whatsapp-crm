<?php

declare(strict_types=1);

namespace App\Domain\Conversations;

use App\Core\Database;
use App\Core\Queue;
use App\Domain\Campaigns\CampaignService;
use App\Domain\Contacts\ContactService;
use App\Domain\Templates\TemplateService;
use App\Domain\ValidationException;
use App\Tenancy\TenantContext;
use App\WhatsApp\AccountRepository;
use App\WhatsApp\Capability;
use App\WhatsApp\Jobs\SendMessageJob;
use App\WhatsApp\NormalizedEvent;

final class InboxService
{
    /** Ordering used to ignore out-of-order status webhooks (never downgrade read → delivered). */
    public const STATUS_RANK = ['queued' => 0, 'submitted' => 1, 'sent' => 2, 'delivered' => 3, 'read' => 4];

    public readonly ConversationRepository $conversations;
    public readonly AccountRepository $accounts;
    public readonly ContactService $contacts;

    public function __construct(private Database $db, private TenantContext $tenant, private ?Queue $queue = null)
    {
        $this->conversations = new ConversationRepository($db, $tenant);
        $this->accounts = new AccountRepository($db, $tenant);
        $this->contacts = new ContactService($db, $tenant);
    }

    public function findOrCreateConversation(int $contactId, int $accountId): array
    {
        $tid = $this->tenant->id();
        $row = $this->db->first(
            'SELECT * FROM conversations WHERE tenant_id = ? AND contact_id = ? AND whatsapp_account_id = ?',
            [$tid, $contactId, $accountId]
        );
        if ($row !== null) {
            return $row;
        }
        try {
            $id = $this->conversations->create([
                'contact_id' => $contactId, 'whatsapp_account_id' => $accountId, 'status' => 'open',
                'priority' => 'normal', 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\PDOException $e) {
            if ($this->db->isUniqueViolation($e)) {
                return (array) $this->db->first(
                    'SELECT * FROM conversations WHERE tenant_id = ? AND contact_id = ? AND whatsapp_account_id = ?',
                    [$tid, $contactId, $accountId]
                );
            }
            throw $e;
        }
        return (array) $this->conversations->find($id);
    }

    /**
     * Queue an outbound text message. It is stored as "queued" and only becomes
     * "submitted" once the provider accepts it (see SendMessageJob).
     */
    public function queueText(array $conversation, ?int $userId, string $body, ?string $idempotencyKey = null, ?int $replyToMessageId = null): array
    {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 4096) {
            throw new ValidationException(['body' => 'Message must be between 1 and 4096 characters.']);
        }
        $account = $this->accounts->findOrFail((int) $conversation['whatsapp_account_id']);
        $contact = $this->contacts->repo->findOrFail((int) $conversation['contact_id']);
        if ($contact['opted_out_at'] !== null) {
            throw new ValidationException(['body' => 'This contact has opted out of WhatsApp messages.']);
        }
        if (!AccountRepository::supports($account, Capability::Text)) {
            throw new ValidationException(['body' => 'This WhatsApp account cannot send text messages.']);
        }
        if ($account['provider'] === 'meta_cloud' && !$this->withinServiceWindow($conversation)) {
            throw new ValidationException(['body' => 'The 24-hour customer service window is closed. Meta requires an approved template message to re-open the conversation.']);
        }
        if ($replyToMessageId !== null) {
            $exists = $this->db->scalar('SELECT 1 FROM messages WHERE id = ? AND conversation_id = ? AND tenant_id = ?', [$replyToMessageId, $conversation['id'], $this->tenant->id()]);
            if ($exists === null) {
                $replyToMessageId = null;
            }
        }
        if ($idempotencyKey !== null) {
            $existing = $this->db->first('SELECT * FROM messages WHERE tenant_id = ? AND idempotency_key = ?', [$this->tenant->id(), $idempotencyKey]);
            if ($existing !== null) {
                return $existing;
            }
        }
        $tid = $this->tenant->id();
        $message = $this->db->transaction(function () use ($conversation, $userId, $body, $idempotencyKey, $replyToMessageId, $tid): array {
            $id = $this->db->insert('messages', [
                'tenant_id' => $tid, 'conversation_id' => (int) $conversation['id'],
                'whatsapp_account_id' => (int) $conversation['whatsapp_account_id'], 'direction' => 'out',
                'type' => 'text', 'body' => $body, 'reply_to_message_id' => $replyToMessageId,
                'status' => 'queued', 'sent_by_user_id' => $userId, 'idempotency_key' => $idempotencyKey,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->db->update('conversations', [
                'last_message_at' => now(), 'last_message_preview' => mb_substr($body, 0, 160),
                'unread_count' => 0, 'updated_at' => now(),
            ], ['id' => (int) $conversation['id'], 'tenant_id' => $tid]);
            $this->db->update('contacts', ['last_contacted_at' => now()], ['id' => (int) $conversation['contact_id'], 'tenant_id' => $tid]);
            return (array) $this->db->first('SELECT * FROM messages WHERE id = ?', [$id]);
        });
        $this->queue?->push(SendMessageJob::class, ['tenant_id' => $tid, 'message_id' => (int) $message['id']], 'messages');
        return $message;
    }

    /**
     * Queue an approved template message (allowed outside the 24-hour window).
     * @param array<string,string> $values slot => value (see TemplateService::slots)
     * @param array{link?:string,id?:string,filename?:string}|null $media header media
     */
    public function queueTemplate(
        array $conversation,
        ?int $userId,
        array $template,
        array $values,
        ?array $media = null,
        ?string $idempotencyKey = null,
        ?int $campaignId = null,
        bool $pushJob = true,
    ): array {
        $tid = $this->tenant->id();
        if ((int) $template['tenant_id'] !== $tid || (int) $template['whatsapp_account_id'] !== (int) $conversation['whatsapp_account_id']) {
            throw new ValidationException(['template' => 'This template belongs to a different WhatsApp number.']);
        }
        if (($why = TemplateService::unsendableReason($template)) !== null) {
            throw new ValidationException(['template' => $why]);
        }
        $contact = $this->contacts->repo->findOrFail((int) $conversation['contact_id']);
        if ($contact['opted_out_at'] !== null) {
            throw new ValidationException(['template' => 'This contact has opted out of WhatsApp messages.']);
        }
        // Builds (and validates) the provider parameters now so errors surface immediately.
        $components = TemplateService::sendComponents($template, $values, $media);
        if ($idempotencyKey !== null) {
            $existing = $this->db->first('SELECT * FROM messages WHERE tenant_id = ? AND idempotency_key = ?', [$tid, $idempotencyKey]);
            if ($existing !== null) {
                return $existing;
            }
        }
        $body = TemplateService::render($template, $values);
        $message = $this->db->transaction(function () use ($conversation, $userId, $template, $components, $body, $idempotencyKey, $campaignId, $tid): array {
            $id = $this->db->insert('messages', [
                'tenant_id' => $tid, 'conversation_id' => (int) $conversation['id'],
                'whatsapp_account_id' => (int) $conversation['whatsapp_account_id'], 'direction' => 'out',
                'type' => 'template', 'body' => mb_substr($body, 0, 4096), 'status' => 'queued', 'sent_by_user_id' => $userId,
                'idempotency_key' => $idempotencyKey, 'template_id' => (int) $template['id'], 'campaign_id' => $campaignId,
                'template_params' => json_encode($components, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->db->update('conversations', [
                'last_message_at' => now(), 'last_message_preview' => mb_substr(str_replace("\n", ' ', $body), 0, 160), 'updated_at' => now(),
            ], ['id' => (int) $conversation['id'], 'tenant_id' => $tid]);
            $this->db->update('contacts', ['last_contacted_at' => now()], ['id' => (int) $conversation['contact_id'], 'tenant_id' => $tid]);
            return (array) $this->db->first('SELECT * FROM messages WHERE id = ?', [$id]);
        });
        if ($pushJob) {
            $this->queue?->push(SendMessageJob::class, ['tenant_id' => $tid, 'message_id' => (int) $message['id']], 'messages');
        }
        return $message;
    }

    public function withinServiceWindow(array $conversation): bool
    {
        $last = $conversation['last_inbound_at'] ?? null;
        return $last !== null && strtotime((string) $last . ' UTC') > time() - 24 * 3600;
    }

    /** Store an inbound message (idempotent on provider message id). Returns message id or null if duplicate. */
    public function recordInbound(array $account, NormalizedEvent $event): ?int
    {
        if ($event->fromPhone === null) {
            return null;
        }
        $tid = $this->tenant->id();
        $dup = $this->db->scalar('SELECT id FROM messages WHERE whatsapp_account_id = ? AND provider_message_id = ?', [$account['id'], $event->providerMessageId]);
        if ($dup !== null) {
            return null;
        }
        $contact = $this->contacts->findOrCreateByPhone($event->fromPhone, $event->contactName);
        $conversation = $this->findOrCreateConversation((int) $contact['id'], (int) $account['id']);
        $replyTo = null;
        if ($event->replyToProviderId !== null) {
            $replyTo = $this->db->scalar('SELECT id FROM messages WHERE whatsapp_account_id = ? AND provider_message_id = ? AND tenant_id = ?', [$account['id'], $event->replyToProviderId, $tid]);
        }
        $ts = gmdate('Y-m-d H:i:s', $event->timestamp > 0 ? $event->timestamp : time());
        $preview = $event->body !== null && $event->body !== '' ? $event->body : '[' . $event->messageType . ']';
        try {
            return $this->db->transaction(function () use ($tid, $conversation, $account, $event, $replyTo, $ts, $preview, $contact): int {
                $id = $this->db->insert('messages', [
                    'tenant_id' => $tid, 'conversation_id' => (int) $conversation['id'], 'whatsapp_account_id' => (int) $account['id'],
                    'direction' => 'in', 'type' => mb_substr($event->messageType, 0, 20), 'body' => $event->body,
                    'media_url' => $event->mediaRef, 'media_mime' => $event->mediaMime,
                    'reply_to_message_id' => $replyTo !== null ? (int) $replyTo : null,
                    'provider_message_id' => $event->providerMessageId, 'status' => 'received',
                    'created_at' => $ts, 'updated_at' => now(),
                ]);
                $this->db->run(
                    'UPDATE conversations SET unread_count = unread_count + 1, last_message_at = ?, last_inbound_at = ?,
                     last_message_preview = ?, status = ?, is_archived = 0, closed_at = NULL, updated_at = ? WHERE id = ? AND tenant_id = ?',
                    [$ts, $ts, mb_substr($preview, 0, 160), 'open', now(), $conversation['id'], $tid]
                );
                $this->detectOptOut((int) $contact['id'], (string) $event->body);
                CampaignService::markReplied($this->db, $tid, (int) $contact['id']);
                return $id;
            });
        } catch (\PDOException $e) {
            if ($this->db->isUniqueViolation($e)) {
                return null; // concurrent duplicate delivery
            }
            throw $e;
        }
    }

    /** Honour STOP-style keywords (configurable via OPT_OUT_KEYWORDS). */
    private function detectOptOut(int $contactId, string $body): void
    {
        $keywords = array_map('trim', explode(',', strtolower((string) env('OPT_OUT_KEYWORDS', 'stop,unsubscribe,stop all'))));
        if (in_array(strtolower(trim($body)), $keywords, true)) {
            $this->db->update('contacts', [
                'opted_out_at' => now(), 'consent_status' => 'revoked', 'consent_at' => now(), 'updated_at' => now(),
            ], ['id' => $contactId, 'tenant_id' => $this->tenant->id()]);
        }
    }

    /** Apply a provider status update to an outbound message. Returns true if the message was found. */
    public function applyStatus(array $account, NormalizedEvent $event): bool
    {
        $tid = $this->tenant->id();
        $msg = $this->db->first(
            'SELECT * FROM messages WHERE whatsapp_account_id = ? AND provider_message_id = ? AND tenant_id = ?',
            [$account['id'], $event->providerMessageId, $tid]
        );
        if ($msg === null) {
            return false;
        }
        $this->db->transaction(function () use ($msg, $event, $tid): void {
            $this->db->insert('message_status_events', [
                'tenant_id' => $tid, 'message_id' => (int) $msg['id'], 'status' => (string) $event->status,
                'error_code' => $event->errorCode, 'error_message' => $event->errorMessage !== null ? mb_substr($event->errorMessage, 0, 500) : null,
                'occurred_at' => gmdate('Y-m-d H:i:s', $event->timestamp), 'created_at' => now(),
            ]);
            $current = (string) $msg['status'];
            $new = (string) $event->status;
            $update = null;
            if ($new === 'failed') {
                if (($current === 'queued' || $current === 'submitted' || $current === 'sent')) {
                    $update = ['status' => 'failed', 'error_code' => $event->errorCode, 'error_message' => $event->errorMessage !== null ? mb_substr($event->errorMessage, 0, 500) : null];
                }
            } elseif (isset(self::STATUS_RANK[$new]) && (self::STATUS_RANK[$new] > (self::STATUS_RANK[$current] ?? 99))) {
                $update = ['status' => $new];
            }
            if ($update !== null) {
                $update['updated_at'] = now();
                $this->db->update('messages', $update, ['id' => (int) $msg['id'], 'tenant_id' => $tid]);
                if ($msg['campaign_id'] !== null) {
                    CampaignService::mirror($this->db, $tid, (int) $msg['id']);
                }
            }
        });
        return true;
    }

    public function assign(array $conversation, ?int $toUserId, ?int $toTeamId, ?int $byUserId): void
    {
        $tid = $this->tenant->id();
        if ($toUserId !== null && $this->db->scalar('SELECT 1 FROM users WHERE id = ? AND tenant_id = ? AND status = ?', [$toUserId, $tid, 'active']) === null) {
            throw new ValidationException(['assigned_user_id' => 'Unknown or inactive agent.']);
        }
        if ($toTeamId !== null && $this->db->scalar('SELECT 1 FROM teams WHERE id = ? AND tenant_id = ?', [$toTeamId, $tid]) === null) {
            throw new ValidationException(['assigned_team_id' => 'Unknown team.']);
        }
        $this->db->transaction(function () use ($conversation, $toUserId, $toTeamId, $byUserId, $tid): void {
            $this->db->update('conversations', [
                'assigned_user_id' => $toUserId, 'assigned_team_id' => $toTeamId, 'updated_at' => now(),
            ], ['id' => (int) $conversation['id'], 'tenant_id' => $tid]);
            $this->db->insert('conversation_assignments', [
                'tenant_id' => $tid, 'conversation_id' => (int) $conversation['id'],
                'from_user_id' => $conversation['assigned_user_id'] ?? null, 'to_user_id' => $toUserId,
                'to_team_id' => $toTeamId, 'assigned_by' => $byUserId, 'created_at' => now(),
            ]);
        });
    }

    /** @param 'close'|'reopen'|'pin'|'unpin'|'archive'|'unarchive'|'read'|'unread' $action */
    public function setFlag(array $conversation, string $action): void
    {
        $data = match ($action) {
            'close' => ['status' => 'closed', 'closed_at' => now()],
            'reopen' => ['status' => 'open', 'closed_at' => null],
            'pin' => ['is_pinned' => 1],
            'unpin' => ['is_pinned' => 0],
            'archive' => ['is_archived' => 1],
            'unarchive' => ['is_archived' => 0],
            'read' => ['unread_count' => 0],
            'unread' => ['unread_count' => max(1, (int) $conversation['unread_count'])],
            default => throw new ValidationException(['action' => 'Unknown action.']),
        };
        $data['updated_at'] = now();
        $this->conversations->update((int) $conversation['id'], $data);
    }

    public function setPriority(array $conversation, string $priority): void
    {
        if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
            throw new ValidationException(['priority' => 'Invalid priority.']);
        }
        $this->conversations->update((int) $conversation['id'], ['priority' => $priority, 'updated_at' => now()]);
    }
}
