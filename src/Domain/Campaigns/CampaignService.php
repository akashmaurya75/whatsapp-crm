<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

use App\Core\Database;
use App\Core\Queue;
use App\Domain\Contacts\ContactService;
use App\Domain\Contacts\PhoneNormalizer;
use App\Domain\Conversations\InboxService;
use App\Domain\Templates\TemplateRepository;
use App\Domain\Templates\TemplateService;
use App\Domain\ValidationException;
use App\Tenancy\TenantContext;
use App\WhatsApp\AccountRepository;
use App\WhatsApp\ProviderFactory;
use App\WhatsApp\TemplateProvider;

/**
 * Broadcast campaigns using approved templates.
 *
 * Lifecycle: draft → scheduled → running → completed
 *                         ↘ paused ↔ running      any → cancelled
 * Safeguards: opted-out contacts are always excluded; marketing campaigns require recorded
 * consent unless the user explicitly attests to it; a frequency cap skips contacts messaged by
 * another campaign recently; sending is throttled per minute and per second.
 */
final class CampaignService
{
    public const EDITABLE = ['draft', 'scheduled'];
    public const SKIP_REASONS = [
        'opted_out' => 'Opted out', 'no_consent' => 'No recorded consent', 'frequency_cap' => 'Messaged recently (frequency cap)',
        'cancelled' => 'Campaign cancelled', 'error' => 'Could not prepare message',
    ];

    public readonly CampaignRepository $repo;

    public function __construct(
        private Database $db,
        private TenantContext $tenant,
        private ?Queue $queue = null,
        private ?ProviderFactory $providers = null,
    ) {
        $this->repo = new CampaignRepository($db, $tenant);
    }

    public static function audience(array $c): array
    {
        $a = json_decode((string) ($c['audience'] ?? ''), true);
        return is_array($a) ? $a + ['tags_any' => [], 'tags_none' => [], 'lead_statuses' => [], 'active_days' => 0] :
            ['tags_any' => [], 'tags_none' => [], 'lead_statuses' => [], 'active_days' => 0];
    }

    public static function variableMap(array $c): array
    {
        $m = json_decode((string) ($c['variable_map'] ?? ''), true);
        return is_array($m) ? $m : [];
    }

    public function template(array $c): array
    {
        return (new TemplateRepository($this->db, $this->tenant))->findOrFail((int) $c['template_id']);
    }

    /** Time zone used for scheduling in the UI. */
    public function timezone(): string
    {
        $tz = (string) $this->db->scalar('SELECT timezone FROM tenants WHERE id = ?', [$this->tenant->id()]);
        if ($tz === '' || $tz === 'UTC') {
            $tz = (string) env('APP_TIMEZONE', 'UTC');
        }
        return in_array($tz, \DateTimeZone::listIdentifiers(), true) ? $tz : 'UTC';
    }

    // ------------------------------------------------------------------ create & edit

