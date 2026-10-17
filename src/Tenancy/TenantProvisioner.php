<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Auth\PasswordPolicy;
use App\Core\Database;
use App\Rbac\Permissions;

/** Creates a tenant with its default roles and an owner user, atomically. */
final class TenantProvisioner
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Ensures every catalogue permission exists. Permissions added by an upgrade are also granted
     * to existing companies' default (system) roles that include them, so upgrades need no manual step.
     */
    public static function syncPermissions(Database $db): void
    {
        $defaults = Permissions::defaultRoles();
        foreach (Permissions::ALL as $name => $description) {
            if ($db->scalar('SELECT id FROM permissions WHERE name = ?', [$name]) !== null) {
                continue;
            }
            $permId = $db->insert('permissions', ['name' => $name, 'description' => $description]);
            foreach ($db->all('SELECT id, slug FROM roles WHERE is_system = 1') as $role) {
                if (in_array($name, $defaults[$role['slug']]['permissions'] ?? [], true)) {
                    $db->insert('role_permissions', ['role_id' => (int) $role['id'], 'permission_id' => $permId]);
                }
            }
        }
    }

    /** @return array{tenant_id:int,user_id:int} */
    public function provision(string $companyName, string $ownerName, string $ownerEmail, string $ownerPassword, ?int $planId = null): array
    {
        return $this->db->transaction(function () use ($companyName, $ownerName, $ownerEmail, $ownerPassword, $planId): array {
            self::syncPermissions($this->db);
            $slugBase = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($companyName)), '-') ?: 'company';
            $slug = $slugBase;
            for ($i = 2; $this->db->scalar('SELECT 1 FROM tenants WHERE slug = ?', [$slug]) !== null; $i++) {
                $slug = $slugBase . '-' . $i;
            }
            $tenantId = $this->db->insert('tenants', [
                'name' => $companyName, 'slug' => mb_substr($slug, 0, 80), 'status' => 'active',
                'plan_id' => $planId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($planId !== null) {
                $this->db->insert('subscriptions', [
                    'tenant_id' => $tenantId, 'plan_id' => $planId, 'status' => 'trialing',
                    'trial_ends_at' => gmdate('Y-m-d H:i:s', time() + 14 * 86400),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $permIds = [];
            foreach ($this->db->all('SELECT id, name FROM permissions') as $p) {
                $permIds[$p['name']] = (int) $p['id'];
            }
            $ownerRoleId = 0;
            foreach (Permissions::defaultRoles() as $slugKey => $role) {
                $roleId = $this->db->insert('roles', [
                    'tenant_id' => $tenantId, 'name' => $role['name'], 'slug' => $slugKey, 'is_system' => 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach ($role['permissions'] as $perm) {
                    $this->db->insert('role_permissions', ['role_id' => $roleId, 'permission_id' => $permIds[$perm]]);
                }
                if ($slugKey === 'owner') {
                    $ownerRoleId = $roleId;
                }
            }
            $userId = $this->db->insert('users', [
                'tenant_id' => $tenantId, 'role_id' => $ownerRoleId, 'name' => $ownerName,
                'email' => strtolower(trim($ownerEmail)), 'password_hash' => PasswordPolicy::hash($ownerPassword),
                'status' => 'active', 'email_verified_at' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            return ['tenant_id' => $tenantId, 'user_id' => $userId];
        });
    }
}
