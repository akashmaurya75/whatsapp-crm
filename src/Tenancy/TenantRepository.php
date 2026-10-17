<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Core\Database;
use App\Core\HttpException;

/**
 * Base for all repositories of tenant-owned tables.
 * Every read/write is constrained by tenant_id from TenantContext.
 */
abstract class TenantRepository
{
    protected string $table;

    public function __construct(protected Database $db, protected TenantContext $tenant)
    {
        Database::assertIdentifier($this->table);
    }

    protected function tid(): int
    {
        return $this->tenant->id();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first("SELECT * FROM {$this->table} WHERE id = ? AND tenant_id = ?", [$id, $this->tid()]);
    }

    /** Like find(), but 404s — cross-tenant ids look identical to missing ones. */
    public function findOrFail(int $id): array
    {
        return $this->find($id) ?? throw HttpException::notFound();
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        unset($data['id']);
        $data['tenant_id'] = $this->tid();
        return $this->db->insert($this->table, $data);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): int
    {
        unset($data['id'], $data['tenant_id']);
        return $this->db->update($this->table, $data, ['id' => $id, 'tenant_id' => $this->tid()]);
    }

    public function delete(int $id): int
    {
        return $this->db->run("DELETE FROM {$this->table} WHERE id = ? AND tenant_id = ?", [$id, $this->tid()])->rowCount();
    }

    public function count(string $where = '1=1', array $params = []): int
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM {$this->table} WHERE tenant_id = ? AND ($where)",
            array_merge([$this->tid()], $params)
        );
    }
}
