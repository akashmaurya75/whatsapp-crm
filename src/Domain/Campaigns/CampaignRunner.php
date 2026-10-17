<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

use App\Core\App;
use App\Core\Logger;
use App\Domain\Conversations\InboxService;
use App\Domain\Templates\TemplateService;
use App\Domain\ValidationException;
use App\Tenancy\TenantContext;

/**
 * Drives campaigns forward. Called by CampaignTickJob and every minute by `schedule:run`.
 *  1. Starts due "scheduled" campaigns: snapshots the audience into campaign_recipients
 *     (eligible → pending, excluded → skipped with a reason).
 *  2. For "running" campaigns: queues at most rate_per_minute messages per minute.
 * Safe to call concurrently: status changes and batch claims are atomic UPDATEs.
 */
final class CampaignRunner
{
    /** @return array{started:int,queued:int,completed:int} */
    public static function tick(App $app, ?int $onlyCampaignId = null): array
    {
        $db = $app->db;
        $stats = ['started' => 0, 'queued' => 0, 'completed' => 0];
        $filter = $onlyCampaignId !== null ? ' AND c.id = ' . (int) $onlyCampaignId : '';
        $due = $db->all("SELECT c.id, c.tenant_id FROM campaigns c JOIN tenants t ON t.id = c.tenant_id
            WHERE c.status = 'scheduled' AND c.scheduled_at <= ? AND t.status = 'active'$filter", [now()]);
        foreach ($due as $row) {
            $claimed = $db->run("UPDATE campaigns SET status = 'running', started_at = ?, updated_at = ? WHERE id = ? AND status = 'scheduled'",
                [now(), now(), $row['id']])->rowCount();
            if ($claimed === 1) {
                self::start($app, (int) $row['tenant_id'], (int) $row['id']);
                $stats['started']++;
            }
        }
        $running = $db->all("SELECT c.id, c.tenant_id FROM campaigns c JOIN tenants t ON t.id = c.tenant_id
            WHERE c.status = 'running' AND t.status = 'active'$filter");
        foreach ($running as $row) {
            [$q, $done] = self::batch($app, (int) $row['tenant_id'], (int) $row['id']);
            $stats['queued'] += $q;
            $stats['completed'] += $done ? 1 : 0;
        }
        return $stats;
    }

    private static function service(App $app, int $tenantId): CampaignService
    {
        $ctx = new TenantContext();
        $ctx->set($tenantId);
        return new CampaignService($app->db, $ctx, $app->queue, $app->providers);
    }

    /** Snapshot the audience. Idempotent: contacts already listed are not added twice. */
    public static function start(App $app, int $tenantId, int $campaignId): void
    {
        $svc = self::service($app, $tenantId);
        $c = (array) $svc->repo->find($campaignId);
        [$where, $wp] = $svc->audienceSql($c);
        [$case1, $x1] = $svc->exclusionSql($c, 'x');
        [$case2, $x2] = $svc->exclusionSql($c, 'y');
        $app->db->run(
            "INSERT INTO campaign_recipients (tenant_id, campaign_id, contact_id, status, skip_reason, created_at, updated_at)
             SELECT :rtid, :rcid, c.id, CASE WHEN ($case1) IS NULL THEN 'pending' ELSE 'skipped' END, $case2, :rnow1, :rnow2
             FROM contacts c WHERE $where
             AND NOT EXISTS (SELECT 1 FROM campaign_recipients r WHERE r.campaign_id = :rcid2 AND r.contact_id = c.id)",
            $wp + $x1 + $x2 + ['rtid' => $tenantId, 'rcid' => $campaignId, 'rcid2' => $campaignId, 'rnow1' => now(), 'rnow2' => now()]
        );
        $counts = $app->db->first("SELECT SUM(CASE WHEN status = 'skipped' THEN 0 ELSE 1 END) AS total,
            SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END) AS skipped FROM campaign_recipients WHERE campaign_id = ?", [$campaignId]);
        $svc->repo->update($campaignId, ['total_recipients' => (int) ($counts['total'] ?? 0), 'skipped_count' => (int) ($counts['skipped'] ?? 0), 'updated_at' => now()]);
        Logger::info('Campaign started', ['campaign_id' => $campaignId, 'recipients' => (int) ($counts['total'] ?? 0)]);
    }

    /** @return array{0:int,1:bool} [messages queued, campaign completed] */
    public static function batch(App $app, int $tenantId, int $campaignId): array
    {
        $db = $app->db;
        // Claim this minute's batch (prevents double sending when several tickers run).
        $claimed = $db->run("UPDATE campaigns SET last_batch_at = ? WHERE id = ? AND status = 'running'
            AND (last_batch_at IS NULL OR last_batch_at <= ?)", [now(), $campaignId, gmdate('Y-m-d H:i:s', time() - 55)])->rowCount();
        if ($claimed !== 1) {
            return [0, false];
        }
        $svc = self::service($app, $tenantId);
        $c = (array) $svc->repo->find($campaignId);
        $template = $svc->template($c);
        if (($why = TemplateService::unsendableReason($template)) !== null) {
            $svc->repo->update($campaignId, ['status' => 'failed', 'last_error' => mb_substr('Stopped: ' . $why, 0, 500), 'updated_at' => now()]);
            return [0, false];
        }
        $map = CampaignService::variableMap($c);
        $media = $svc->media($c);
        $inbox = new InboxService($db, self::ctx($tenantId), $app->queue);
        $rows = $db->all(
            "SELECT r.id AS rid, c.* FROM campaign_recipients r JOIN contacts c ON c.id = r.contact_id AND c.tenant_id = r.tenant_id
             WHERE r.campaign_id = ? AND r.tenant_id = ? AND r.status = 'pending' ORDER BY r.id LIMIT " . max(1, (int) $c['rate_per_minute']),
            [$campaignId, $tenantId]
        );
        $queued = 0;
        foreach ($rows as $contact) {
            $rid = (int) $contact['rid'];
            if ($contact['opted_out_at'] !== null) {
                $db->update('campaign_recipients', ['status' => 'skipped', 'skip_reason' => 'opted_out', 'updated_at' => now()], ['id' => $rid]);
                continue;
            }
            try {
                $existing = $db->first('SELECT * FROM conversations WHERE tenant_id = ? AND contact_id = ? AND whatsapp_account_id = ?',
                    [$tenantId, $contact['id'], $c['whatsapp_account_id']]);
                $conv = $existing ?? $inbox->findOrCreateConversation((int) $contact['id'], (int) $c['whatsapp_account_id']);
                if ($existing === null) {
                    // New thread started by a broadcast: keep it out of the agents' open queue until the customer replies.
                    $db->update('conversations', ['status' => 'closed', 'closed_at' => now()], ['id' => (int) $conv['id'], 'tenant_id' => $tenantId]);
                }
                $msg = $inbox->queueTemplate($conv, null, $template, TemplateService::resolve($map, $contact), $media,
                    'camp-' . $campaignId . '-' . $contact['id'], $campaignId);
                $db->update('campaign_recipients', ['status' => (string) $msg['status'], 'message_id' => (int) $msg['id'], 'updated_at' => now()], ['id' => $rid]);
                $queued++;
            } catch (ValidationException $e) {
                $db->update('campaign_recipients', ['status' => 'failed', 'skip_reason' => 'error',
                    'error' => mb_substr($e->getMessage(), 0, 500), 'updated_at' => now()], ['id' => $rid]);
            }
        }
        // Completed once nothing is waiting: no pending recipients and no messages still queued for sending
        // (so a campaign can still be paused/cancelled while its last batch is going out).
        $left = (int) $db->scalar("SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = ? AND status IN ('pending', 'queued')", [$campaignId]);
        if ($left === 0) {
            $db->run("UPDATE campaigns SET status = 'completed', completed_at = ?, updated_at = ? WHERE id = ? AND status = 'running'",
                [now(), now(), $campaignId]);
            return [$queued, true];
        }
        return [$queued, false];
    }

    private static function ctx(int $tenantId): TenantContext
    {
        $ctx = new TenantContext();
        $ctx->set($tenantId);
        return $ctx;
    }
}
