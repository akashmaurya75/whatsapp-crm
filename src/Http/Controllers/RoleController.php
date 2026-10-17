<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Rbac\Permissions;

final class RoleController extends Controller
{
    public function index(Request $request): Response
    {
        $tid = $this->app->tenant->id();
        $roles = $this->app->db->all(
            'SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS user_count FROM roles r WHERE r.tenant_id = ? ORDER BY r.is_system DESC, r.name',
            [$tid]
        );
        $grants = [];
        foreach ($this->app->db->all(
            'SELECT rp.role_id, p.name FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id JOIN roles r ON r.id = rp.role_id WHERE r.tenant_id = ?',
            [$tid]
        ) as $g) {
            $grants[$g['role_id']][$g['name']] = true;
        }
        return $this->app->render('roles/index', ['roles' => $roles, 'grants' => $grants, 'permissions' => Permissions::ALL]);
    }

    public function store(Request $request): Response
    {
        $name = mb_substr($request->str('name'), 0, 100);
        if ($name === '') {
            return $this->backWithErrors($request, ['name' => 'Role name is required.'], '/roles');
        }
        $slug = 'custom-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $id = $this->app->db->insert('roles', [
            'tenant_id' => $this->app->tenant->id(), 'name' => $name, 'slug' => $slug, 'is_system' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->app->auditLog('role.created', 'role', $id, ['name' => $name]);
        $this->flash('success', 'Role created. Choose its permissions below.');
        return Response::redirect('/roles#role-' . $id);
    }

    public function update(Request $request): Response
    {
        $tid = $this->app->tenant->id();
        $role = $this->app->db->first('SELECT * FROM roles WHERE id = ? AND tenant_id = ?', [$request->intParam('id'), $tid]) ?? throw HttpException::notFound();
        if ($role['slug'] === 'owner') {
            return $this->backWithErrors($request, ['role' => 'The Company Owner role always has every permission.'], '/roles');
        }
        $selected = array_values(array_intersect(array_keys(Permissions::ALL), array_keys((array) ($request->post['permissions'] ?? []))));
        $this->app->db->transaction(function () use ($role, $selected): void {
            $this->app->db->run('DELETE FROM role_permissions WHERE role_id = ?', [$role['id']]);
            foreach ($selected as $perm) {
                $pid = (int) $this->app->db->scalar('SELECT id FROM permissions WHERE name = ?', [$perm]);
                $this->app->db->insert('role_permissions', ['role_id' => (int) $role['id'], 'permission_id' => $pid]);
            }
        });
        $this->app->auditLog('role.permissions_updated', 'role', (int) $role['id'], ['permissions' => $selected]);
        $this->flash('success', 'Permissions saved for ' . $role['name'] . '.');
        return Response::redirect('/roles#role-' . $role['id']);
    }
}
