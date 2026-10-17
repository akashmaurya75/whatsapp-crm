<?php

declare(strict_types=1);

namespace App\Domain\Contacts;

use App\Tenancy\TenantRepository;

final class ContactRepository extends TenantRepository
{
    protected string $table = 'contacts';

    public const SORTS = ['name', 'created_at', 'last_contacted_at', 'lead_status'];

    /**
     * @param array{q?:string,status?:string,tag?:string,archived?:bool,sort?:string,dir?:string} $filters
     * @return array{data:list<array>,total:int}
     */
    public function search(array $filters, int $page = 1, int $perPage = 25): array
    {
        $where = ['c.tenant_id = :tid'];
        $params = ['tid' => $this->tid()];
        $where[] = !empty($filters['archived']) ? 'c.archived_at IS NOT NULL' : 'c.archived_at IS NULL';
        if (($q = trim((string) ($filters['q'] ?? ''))) !== '') {
            $like = '%' . like_escape($q) . '%';
            $where[] = "(c.name LIKE :q1 ESCAPE '!' OR c.phone_e164 LIKE :q2 ESCAPE '!' OR c.email LIKE :q3 ESCAPE '!' OR c.company LIKE :q4 ESCAPE '!')";
            $params += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like];
        }
        if (($status = (string) ($filters['status'] ?? '')) !== '') {
            $where[] = 'c.lead_status = :status';
            $params['status'] = $status;
        }
        if (($tag = (string) ($filters['tag'] ?? '')) !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM contact_tags ct JOIN tags t ON t.id = ct.tag_id WHERE ct.contact_id = c.id AND t.tenant_id = :tid2 AND t.name = :tag)';
            $params += ['tid2' => $this->tid(), 'tag' => $tag];
        }
        $sort = in_array($filters['sort'] ?? '', self::SORTS, true) ? $filters['sort'] : 'created_at';
        $dir = strtolower((string) ($filters['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $whereSql = implode(' AND ', $where);
        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM contacts c WHERE $whereSql", $params);
        $perPage = max(1, min(100, $perPage));
        $offset = (max(1, $page) - 1) * $perPage;
        $rows = $this->db->all(
            "SELECT c.*, u.name AS owner_name FROM contacts c
             LEFT JOIN users u ON u.id = c.owner_user_id AND u.tenant_id = c.tenant_id
             WHERE $whereSql ORDER BY c.$sort $dir, c.id DESC LIMIT $perPage OFFSET $offset",
            $params
        );
        return ['data' => $rows, 'total' => $total];
    }

    public function findByPhone(string $e164): ?array
    {
        return $this->db->first('SELECT * FROM contacts WHERE tenant_id = ? AND phone_e164 = ?', [$this->tid(), $e164]);
    }

    /** @return list<string> */
    public function tags(int $contactId): array
    {
        return array_column($this->db->all(
            'SELECT t.name FROM contact_tags ct JOIN tags t ON t.id = ct.tag_id
             WHERE ct.contact_id = ? AND ct.tenant_id = ? ORDER BY t.name',
            [$contactId, $this->tid()]
        ), 'name');
    }

    /** @param list<string> $names */
    public function syncTags(int $contactId, array $names): void
    {
        $tid = $this->tid();
        $this->db->transaction(function () use ($contactId, $names, $tid): void {
            $this->db->run('DELETE FROM contact_tags WHERE contact_id = ? AND tenant_id = ?', [$contactId, $tid]);
            foreach (array_unique($names) as $name) {
                $name = mb_substr(trim($name), 0, 60);
                if ($name === '') {
                    continue;
                }
                $tagId = $this->db->scalar('SELECT id FROM tags WHERE tenant_id = ? AND name = ?', [$tid, $name]);
                if ($tagId === null) {
                    $tagId = $this->db->insert('tags', ['tenant_id' => $tid, 'name' => $name]);
                }
                $this->db->insert('contact_tags', ['contact_id' => $contactId, 'tag_id' => (int) $tagId, 'tenant_id' => $tid]);
            }
        });
    }

    /** @return list<string> */
    public function allTagNames(): array
    {
        return array_column($this->db->all('SELECT name FROM tags WHERE tenant_id = ? ORDER BY name', [$this->tid()]), 'name');
    }

    /** @return list<array> */
    public function notes(int $contactId): array
    {
        return $this->db->all(
            'SELECT n.*, u.name AS author FROM notes n LEFT JOIN users u ON u.id = n.user_id
             WHERE n.contact_id = ? AND n.tenant_id = ? ORDER BY n.id DESC LIMIT 100',
            [$contactId, $this->tid()]
        );
    }

    public function addNote(int $contactId, ?int $conversationId, ?int $userId, string $body): int
    {
        return $this->db->insert('notes', [
            'tenant_id' => $this->tid(), 'contact_id' => $contactId, 'conversation_id' => $conversationId,
            'user_id' => $userId, 'body' => $body, 'created_at' => now(),
        ]);
    }
}
