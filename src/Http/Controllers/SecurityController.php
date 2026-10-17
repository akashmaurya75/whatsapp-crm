<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\PasswordPolicy;
use App\Auth\Totp;
use App\Core\Crypto;
use App\Core\Request;
use App\Core\Response;

/** The signed-in user's own security settings: password, 2FA, sessions. */
final class SecurityController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $this->user();
        $pending = $this->app->session->get('2fa_pending_secret');
        return $this->app->render('account/security', [
            'user' => $user,
            'pendingSecret' => is_string($pending) ? $pending : null,
            'otpUri' => is_string($pending) ? Totp::provisioningUri($pending, (string) $user['email'], (string) env('APP_NAME', 'WhatsApp CRM')) : null,
            'recoveryCodes' => $this->app->session->getFlash('recovery_codes'),
            'logins' => $this->app->db->all('SELECT ip, success, user_agent, created_at FROM login_attempts WHERE email = ? ORDER BY id DESC LIMIT 15', [$user['email']]),
        ]);
    }

    private function requirePassword(Request $request): bool
    {
        return password_verify((string) ($request->post['current_password'] ?? ''), (string) $this->user()['password_hash']);
    }

    public function changePassword(Request $request): Response
    {
        if (!$this->requirePassword($request)) {
            return $this->backWithErrors($request, ['current_password' => 'Current password is incorrect.'], '/account/security');
        }
        $new = (string) ($request->post['password'] ?? '');
        if ($new !== (string) ($request->post['password_confirmation'] ?? '')) {
            return $this->backWithErrors($request, ['password' => 'Passwords do not match.'], '/account/security');
        }
        if (($err = PasswordPolicy::check($new, (string) $this->user()['email'])) !== null) {
            return $this->backWithErrors($request, ['password' => $err], '/account/security');
        }
        $user = $this->user();
        $this->app->auth->setPassword((int) $user['id'], $new); // bumps session_version → other sessions end
        $this->app->auditLog('auth.password_changed', 'user', (int) $user['id']);
        $fresh = (array) $this->app->db->first('SELECT * FROM users WHERE id = ?', [$user['id']]);
        $this->app->auth->login($fresh, $request->ip(), $request->userAgent());
        $this->flash('success', 'Password changed. Other sessions were signed out.');
        return Response::redirect('/account/security');
    }

    public function startTwoFactor(Request $request): Response
    {
        $this->app->session->put('2fa_pending_secret', Totp::generateSecret());
        return Response::redirect('/account/security');
    }

    public function confirmTwoFactor(Request $request): Response
    {
        $secret = $this->app->session->get('2fa_pending_secret');
        if (!is_string($secret) || !Totp::verify($secret, $request->str('code'))) {
            return $this->backWithErrors($request, ['code' => 'That code is not valid. Check your authenticator app\'s time and try again.'], '/account/security');
        }
        $user = $this->user();
        $this->app->db->update('users', ['totp_secret_enc' => Crypto::encrypt($secret), 'totp_enabled_at' => now()], ['id' => $user['id']]);
        $this->app->session->forget('2fa_pending_secret');
        $this->app->session->flash('recovery_codes', $this->app->auth->generateRecoveryCodes((int) $user['id']));
        $this->app->auditLog('auth.2fa_enabled', 'user', (int) $user['id']);
        $this->flash('success', 'Two-factor authentication is on. Store your recovery codes safely.');
        return Response::redirect('/account/security');
    }

    public function disableTwoFactor(Request $request): Response
    {
        if (!$this->requirePassword($request)) {
            return $this->backWithErrors($request, ['current_password' => 'Current password is incorrect.'], '/account/security');
        }
        $user = $this->user();
        $this->app->db->update('users', ['totp_secret_enc' => null, 'totp_enabled_at' => null, 'recovery_codes' => null], ['id' => $user['id']]);
        $this->app->auditLog('auth.2fa_disabled', 'user', (int) $user['id']);
        $this->flash('success', 'Two-factor authentication disabled.');
        return Response::redirect('/account/security');
    }

    public function logoutEverywhere(Request $request): Response
    {
        $user = $this->user();
        $this->app->auditLog('auth.logout_all', 'user', (int) $user['id']);
        $this->app->auth->logoutEverywhere((int) $user['id']);
        return Response::redirect('/login');
    }
}
