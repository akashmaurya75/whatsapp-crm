<?php

declare(strict_types=1);

namespace App\Domain\Conversations;

use App\Tenancy\TenantRepository;

final class ConversationRepository extends TenantRepository
{
    protected string $table = 'conversations';

    /**
     * SQL fragment restricting conversations to what $user may see.
     * Users without inbox.view_all see: assigned to them, assigned to one of their teams, or unassigned.
     * @return array{0:string,1:array}
     */
    public function visibilityClause(array $user, bool $canViewAll): array
    {
        if ($canViewAll) {
            return ['1=1', []];
        }
        return [
            '(cv.assigned_user_id = :vis_uid
              OR (cv.assigned_user_id IS NULL AND cv.assigned_team_id IS NULL)
              OR cv.assigned_team_id IN (SELECT team_id FROM team_members WHERE user_id = :vis_uid2 AND tenant_id = :vis_tid))',
            ['vis_uid' => (int) $user['id'], 'vis_uid2' => (int) $user['id'], 'vis_tid' => $this->tid()],
        ];
    }

    /**
     * @param array{q?:string,status?:string,assigned?:string,account?:int} $filters
     * @return array{data:list<array>,total:int}
     */
    public function inbox(array $user, bool $canViewAll, array $filters, int $page = 1, int $perPage = 30): array
    {
        [$vis, $params] = $this->visibilityClause($user, $canViewAll);
        $where = ['cv.tenant_id = :tid', $vis, 'cv.is_archived = :archived'];
        $params += ['tid' => $this->tid(), 'archived' => ($filters['status'] ?? '') === 'archived' ? 1 : 0];
        $status = (string) ($filters['status'] ?? 'open');
        if (in_array($status, ['open', 'closed'], true)) {
            $where[] = 'cv.status = :status';
            $params['status'] = $status;
        }
        $assigned = (string) ($filters['assigned'] ?? '');
        if ($assigned === 'me') {
            $where[] = 'cv.assigned_user_id = :me';
            $params['me'] = (int) $user['id'];
        } elseif ($assigned === 'unassigned') {
            $where[] = 'cv.assigned_user_id IS NULL';
        } elseif ($assigned === 'awaiting') {
            $where[] = 'cv.last_inbound_at IS NOT NULL AND cv.last_inbound_at >= cv.last_message_at';
        }
        if (!empty($filters['account'])) {
            $where[] = 'cv.whatsapp_account_id = :acc';
            $params['acc'] = (int) $filters['account'];
        }
        if (($q = trim((string) ($filters['q'] ?? ''))) !== '') {
            $like = '%' . like_escape($q) . '%';
            $where[] = "(c.name LIKE :q1 ESCAPE '!' OR c.phone_e164 LIKE :q2 ESCAPE '!'
                OR EXISTS (SELECT 1 FROM messages m WHERE m.conversation_id = cv.id AND m.tenant_id = cv.tenant_id AND m.body LIKE :q3 ESCAPE '!'))";
            $params += ['q1' => $like, 'q2' => $like, 'q3' => $like];
        }
        $whereSql = implode(' AND ', $where);
        $from = 'FROM conversations cv JOIN contacts c ON c.id = cv.contact_id AND c.tenant_id = cv.tenant_id
                 JOIN whatsapp_accounts a ON a.id = cv.whatsapp_account_id AND a.tenant_id = cv.tenant_id
                 LEFT JOIN users u ON u.id = cv.assigned_user_id';
        $total = (int) $this->db->scalar("SELECT COUNT(*) $from WHERE $whereSql", $params);
        $perPage = max(1, min(100, $perPage));
        $offset = (max(1, $page) - 1) * $perPage;
        $rows = $this->db->all(
            "SELECT cv.*, c.name AS contact_name, c.phone_e164, a.name AS account_name, a.provider, u.name AS assignee_name
             $from WHERE $whereSql ORDER BY cv.is_pinned DESC, cv.last_message_at DESC, cv.id DESC LIMIT $perPage OFFSET $offset",
            $params
        );
        return ['data' => $rows, 'total' => $total];
    }

    /** Find a conversation the user is allowed to see (404 otherwise). */
    public function findVisible(int $id, array $user, bool $canViewAll): ?array
    {
        [$vis, $params] = $this->visibilityClause($user, $canViewAll);
        return $this->db->first(
            "SELECT cv.*, c.name AS contact_name, c.phone_e164, c.opted_out_at, c.email AS contact_email,
                    c.company AS contact_company, c.lead_status, a.name AS account_name, a.provider, a.capabilities
             FROM conversations cv
             JOIN contacts c ON c.id = cv.contact_id AND c.tenant_id = cv.tenant_id
             JOIN whatsapp_accounts a ON a.id = cv.whatsapp_account_id AND a.tenant_id = cv.tenant_id
             WHERE cv.id = :id AND cv.tenant_id = :tid AND $vis",
            $params + ['id' => $id, 'tid' => $this->tid()]
        );
    }

    /** @return list<array> newest last; $beforeId paginates older history */
    public function messages(int $conversationId, int $limit = 50, ?int $beforeId = null): array
    {
        $params = [$conversationId, $this->tid()];
        $older = '';
        if ($beforeId !== null) {
            $older = 'AND m.id < ?';
            $params[] = $beforeId;
        }
        $rows = $this->db->all(
            "SELECT m.*, u.name AS sender_name FROM messages m LEFT JOIN users u ON u.id = m.sent_by_user_id
             WHERE m.conversation_id = ? AND m.tenant_id = ? $older ORDER BY m.id DESC LIMIT " . max(1, min(200, $limit)),
            $params
        );
        return array_reverse($rows);
    }
}
