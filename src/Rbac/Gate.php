<?php

declare(strict_types=1);

namespace App\Rbac;

use App\Core\Database;
use App\Core\HttpException;

/** Permission checks for the authenticated tenant user. */
final class Gate
{
    /** @var array<string,true>|null */
    private ?array $granted = null;

    public function __construct(private Database $db, private ?array $user)
    {
    }

    public function can(string $permission): bool
    {
        if ($this->user === null || $this->user['tenant_id'] === null || $this->user['role_id'] === null) {
            return false;
        }
        if ($this->granted === null) {
            $rows = $this->db->all(
                'SELECT p.name FROM role_permissions rp
                 JOIN permissions p ON p.id = rp.permission_id
                 JOIN roles r ON r.id = rp.role_id
                 WHERE rp.role_id = ? AND r.tenant_id = ?',
                [$this->user['role_id'], $this->user['tenant_id']]
            );
            $this->granted = array_fill_keys(array_column($rows, 'name'), true);
        }
        return isset($this->granted[$permission]);
    }

    public function authorize(string $permission): void
    {
        if (!$this->can($permission)) {
            throw HttpException::forbidden();
        }
    }
}
