<?php

declare(strict_types=1);

namespace App\Tenancy;

use LogicException;

/**
 * Holds the tenant for the current request/job. Set exactly once; every
 * tenant-owned query reads the id from here, never from request input.
 */
final class TenantContext
{
    private ?int $tenantId = null;

    public function set(int $tenantId): void
    {
        if ($this->tenantId !== null && $this->tenantId !== $tenantId) {
            throw new LogicException('Tenant context cannot be switched within a request.');
        }
        $this->tenantId = $tenantId;
    }

    public function has(): bool
    {
        return $this->tenantId !== null;
    }

    public function id(): int
    {
        if ($this->tenantId === null) {
            throw new LogicException('No tenant in context — refusing to run tenant-scoped query.');
        }
        return $this->tenantId;
    }
}