    public function create(array $in, ?int $userId): int
    {
        $e = [];
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 160);
        if ($name === '') {
            $e['name'] = 'Give the campaign a name.';
        }
        $template = (new TemplateRepository($this->db, $this->tenant))->find((int) ($in['template_id'] ?? 0));
        if ($template === null) {
            $e['template_id'] = 'Choose an approved template.';
        } elseif (($why = TemplateService::unsendableReason($template)) !== null) {
            $e['template_id'] = $why;
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $marketing = $template['category'] === 'marketing';
        return $this->repo->create([
            'whatsapp_account_id' => (int) $template['whatsapp_account_id'], 'template_id' => (int) $template['id'], 'name' => $name,
            'status' => 'draft', 'audience' => json_encode(['tags_any' => [], 'tags_none' => [], 'lead_statuses' => [], 'active_days' => 0]),
            'variable_map' => json_encode(TemplateService::defaultMap($template), JSON_UNESCAPED_UNICODE),
            'require_consent' => $marketing ? 1 : 0, 'frequency_cap_hours' => $marketing ? 24 : 0, 'rate_per_minute' => 300,
            'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Save audience, variables, media, safeguards and schedule. */
    public function saveSettings(int $id, array $in, ?int $userId, ?array $mediaFile = null): void
    {
        $c = $this->repo->findOrFail($id);
        if (!in_array($c['status'], self::EDITABLE, true)) {
            throw new ValidationException(['status' => 'This campaign can no longer be edited.']);
        }
        $t = $this->template($c);
        $e = [];
        $clean = static fn ($v) => array_values(array_unique(array_filter(array_map(static fn ($x) => mb_substr(trim((string) $x), 0, 60), (array) $v))));
        $audience = [
            'tags_any' => $clean($in['tags_any'] ?? []),
            'tags_none' => $clean($in['tags_none'] ?? []),
            'lead_statuses' => array_values(array_intersect(ContactService::LEAD_STATUSES, (array) ($in['lead_statuses'] ?? []))),
            'active_days' => max(0, min(365, (int) ($in['active_days'] ?? 0))),
        ];
        $map = TemplateService::cleanMap($t, (array) ($in['map'] ?? []));
        $e += TemplateService::mapErrors($t, $map);

        $data = ['audience' => json_encode($audience, JSON_UNESCAPED_UNICODE), 'variable_map' => json_encode($map, JSON_UNESCAPED_UNICODE)];
        if (TemplateService::needsMedia($t)) {
            $url = trim((string) ($in['header_media_url'] ?? ''));
            if ($mediaFile !== null && ($mediaFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                try {
                    $data['header_media_id'] = $this->uploadMedia($c, $t, $mediaFile);
                    $data['header_media_url'] = null;
                } catch (ValidationException $ve) {
                    $e += $ve->errors;
                }
            } elseif ($url !== '') {
                if (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($url), 'https://')) {
                    $e['header_media_url'] = 'Use a public https:// link to the file.';
                } else {
                    $data['header_media_url'] = mb_substr($url, 0, 1000);
                    $data['header_media_id'] = null;
                }
            } elseif (empty($c['header_media_id']) && empty($c['header_media_url'])) {
                $e['header_media_url'] = 'This template has a ' . $t['header_type'] . ' header: upload the file or paste a public https link.';
            }
        }

        $requireConsent = !empty($in['require_consent']);
        $data['require_consent'] = $requireConsent ? 1 : 0;
        if (!$requireConsent) {
            if (empty($in['consent_attested'])) {
                $e['consent_attested'] = 'Confirm that these contacts agreed to receive WhatsApp messages from you, or keep "only contacts with consent" on.';
            } else {
                $data['consent_attested_by'] = $userId;
            }
        } else {
            $data['consent_attested_by'] = null;
        }
        $data['frequency_cap_hours'] = max(0, min(720, (int) ($in['frequency_cap_hours'] ?? 24)));
        $data['rate_per_minute'] = max(10, min(3000, (int) ($in['rate_per_minute'] ?? 300)));

        $mode = (string) ($in['send_mode'] ?? 'now');
        if ($mode === 'later') {
            $local = trim((string) ($in['scheduled_at'] ?? ''));
            try {
                $dt = new \DateTimeImmutable($local, new \DateTimeZone($this->timezone()));
            } catch (\Exception) {
                $dt = null;
            }
            if ($local === '' || $dt === null) {
                $e['scheduled_at'] = 'Pick the date and time to send.';
            } elseif ($dt->getTimestamp() < time() + 60) {
                $e['scheduled_at'] = 'Pick a time at least a minute in the future.';
            } elseif ($dt->getTimestamp() > time() + 90 * 86400) {
                $e['scheduled_at'] = 'Campaigns can be scheduled up to 90 days ahead.';
            } else {
                $data['scheduled_at'] = gmdate('Y-m-d H:i:s', $dt->getTimestamp());
            }
        } else {
            $data['scheduled_at'] = null;
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $data['updated_at'] = now();
        if ($c['status'] === 'scheduled') {
            $data['status'] = 'draft'; // edited: must be launched again after review
        }
        $this->repo->update($id, $data);
    }

    private function uploadMedia(array $c, array $t, array $file): string
    {
        $mime = TemplateService::checkMediaFile((string) $t['header_type'], $file, 'header_media');
        $account = (new AccountRepository($this->db, $this->tenant))->findOrFail((int) $c['whatsapp_account_id']);
        $p = $this->providers?->get((string) $account['provider']);
        if (!$p instanceof TemplateProvider) {
            throw new ValidationException(['header_media' => 'This account cannot upload media.']);
        }
        $r = $p->uploadMedia($account, AccountRepository::credentials($account), (string) $file['tmp_name'], $mime, (string) $file['name']);
        if (!$r['ok']) {
            throw new ValidationException(['header_media' => 'Upload to WhatsApp failed: ' . $r['error']]);
        }
        return (string) $r['id'];
    }

    public function media(array $c): ?array
    {
        if (!empty($c['header_media_id'])) {
            return ['id' => (string) $c['header_media_id'], 'filename' => 'document.pdf'];
        }
        if (!empty($c['header_media_url'])) {
            return ['link' => (string) $c['header_media_url'], 'filename' => basename((string) parse_url((string) $c['header_media_url'], PHP_URL_PATH)) ?: 'document.pdf'];
        }
        return null;
    }

    // ------------------------------------------------------------------ audience

    /**
     * SQL over "contacts c" for the campaign's audience filters. Each call uses unique
     * parameter names (MySQL native prepares cannot reuse a named parameter).
     * @return array{0:string,1:array<string,mixed>}
     */
    public function audienceSql(array $c, string $p = 'a'): array
    {
        $a = self::audience($c);
        $tid = $this->tenant->id();
        $where = ["c.tenant_id = :{$p}tid", 'c.archived_at IS NULL'];
        $params = ["{$p}tid" => $tid];
        $in = static function (array $values, string $key) use (&$params): string {
            $names = [];
            foreach (array_values($values) as $i => $v) {
                $names[] = ':' . $key . $i;
                $params[$key . $i] = $v;
            }
            return implode(', ', $names);
        };
        if ($a['tags_any'] !== []) {
            $where[] = "EXISTS (SELECT 1 FROM contact_tags ct JOIN tags t ON t.id = ct.tag_id WHERE ct.contact_id = c.id AND t.tenant_id = :{$p}tid2 AND t.name IN (" . $in($a['tags_any'], "{$p}ta") . '))';
            $params["{$p}tid2"] = $tid;
        }
        if ($a['tags_none'] !== []) {
            $where[] = "NOT EXISTS (SELECT 1 FROM contact_tags ct2 JOIN tags t2 ON t2.id = ct2.tag_id WHERE ct2.contact_id = c.id AND t2.tenant_id = :{$p}tid3 AND t2.name IN (" . $in($a['tags_none'], "{$p}tn") . '))';
            $params["{$p}tid3"] = $tid;
        }
        if ($a['lead_statuses'] !== []) {
            $where[] = 'c.lead_status IN (' . $in($a['lead_statuses'], "{$p}ls") . ')';
        }
        if ((int) $a['active_days'] > 0) {
            $where[] = "c.id IN (SELECT cv.contact_id FROM conversations cv WHERE cv.tenant_id = :{$p}tid4 AND cv.last_inbound_at >= :{$p}since)";
            $params["{$p}tid4"] = $tid;
            $params["{$p}since"] = gmdate('Y-m-d H:i:s', time() - (int) $a['active_days'] * 86400);
        }
        return [implode(' AND ', $where), $params];
    }

    /** CASE expression yielding the skip reason (or NULL when the contact is eligible). */
    public function exclusionSql(array $c, string $p = 'x'): array
    {
        $sql = "CASE WHEN c.opted_out_at IS NOT NULL THEN 'opted_out'
            WHEN :{$p}req = 1 AND c.consent_status <> 'granted' THEN 'no_consent'
            WHEN :{$p}cap > 0 AND EXISTS (SELECT 1 FROM campaign_recipients r2 JOIN messages m2 ON m2.id = r2.message_id
                WHERE r2.tenant_id = :{$p}tid AND r2.contact_id = c.id AND r2.campaign_id <> :{$p}cid
                AND r2.status NOT IN ('failed', 'skipped', 'pending') AND m2.created_at >= :{$p}since) THEN 'frequency_cap'
            ELSE NULL END";
        return [$sql, [
            "{$p}req" => (int) $c['require_consent'], "{$p}cap" => (int) $c['frequency_cap_hours'], "{$p}tid" => $this->tenant->id(),
            "{$p}cid" => (int) $c['id'], "{$p}since" => gmdate('Y-m-d H:i:s', time() - (int) $c['frequency_cap_hours'] * 3600),
        ]];
    }

    /** Dry run: who would receive it, who is excluded and why, plus sample messages. */
    public function preview(array $c): array
    {
        [$where, $wp] = $this->audienceSql($c);
        [$case, $xp] = $this->exclusionSql($c);
        $rows = $this->db->all("SELECT reason, COUNT(*) AS n FROM (SELECT $case AS reason FROM contacts c WHERE $where) x GROUP BY reason", $wp + $xp);
        $matched = 0;
        $eligible = 0;
        $excluded = [];
        foreach ($rows as $r) {
            $matched += (int) $r['n'];
            if ($r['reason'] === null) {
                $eligible = (int) $r['n'];
            } else {
                $excluded[(string) $r['reason']] = (int) $r['n'];
            }
        }
        [$where2, $wp2] = $this->audienceSql($c, 'b');
        [$case2, $xp2] = $this->exclusionSql($c, 'y');
        $sampleContacts = $this->db->all("SELECT c.* FROM contacts c WHERE $where2 AND ($case2) IS NULL ORDER BY c.id LIMIT 3", $wp2 + $xp2);
        $t = $this->template($c);
        $map = self::variableMap($c);
        $samples = array_map(static fn ($ct) => [
            'contact' => $ct, 'text' => TemplateService::render($t, TemplateService::resolve($map, $ct)),
        ], $sampleContacts);
        return ['matched' => $matched, 'eligible' => $eligible, 'excluded' => $excluded, 'samples' => $samples];
    }

    // ------------------------------------------------------------------ actions

    /** @return list<string> problems preventing launch */
    public function problems(array $c): array
    {
        $p = [];
        $t = $this->template($c);
        if (($why = TemplateService::unsendableReason($t)) !== null) {
            $p[] = $why;
        }
        if (TemplateService::mapErrors($t, self::variableMap($c)) !== []) {
            $p[] = 'Fill in every template variable.';
        }
        if (TemplateService::needsMedia($t) && $this->media($c) === null) {
            $p[] = 'Add the header ' . $t['header_type'] . '.';
        }
        if ((int) $c['require_consent'] === 0 && $c['consent_attested_by'] === null) {
            $p[] = 'Confirm consent or keep "only contacts with consent" on.';
        }
        if ($p === [] && $this->preview($c)['eligible'] === 0) {
            $p[] = 'No contacts would receive this campaign. Adjust the audience or consent settings.';
        }
        if ($c['scheduled_at'] !== null && strtotime($c['scheduled_at'] . ' UTC') < time() - 300 && $c['status'] === 'draft') {
            $p[] = 'The scheduled time has passed. Pick a new time or choose "Send now".';
        }
        return $p;
    }

    public function launch(int $id): array
    {
        $c = $this->repo->findOrFail($id);
        if ($c['status'] !== 'draft') {
            throw new ValidationException(['status' => 'Only draft campaigns can be launched.']);
        }
        if (($p = $this->problems($c)) !== []) {
            throw new ValidationException(['launch' => implode(' ', $p)]);
        }
        $when = $c['scheduled_at'] ?? now();
        $this->repo->update($id, ['status' => 'scheduled', 'scheduled_at' => $when, 'last_error' => null, 'updated_at' => now()]);
        $delay = max(0, (int) strtotime($when . ' UTC') - time());
        $this->queue?->push(Jobs\CampaignTickJob::class, ['campaign_id' => $id], 'default', $delay);
        return (array) $this->repo->find($id);
    }

    public function pause(int $id): void
    {
        $c = $this->repo->findOrFail($id);
        if (!in_array($c['status'], ['running', 'scheduled'], true)) {
            throw new ValidationException(['status' => 'Only running or scheduled campaigns can be paused.']);
        }
        $this->repo->update($id, ['status' => 'paused', 'updated_at' => now()]);
    }

    public function resume(int $id): void
    {
        $c = $this->repo->findOrFail($id);
        if ($c['status'] !== 'paused') {
            throw new ValidationException(['status' => 'Only paused campaigns can be resumed.']);
        }
        $status = $c['started_at'] !== null ? 'running' : 'scheduled';
        $this->repo->update($id, ['status' => $status, 'updated_at' => now()]);
        $this->queue?->push(Jobs\CampaignTickJob::class, ['campaign_id' => $id], 'default');
    }

    public function cancel(int $id): void
    {
        $c = $this->repo->findOrFail($id);
        if (in_array($c['status'], ['completed', 'cancelled'], true)) {
            throw new ValidationException(['status' => 'This campaign has already finished.']);
        }
        $tid = $this->tenant->id();
        $this->db->transaction(function () use ($id, $tid): void {
            $this->repo->update($id, ['status' => 'cancelled', 'cancelled_at' => now(), 'updated_at' => now()]);
            $this->db->run("UPDATE campaign_recipients SET status = 'skipped', skip_reason = 'cancelled', updated_at = ? WHERE campaign_id = ? AND tenant_id = ? AND status = 'pending'",
                [now(), $id, $tid]);
        });
    }

    public function delete(int $id): void
    {
        $c = $this->repo->findOrFail($id);
        if (!in_array($c['status'], ['draft', 'cancelled'], true)) {
            throw new ValidationException(['status' => 'Only drafts and cancelled campaigns can be deleted.']);
        }
        $this->repo->delete($id);
    }

    public function duplicate(int $id, ?int $userId): int
    {
        $c = $this->repo->findOrFail($id);
        $row = array_intersect_key($c, array_flip(['whatsapp_account_id', 'template_id', 'audience', 'variable_map', 'header_media_url',
            'header_media_id', 'require_consent', 'frequency_cap_hours', 'rate_per_minute']));
        return $this->repo->create($row + ['name' => mb_substr('Copy of ' . $c['name'], 0, 160), 'status' => 'draft',
            'created_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Send the campaign's message to one phone number (not counted in the campaign). */
    public function sendTest(int $id, string $phone, ?int $userId): array
    {
        $c = $this->repo->findOrFail($id);
        $t = $this->template($c);
        $e164 = PhoneNormalizer::normalize($phone);
        if ($e164 === null) {
            throw new ValidationException(['test_phone' => 'Enter a valid WhatsApp number with country code.']);
        }
        $inbox = new InboxService($this->db, $this->tenant, $this->queue);
        $contact = $inbox->contacts->findOrCreateByPhone($e164, null);
        $conv = $inbox->findOrCreateConversation((int) $contact['id'], (int) $c['whatsapp_account_id']);
        $map = self::variableMap($c);
        if (TemplateService::mapErrors($t, $map) !== []) {
            throw new ValidationException(['test_phone' => 'Fill in the template variables first.']);
        }
        try {
            return $inbox->queueTemplate($conv, $userId, $t, TemplateService::resolve($map, $contact), $this->media($c));
        } catch (ValidationException $ve) {
            throw new ValidationException(['test_phone' => (string) array_values($ve->errors)[0]]);
        }
    }

    // ------------------------------------------------------------------ reporting

    public function stats(array $c): array
    {
        $rows = $this->db->all('SELECT status, COUNT(*) AS n FROM campaign_recipients WHERE campaign_id = ? AND tenant_id = ? GROUP BY status',
            [$c['id'], $this->tenant->id()]);
        $by = array_fill_keys(['pending', 'queued', 'submitted', 'sent', 'delivered', 'read', 'failed', 'skipped'], 0);
        foreach ($rows as $r) {
            $by[$r['status']] = (int) $r['n'];
        }
        $replied = (int) $this->db->scalar('SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = ? AND tenant_id = ? AND replied_at IS NOT NULL',
            [$c['id'], $this->tenant->id()]);
        $sent = $by['submitted'] + $by['sent'] + $by['delivered'] + $by['read'];
        return $by + [
            'audience' => array_sum($by) - $by['skipped'],
            'accepted' => $sent, 'delivered_total' => $by['delivered'] + $by['read'], 'replied' => $replied,
            'in_progress' => $by['pending'] + $by['queued'],
        ];
    }

    public static function mirror(Database $db, int $tenantId, int $messageId): void
    {
        $m = $db->first('SELECT status, error_message FROM messages WHERE id = ? AND tenant_id = ?', [$messageId, $tenantId]);
        if ($m === null) {
            return;
        }
        $db->run('UPDATE campaign_recipients SET status = ?, error = ?, updated_at = ? WHERE message_id = ? AND tenant_id = ?',
            [$m['status'], $m['status'] === 'failed' ? $m['error_message'] : null, now(), $messageId, $tenantId]);
    }

    /** Attribute an inbound message as a reply to campaigns sent to this contact in the last 7 days. */
    public static function markReplied(Database $db, int $tenantId, int $contactId): void
    {
        $db->run(
            "UPDATE campaign_recipients SET replied_at = ? WHERE tenant_id = ? AND contact_id = ? AND replied_at IS NULL
             AND message_id IS NOT NULL AND status IN ('submitted', 'sent', 'delivered', 'read') AND created_at >= ?",
            [now(), $tenantId, $contactId, gmdate('Y-m-d H:i:s', time() - 7 * 86400)]
        );
    }
}
