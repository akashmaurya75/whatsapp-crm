<?php

declare(strict_types=1);

namespace App\Domain\Automation;

use App\Core\Database;
use App\Core\Queue;
use App\Domain\Contacts\ContactService;
use App\Domain\Conversations\InboxService;
use App\Domain\Templates\TemplateRepository;
use App\Domain\Templates\TemplateService;
use App\Domain\ValidationException;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantRepository;

/**
 * Auto-replies triggered by inbound customer messages:
 *   welcome  – first message ever from a contact (once per contact)
 *   away     – outside business hours (with cooldown)
 *   keyword  – message matches keywords → reply and/or tag, assign, set lead status
 * Guards: only customer messages trigger rules (no loops), opted-out contacts get nothing,
 * max 2 auto-replies per inbound message and 6 per contact per hour, per-rule cooldowns.
 */
final class AutomationService
{
    public const TYPES = ['welcome' => 'Welcome message', 'away' => 'Out-of-office reply', 'keyword' => 'Keyword reply'];
    public const DAYS = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];
    public const MATCH = ['exact' => 'Message is exactly', 'contains' => 'Message contains', 'starts' => 'Message starts with'];
    private const MAX_PER_MESSAGE = 2;
    private const MAX_PER_CONTACT_HOUR = 6;

    private TenantRepository $repo;

    public function __construct(private Database $db, private TenantContext $tenant, private ?Queue $queue = null)
    {
        $this->repo = new class ($db, $tenant) extends TenantRepository {
            protected string $table = 'automation_rules';
        };
    }

    public static function config(array $rule): array
    {
        $c = json_decode((string) $rule['config'], true);
        return is_array($c) ? $c : [];
    }

    public function find(int $id): array
    {
        return $this->repo->findOrFail($id);
    }

    /** @return list<array> */
    public function rules(): array
    {
        return $this->db->all("SELECT * FROM automation_rules WHERE tenant_id = ? ORDER BY CASE type WHEN 'welcome' THEN 0 WHEN 'away' THEN 1 ELSE 2 END, priority, id",
            [$this->tenant->id()]);
    }

    public static function defaultHours(): array
    {
        $h = [];
        foreach (array_keys(self::DAYS) as $d) {
            $h[$d] = ['open' => !in_array($d, ['sun'], true), 'start' => '09:00', 'end' => '18:00'];
        }
        return $h;
    }

    // ------------------------------------------------------------------ CRUD

    public function save(array $in, ?int $id = null): int
    {
        $type = $id !== null ? (string) $this->find($id)['type'] : (string) ($in['type'] ?? '');
        if (!isset(self::TYPES[$type])) {
            throw new ValidationException(['type' => 'Unknown automation type.']);
        }
        $e = [];
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 120) ?: self::TYPES[$type];
        $config = ['reply' => $this->validateReply((array) ($in['reply'] ?? []), $e)];
        $cooldown = max(0, min(10080, (int) ($in['cooldown_minutes'] ?? ($type === 'away' ? 240 : 0))));

        if ($type === 'away') {
            $tz = (string) ($in['timezone'] ?? 'UTC');
            if (!in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
                $e['timezone'] = 'Choose a valid time zone.';
            }
            $hours = [];
            foreach (array_keys(self::DAYS) as $d) {
                $r = (array) (($in['hours'] ?? [])[$d] ?? []);
                $start = (string) ($r['start'] ?? '09:00');
                $end = (string) ($r['end'] ?? '18:00');
                if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end) || $start === $end) {
                    $e['hours'] = 'Use HH:MM times, and different start and end times (' . self::DAYS[$d] . ').';
                }
                $hours[$d] = ['open' => !empty($r['open']), 'start' => $start, 'end' => $end];
            }
            $config += ['timezone' => $tz, 'hours' => $hours];
        }
        if ($type === 'keyword') {
            $keywords = array_values(array_unique(array_filter(array_map(
                static fn ($k) => mb_strtolower(trim($k)),
                preg_split('/[,\n]+/', (string) ($in['keywords'] ?? '')) ?: []
            ))));
            if ($keywords === []) {
                $e['keywords'] = 'Enter at least one keyword.';
            }
            $match = (string) ($in['match'] ?? 'contains');
            $actions = (array) ($in['actions'] ?? []);
            $tid = $this->tenant->id();
            $teamId = (int) ($actions['assign_team_id'] ?? 0);
            $userId = (int) ($actions['assign_user_id'] ?? 0);
            if ($teamId > 0 && $this->db->scalar('SELECT 1 FROM teams WHERE id = ? AND tenant_id = ?', [$teamId, $tid]) === null) {
                $e['actions'] = 'Unknown team.';
            }
            if ($userId > 0 && $this->db->scalar('SELECT 1 FROM users WHERE id = ? AND tenant_id = ?', [$userId, $tid]) === null) {
                $e['actions'] = 'Unknown user.';
            }
            $lead = (string) ($actions['lead_status'] ?? '');
            $config += [
                'keywords' => array_slice($keywords, 0, 50), 'match' => isset(self::MATCH[$match]) ? $match : 'contains',
                'actions' => [
                    'add_tags' => array_slice(array_values(array_filter(array_map('trim', explode(',', (string) ($actions['add_tags'] ?? ''))))), 0, 10),
                    'assign_team_id' => $teamId ?: null, 'assign_user_id' => $userId ?: null,
                    'lead_status' => in_array($lead, ContactService::LEAD_STATUSES, true) ? $lead : null,
                ],
            ];
            $hasAction = $config['actions']['add_tags'] !== [] || $teamId || $userId || $config['actions']['lead_status'];
            if ($config['reply']['type'] === 'none' && !$hasAction) {
                $e['reply'] = 'Choose a reply or at least one action.';
            }
        } elseif ($config['reply']['type'] === 'none') {
            $e['reply'] = 'Write the reply message.';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $row = ['name' => $name, 'is_active' => !empty($in['is_active']) ? 1 : 0, 'priority' => max(1, min(1000, (int) ($in['priority'] ?? 100))),
            'config' => json_encode($config, JSON_UNESCAPED_UNICODE), 'cooldown_minutes' => $cooldown, 'updated_at' => now()];
        if ($id !== null) {
            $this->repo->update($id, $row);
            return $id;
        }
        if ($type !== 'keyword' && $this->db->scalar('SELECT id FROM automation_rules WHERE tenant_id = ? AND type = ?', [$this->tenant->id(), $type]) !== null) {
            throw new ValidationException(['type' => 'You already have a ' . strtolower(self::TYPES[$type]) . '. Edit the existing one.']);
        }
        return $this->repo->create($row + ['type' => $type, 'whatsapp_account_id' => null, 'created_at' => now()]);
    }

    private function validateReply(array $r, array &$e): array
    {
        $type = (string) ($r['type'] ?? 'text');
        if ($type === 'text') {
            $text = trim((string) ($r['text'] ?? ''));
            if ($text === '') {
                return ['type' => 'none'];
            }
            if (mb_strlen($text) > 4096) {
                $e['reply'] = 'Reply is too long (max 4096 characters).';
            }
            return ['type' => 'text', 'text' => $text];
        }
        if ($type === 'template') {
            $t = (new TemplateRepository($this->db, $this->tenant))->find((int) ($r['template_id'] ?? 0));
            if ($t === null || ($why = TemplateService::unsendableReason($t)) !== null) {
                $e['reply'] = $t === null ? 'Choose an approved template.' : $why;
                return ['type' => 'none'];
            }
            $map = TemplateService::cleanMap($t, (array) ($r['map'] ?? []));
            if (($me = TemplateService::mapErrors($t, $map)) !== []) {
                $e['reply'] = (string) array_values($me)[0];
            }
            return ['type' => 'template', 'template_id' => (int) $t['id'], 'map' => $map];
        }
        return ['type' => 'none'];
    }

    public function toggle(int $id): void
    {
        $r = $this->find($id);
        $this->repo->update($id, ['is_active' => (int) $r['is_active'] === 1 ? 0 : 1, 'updated_at' => now()]);
    }

    public function delete(int $id): void
    {
        $this->find($id);
        $this->repo->delete($id);
    }

    // ------------------------------------------------------------------ engine

    public static function isOpen(array $config, int $now): bool
    {
        $tz = new \DateTimeZone((string) ($config['timezone'] ?? 'UTC'));
        $dt = (new \DateTimeImmutable('@' . $now))->setTimezone($tz);
        $day = strtolower($dt->format('D'));
        $h = $config['hours'][$day] ?? null;
        if ($h === null || empty($h['open'])) {
            return false;
        }
        $t = $dt->format('H:i');
        return $h['start'] < $h['end'] ? ($t >= $h['start'] && $t < $h['end']) : ($t >= $h['start'] || $t < $h['end']);
    }

    public static function matches(array $config, string $body): bool
    {
        $text = mb_strtolower(trim(preg_replace('/\s+/', ' ', $body) ?? ''));
        if ($text === '') {
            return false;
        }
        foreach ((array) ($config['keywords'] ?? []) as $k) {
            $k = (string) $k;
            $ok = match ($config['match'] ?? 'contains') {
                'exact' => $text === $k,
                'starts' => str_starts_with($text, $k),
                default => preg_match('/(^|\W)' . preg_quote($k, '/') . '($|\W)/u', $text) === 1,
            };
            if ($ok) {
                return true;
            }
        }
        return false;
    }

    /** Run automations for a stored inbound message. Returns number of replies queued. */
    public function handleInbound(array $account, int $messageId, ?int $now = null): int
    {
        $now ??= time();
        $tid = $this->tenant->id();
        $msg = $this->db->first("SELECT * FROM messages WHERE id = ? AND tenant_id = ? AND direction = 'in'", [$messageId, $tid]);
        if ($msg === null) {
            return 0;
        }
        $conv = (array) $this->db->first('SELECT * FROM conversations WHERE id = ? AND tenant_id = ?', [$msg['conversation_id'], $tid]);
        $contact = (array) $this->db->first('SELECT * FROM contacts WHERE id = ? AND tenant_id = ?', [$conv['contact_id'], $tid]);
        if ($contact === [] || $contact['opted_out_at'] !== null) {
            return 0;
        }
        $rules = $this->db->all('SELECT * FROM automation_rules WHERE tenant_id = ? AND is_active = 1 AND (whatsapp_account_id IS NULL OR whatsapp_account_id = ?)
            ORDER BY priority, id', [$tid, $account['id']]);
        if ($rules === []) {
            return 0;
        }
        $hourly = (int) $this->db->scalar("SELECT COUNT(*) FROM automation_runs WHERE tenant_id = ? AND contact_id = ? AND status = 'sent' AND created_at >= ?",
            [$tid, $contact['id'], gmdate('Y-m-d H:i:s', $now - 3600)]);
        $budget = min(self::MAX_PER_MESSAGE, self::MAX_PER_CONTACT_HOUR - $hourly);
        $firstEver = (int) $this->db->scalar("SELECT COUNT(*) FROM messages m JOIN conversations cv ON cv.id = m.conversation_id
            WHERE cv.contact_id = ? AND m.tenant_id = ? AND m.direction = 'in'", [$contact['id'], $tid]) === 1;

        $sent = 0;
        $welcomed = false;
        // 1. Keyword rules (first match wins).
        foreach ($rules as $rule) {
            if ($rule['type'] !== 'keyword' || !self::matches(self::config($rule), (string) $msg['body'])) {
                continue;
            }
            $sent += $this->run($rule, $account, $conv, $contact, $messageId, $budget - $sent, $now, true);
            break;
        }
        // 2. Welcome for brand-new contacts.
        foreach ($rules as $rule) {
            if ($rule['type'] === 'welcome' && $firstEver) {
                $n = $this->run($rule, $account, $conv, $contact, $messageId, $budget - $sent, $now);
                $sent += $n;
                $welcomed = $n > 0;
            }
        }
        // 3. Out-of-office (not on top of a welcome).
        foreach ($rules as $rule) {
            if ($rule['type'] === 'away' && !$welcomed && !self::isOpen(self::config($rule), $now)) {
                $sent += $this->run($rule, $account, $conv, $contact, $messageId, $budget - $sent, $now);
            }
        }
        return $sent;
    }

    private function run(array $rule, array $account, array $conv, array $contact, int $messageId, int $budget, int $now, bool $actions = false): int
    {
        $tid = $this->tenant->id();
        $cfg = self::config($rule);
        $log = function (string $status, string $detail = '') use ($rule, $contact, $conv, $messageId, $tid, $now): void {
            $this->db->insert('automation_runs', ['tenant_id' => $tid, 'rule_id' => (int) $rule['id'], 'contact_id' => (int) $contact['id'],
                'conversation_id' => (int) $conv['id'], 'trigger_message_id' => $messageId, 'status' => $status,
                'detail' => $detail !== '' ? mb_substr($detail, 0, 500) : null, 'created_at' => gmdate('Y-m-d H:i:s', $now)]);
        };
        // Welcome: once per contact. Others: per-rule cooldown.
        $since = $rule['type'] === 'welcome' ? '1970-01-01 00:00:00' : gmdate('Y-m-d H:i:s', $now - (int) $rule['cooldown_minutes'] * 60);
        if (($rule['type'] === 'welcome' || (int) $rule['cooldown_minutes'] > 0) && $this->db->scalar(
            "SELECT 1 FROM automation_runs WHERE rule_id = ? AND contact_id = ? AND status = 'sent' AND created_at >= ?",
            [$rule['id'], $contact['id'], $since]
        ) !== null) {
            return 0;
        }
        if ($actions) {
            $this->applyActions($cfg['actions'] ?? [], $conv, $contact);
        }
        $reply = $cfg['reply'] ?? ['type' => 'none'];
        if ($reply['type'] === 'none') {
            $log('sent', 'Actions applied (no reply)');
            return 0;
        }
        if ($budget <= 0) {
            $log('skipped', 'Auto-reply limit reached for this contact');
            return 0;
        }
        $inbox = new InboxService($this->db, $this->tenant, $this->queue);
        $fresh = (array) $this->db->first('SELECT * FROM conversations WHERE id = ? AND tenant_id = ?', [$conv['id'], $tid]);
        try {
            if ($reply['type'] === 'text') {
                $inbox->queueText($fresh, null, (string) $reply['text'], 'auto-' . $rule['id'] . '-' . $messageId);
            } else {
                $t = (new TemplateRepository($this->db, $this->tenant))->findOrFail((int) $reply['template_id']);
                $inbox->queueTemplate($fresh, null, $t, TemplateService::resolve((array) $reply['map'], $contact), null, 'auto-' . $rule['id'] . '-' . $messageId);
            }
        } catch (ValidationException $e) {
            $log('failed', $e->getMessage());
            return 0;
        }
        $log('sent');
        return 1;
    }

    private function applyActions(array $a, array $conv, array $contact): void
    {
        $tid = $this->tenant->id();
        $contacts = new ContactService($this->db, $this->tenant);
        if (!empty($a['add_tags'])) {
            $contacts->repo->syncTags((int) $contact['id'], array_merge($contacts->repo->tags((int) $contact['id']), (array) $a['add_tags']));
        }
        if (!empty($a['lead_status'])) {
            $this->db->update('contacts', ['lead_status' => $a['lead_status'], 'updated_at' => now()], ['id' => (int) $contact['id'], 'tenant_id' => $tid]);
        }
        if (!empty($a['assign_team_id']) || !empty($a['assign_user_id'])) {
            $userOk = !empty($a['assign_user_id']) && $this->db->scalar("SELECT 1 FROM users WHERE id = ? AND tenant_id = ? AND status = 'active'", [$a['assign_user_id'], $tid]) !== null;
            (new InboxService($this->db, $this->tenant))->assign($conv, $userOk ? (int) $a['assign_user_id'] : null,
                !empty($a['assign_team_id']) ? (int) $a['assign_team_id'] : null, null);
        }
    }

    /** @return list<array> */
    public function recentRuns(int $limit = 30): array
    {
        return $this->db->all('SELECT r.*, a.name AS rule_name, c.name AS contact_name, c.phone_e164 FROM automation_runs r
            JOIN automation_rules a ON a.id = r.rule_id JOIN contacts c ON c.id = r.contact_id
            WHERE r.tenant_id = ? ORDER BY r.id DESC LIMIT ' . max(1, min(200, $limit)), [$this->tenant->id()]);
    }
}
