<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

final class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $tid = $this->app->tenant->id();
        return $this->app->render('users/index', [
            'users' => $this->app->db->all(
                'SELECT u.id, u.name, u.email, u.status, u.last_login_at, u.totp_enabled_at, r.name AS role_name, u.role_id
                 FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.tenant_id = ? ORDER BY u.name',
                [$tid]
            ),
            'roles' => $this->roles(),
            'canManage' => $this->app->can('users.manage'),
        ]);
    }

    private function roles(): array
    {
        return $this->app->db->all('SELECT id, name, slug FROM roles WHERE tenant_id = ? ORDER BY name', [$this->app->tenant->id()]);
    }

    /** Owner role can only be granted by an owner. */
    private function assertRoleAssignable(int $roleId): array
    {
        $role = $this->app->db->first('SELECT * FROM roles WHERE id = ? AND tenant_id = ?', [$roleId, $this->app->tenant->id()]);
        if ($role === null) {
            throw HttpException::notFound();
        }
        $me = $this->app->db->scalar('SELECT slug FROM roles WHERE id = ?', [$this->user()['role_id']]);
        if ($role['slug'] === 'owner' && $me !== 'owner') {
            throw HttpException::forbidden();
        }
        return $role;
    }

    public function invite(Request $request): Response
    {
        $v = Validator::make($request->post, ['name' => 'required|string|max:160', 'email' => 'required|email|max:191', 'role_id' => 'required|int']);
        if ($v->fails()) {
            return $this->backWithErrors($request, $v->errors(), '/users');
        }
        $d = $v->validated();
        $email = strtolower((string) $d['email']);
        if ($this->app->db->scalar('SELECT 1 FROM users WHERE email = ?', [$email]) !== null) {
            return $this->backWithErrors($request, ['email' => 'A user with this email already exists.'], '/users');
        }
        $this->assertRoleAssignable((int) $d['role_id']);
        $plan = $this->app->db->first('SELECT p.max_agents FROM tenants t JOIN subscription_plans p ON p.id = t.plan_id WHERE t.id = ?', [$this->app->tenant->id()]);
        if ($plan !== null && $plan['max_agents'] !== null) {
            $count = (int) $this->app->db->scalar("SELECT COUNT(*) FROM users WHERE tenant_id = ? AND status <> 'suspended'", [$this->app->tenant->id()]);
            if ($count >= (int) $plan['max_agents']) {
                return $this->backWithErrors($request, ['email' => 'Your plan\'s user limit has been reached.'], '/users');
            }
        }
        $token = random_token(32);
        $id = $this->app->db->insert('users', [
            'tenant_id' => $this->app->tenant->id(), 'role_id' => (int) $d['role_id'], 'name' => $d['name'], 'email' => $email,
            'password_hash' => null, 'status' => 'invited', 'invite_token_hash' => hash('sha256', $token),
            'invite_expires_at' => gmdate('Y-m-d H:i:s', time() + 7 * 86400), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->app->mailer->send($email, 'You have been invited to WhatsApp CRM',
            "Hi {$d['name']},\n\nYou have been invited to join your team on WhatsApp CRM. Set your password within 7 days:\n\n" . url('/invite/' . $token));
        $this->app->auditLog('user.invited', 'user', $id, ['email' => $email, 'role_id' => (int) $d['role_id']]);
        $this->flash('success', 'Invitation sent to ' . $email . '.');
        return Response::redirect('/users');
    }

    public function update(Request $request): Response
    {
        $tid = $this->app->tenant->id();
        $target = $this->app->db->first('SELECT * FROM users WHERE id = ? AND tenant_id = ?', [$request->intParam('id'), $tid]) ?? throw HttpException::notFound();
        if ((int) $target['id'] === $this->app->userId()) {
            return $this->backWithErrors($request, ['user' => 'You cannot change your own role or status.'], '/users');
        }
        $targetRole = $this->app->db->scalar('SELECT slug FROM roles WHERE id = ?', [$target['role_id']]);
        $myRole = $this->app->db->scalar('SELECT slug FROM roles WHERE id = ?', [$this->user()['role_id']]);
        if ($targetRole === 'owner' && $myRole !== 'owner') {
            throw HttpException::forbidden();
        }
        $data = ['updated_at' => now()];
        $roleId = (int) ($request->post['role_id'] ?? 0);
        if ($roleId > 0 && $roleId !== (int) $target['role_id']) {
            $this->assertRoleAssignable($roleId);
            $data['role_id'] = $roleId;
        }
        $status = $request->str('status');
        if (in_array($status, ['active', 'suspended'], true) && $target['status'] !== 'invited') {
            $data['status'] = $status;
        }
        $this->app->db->update('users', $data, ['id' => $target['id'], 'tenant_id' => $tid]);
        if (isset($data['role_id']) || ($data['status'] ?? '') === 'suspended') {
            // Force re-login so new privileges apply immediately.
            $this->app->db->run('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$target['id']]);
        }
        if (($data['status'] ?? '') === 'suspended') {
            $this->app->db->run('UPDATE api_tokens SET revoked_at = ? WHERE user_id = ? AND tenant_id = ? AND revoked_at IS NULL', [now(), $target['id'], $tid]);
        }
        $this->app->auditLog('user.updated', 'user', (int) $target['id'], array_diff_key($data, ['updated_at' => 1]));
        $this->flash('success', 'User updated.');
        return Response::redirect('/users');
    }
}
