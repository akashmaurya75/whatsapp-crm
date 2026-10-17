<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Domain\Conversations\ConversationRepository;

/** All figures are computed from stored data for the current tenant (and the user's visibility). */
final class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $db = $this->app->db;
        $tid = $this->app->tenant->id();
        $to = $this->dateParam($request, 'to', time());
        $from = $this->dateParam($request, 'from', $to - 29 * 86400);
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $fromSql = gmdate('Y-m-d 00:00:00', $from);
        $toSql = gmdate('Y-m-d 23:59:59', $to);
        $accountId = (int) ($request->query['account'] ?? 0);
        $accFilter = $accountId > 0 ? ' AND whatsapp_account_id = ' . $accountId : '';

        $user = $this->user();
        [$vis, $visParams] = (new ConversationRepository($db, $this->app->tenant))->visibilityClause($user, $this->app->can('inbox.view_all'));
        $conv = $db->first(
            "SELECT COUNT(*) AS total,
                SUM(CASE WHEN cv.status = 'open' THEN 1 ELSE 0 END) AS open_count,
                SUM(CASE WHEN cv.status = 'open' AND cv.assigned_user_id IS NULL THEN 1 ELSE 0 END) AS unassigned,
                SUM(CASE WHEN cv.status = 'open' AND cv.last_inbound_at IS NOT NULL AND cv.last_inbound_at >= cv.last_message_at THEN 1 ELSE 0 END) AS awaiting
             FROM conversations cv WHERE cv.tenant_id = :tid AND $vis" . str_replace('whatsapp_account_id', 'cv.whatsapp_account_id', $accFilter),
            $visParams + ['tid' => $tid]
        );

        $msgs = $db->all(
            "SELECT direction, status, COUNT(*) AS n FROM messages
             WHERE tenant_id = ? AND created_at BETWEEN ? AND ?$accFilter GROUP BY direction, status",
            [$tid, $fromSql, $toSql]
        );
        $sent = 0; $received = 0; $byStatus = ['queued' => 0, 'submitted' => 0, 'sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0];
        foreach ($msgs as $m) {
            if ($m['direction'] === 'in') {
                $received += (int) $m['n'];
            } else {
                $sent += (int) $m['n'];
                $byStatus[$m['status']] = ($byStatus[$m['status']] ?? 0) + (int) $m['n'];
            }
        }

        // Average time from a customer message to the next outbound reply (sample of up to 500 in range).
        $pairs = $db->all(
            "SELECT m.created_at AS in_at,
                (SELECT MIN(o.created_at) FROM messages o WHERE o.conversation_id = m.conversation_id AND o.tenant_id = m.tenant_id
                   AND o.direction = 'out' AND o.id > m.id) AS reply_at
             FROM messages m WHERE m.tenant_id = ? AND m.direction = 'in' AND m.created_at BETWEEN ? AND ?" . str_replace('whatsapp_account_id', 'm.whatsapp_account_id', $accFilter) . '
             ORDER BY m.id DESC LIMIT 500',
            [$tid, $fromSql, $toSql]
        );
        $deltas = [];
        foreach ($pairs as $p) {
            if ($p['reply_at'] !== null) {
                $deltas[] = max(0, strtotime($p['reply_at'] . ' UTC') - strtotime($p['in_at'] . ' UTC'));
            }
        }
        $avgResponse = $deltas !== [] ? (int) round(array_sum($deltas) / count($deltas)) : null;

        $daily = $db->all(
            "SELECT substr(created_at, 1, 10) AS day, direction, COUNT(*) AS n FROM messages
             WHERE tenant_id = ? AND created_at BETWEEN ? AND ?$accFilter GROUP BY substr(created_at, 1, 10), direction ORDER BY day",
            [$tid, $fromSql, $toSql]
        );
        $series = [];
        for ($d = $from; $d <= $to; $d += 86400) {
            $series[gmdate('Y-m-d', $d)] = ['in' => 0, 'out' => 0];
        }
        foreach ($daily as $row) {
            if (isset($series[$row['day']])) {
                $series[$row['day']][$row['direction']] = (int) $row['n'];
            }
        }

        $today = gmdate('Y-m-d');
        return $this->app->render('dashboard/index', [
            'from' => gmdate('Y-m-d', $from), 'to' => gmdate('Y-m-d', $to), 'accountId' => $accountId,
            'contactsTotal' => (int) $db->scalar('SELECT COUNT(*) FROM contacts WHERE tenant_id = ? AND archived_at IS NULL', [$tid]),
            'contactsNew' => (int) $db->scalar('SELECT COUNT(*) FROM contacts WHERE tenant_id = ? AND created_at BETWEEN ? AND ?', [$tid, $fromSql, $toSql]),
            'optedOut' => (int) $db->scalar('SELECT COUNT(*) FROM contacts WHERE tenant_id = ? AND opted_out_at IS NOT NULL', [$tid]),
            'followUpsToday' => (int) $db->scalar('SELECT COUNT(*) FROM contacts WHERE tenant_id = ? AND archived_at IS NULL AND next_follow_up_at BETWEEN ? AND ?', [$tid, "$today 00:00:00", "$today 23:59:59"]),
            'leadStatuses' => $db->all('SELECT lead_status, COUNT(*) AS n FROM contacts WHERE tenant_id = ? AND archived_at IS NULL GROUP BY lead_status ORDER BY n DESC', [$tid]),
            'conv' => $conv,
            'sent' => $sent, 'received' => $received, 'byStatus' => $byStatus,
            'avgResponse' => $avgResponse, 'responseSample' => count($deltas),
            'series' => $series,
            'accounts' => $db->all('SELECT id, name, provider, status, last_synced_at, last_error, last_error_at FROM whatsapp_accounts WHERE tenant_id = ? ORDER BY name', [$tid]),
            'failedJobs' => (int) $db->scalar("SELECT COUNT(*) FROM failed_jobs WHERE payload LIKE ?", ['%"tenant_id":' . $tid . ',%']),
        ]);
    }

    private function dateParam(Request $request, string $key, int $default): int
    {
        $v = (string) ($request->query[$key] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && ($ts = strtotime($v . ' 00:00:00 UTC')) !== false) {
            return $ts;
        }
        return $default;
    }
}
