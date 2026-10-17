<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\ApiTokenService;
use App\Core\Request;
use App\Core\Response;

final class ApiTokenController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->app->render('settings/api-tokens', [
            'tokens' => $this->app->db->all(
                'SELECT t.id, t.name, t.created_at, t.last_used_at, t.expires_at, t.revoked_at, u.name AS user_name
                 FROM api_tokens t JOIN users u ON u.id = t.user_id WHERE t.tenant_id = ? ORDER BY t.id DESC',
                [$this->app->tenant->id()]
            ),
            'newToken' => $this->app->session->getFlash('new_token'),
        ]);
    }

    public function store(Request $request): Response
    {
        $name = mb_substr($request->str('name'), 0, 100);
        $days = (int) ($request->post['ttl_days'] ?? 0);
        if ($name === '') {
            return $this->backWithErrors($request, ['name' => 'Give the token a name.'], '/settings/api-tokens');
        }
        $token = (new ApiTokenService($this->app->db))->create($this->app->tenant->id(), (int) $this->app->userId(), $name, $days > 0 ? min($days, 3650) : null);
        $this->app->auditLog('api_token.created', 'api_token', $token['id'], ['name' => $name]);
        $this->app->session->flash('new_token', $token['token']);
        return Response::redirect('/settings/api-tokens');
    }

    public function revoke(Request $request): Response
    {
        if ((new ApiTokenService($this->app->db))->revoke($this->app->tenant->id(), $request->intParam('id'))) {
            $this->app->auditLog('api_token.revoked', 'api_token', $request->intParam('id'));
            $this->flash('success', 'Token revoked.');
        }
        return Response::redirect('/settings/api-tokens');
    }
}
