<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\PasswordPolicy;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Tenancy\TenantProvisioner;

final class AuthController extends Controller
{
    public function showLogin(Request $request): Response
    {
        return $this->app->render('auth/login', ['registration' => (bool) env('ALLOW_REGISTRATION', false)]);
    }

    public function login(Request $request): Response
    {
        $email = $request->str('email');
        $password = (string) ($request->post['password'] ?? '');
        if ($email === '' || $password === '') {
            return $this->backWithErrors($request, ['email' => 'Email and password are required.'], '/login');
        }
        $result = $this->app->auth->attempt($email, $password, $request->ip(), $request->userAgent());
        return match ($result['status']) {
            'ok' => $this->afterLogin(),
            'two_factor' => Response::redirect('/two-factor'),
            default => $this->backWithErrors($request, ['email' => $result['message'] ?? 'Login failed.'], '/login'),
        };
    }

    private function afterLogin(): Response
    {
        $user = $this->app->auth->user();
        if ($user !== null && $user['tenant_id'] === null) {
            return Response::redirect('/admin');
        }
        $intended = (string) $this->app->session->pull('intended', '/');
        return Response::redirect(str_starts_with($intended, '/') && !str_starts_with($intended, '//') ? $intended : '/');
    }

    public function showTwoFactor(Request $request): Response
    {
        if ($this->app->session->get('2fa_user_id') === null) {
            return Response::redirect('/login');
        }
        return $this->app->render('auth/two-factor');
    }

    public function twoFactor(Request $request): Response
    {
        if ($this->app->auth->verifyTwoFactor($request->str('code'), $request->ip(), $request->userAgent())) {
            return $this->afterLogin();
        }
        if ($this->app->session->get('2fa_user_id') === null) {
            $this->flash('error', 'Verification expired. Please sign in again.');
            return Response::redirect('/login');
        }
        return $this->backWithErrors($request, ['code' => 'Invalid or expired code.'], '/two-factor');
    }

    public function logout(Request $request): Response
    {
        $user = $this->app->auth->user();
        if ($user !== null) {
            $this->app->audit->record('auth.logout', $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null, (int) $user['id'], ip: $request->ip(), userAgent: $request->userAgent());
        }
        $this->app->auth->logout();
        return Response::redirect('/login');
    }

    public function showForgot(Request $request): Response
    {
        return $this->app->render('auth/forgot');
    }

    public function forgot(Request $request): Response
    {
        $email = $request->str('email');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) && $this->app->limiter->hit('reset:' . strtolower($email), 3, 3600)) {
            $token = $this->app->auth->createResetToken($email);
            if ($token !== null) {
                $this->app->mailer->send($email, 'Reset your WhatsApp CRM password',
                    "Use this link within 60 minutes to reset your password:\n\n" . url('/reset-password/' . $token)
                    . "\n\nIf you did not request this, you can ignore this email.");
            }
        }
        // Same response whether or not the account exists (no user enumeration).
        $this->flash('success', 'If that email is registered, a reset link has been sent.');
        return Response::redirect('/login');
    }

    public function showReset(Request $request): Response
    {
        if ($this->app->auth->findByResetToken($request->param('token')) === null) {
            throw new HttpException(404, 'This reset link is invalid or has expired.');
        }
        return $this->app->render('auth/reset', ['token' => $request->param('token'), 'title' => 'Choose a new password', 'action' => '/reset-password/']);
    }

    public function reset(Request $request): Response
    {
        $user = $this->app->auth->findByResetToken($request->param('token'));
        if ($user === null) {
            throw new HttpException(404, 'This reset link is invalid or has expired.');
        }
        $password = (string) ($request->post['password'] ?? '');
        if ($password !== (string) ($request->post['password_confirmation'] ?? '')) {
            return $this->backWithErrors($request, ['password' => 'Passwords do not match.']);
        }
        if (($err = PasswordPolicy::check($password, (string) $user['email'])) !== null) {
            return $this->backWithErrors($request, ['password' => $err]);
        }
        $this->app->auth->setPassword((int) $user['id'], $password);
        $this->app->audit->record('auth.password_reset', $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null, (int) $user['id'], ip: $request->ip());
        $this->flash('success', 'Password updated. Please sign in.');
        return Response::redirect('/login');
    }

    public function showInvite(Request $request): Response
    {
        $user = $this->app->auth->findByInviteToken($request->param('token'));
        if ($user === null) {
            throw new HttpException(404, 'This invitation is invalid or has expired.');
        }
        return $this->app->render('auth/reset', [
            'token' => $request->param('token'), 'title' => 'Welcome, ' . $user['name'] . ' — set your password',
            'action' => '/invite/',
        ]);
    }

    public function acceptInvite(Request $request): Response
    {
        $user = $this->app->auth->findByInviteToken($request->param('token'));
        if ($user === null) {
            throw new HttpException(404, 'This invitation is invalid or has expired.');
        }
        $password = (string) ($request->post['password'] ?? '');
        if ($password !== (string) ($request->post['password_confirmation'] ?? '')) {
            return $this->backWithErrors($request, ['password' => 'Passwords do not match.']);
        }
        if (($err = PasswordPolicy::check($password, (string) $user['email'])) !== null) {
            return $this->backWithErrors($request, ['password' => $err]);
        }
        $this->app->auth->setPassword((int) $user['id'], $password);
        $this->app->db->update('users', [
            'status' => 'active', 'invite_token_hash' => null, 'invite_expires_at' => null, 'email_verified_at' => now(),
        ], ['id' => $user['id']]);
        $this->app->audit->record('user.invite_accepted', (int) $user['tenant_id'], (int) $user['id'], ip: $request->ip());
        $this->flash('success', 'Your account is ready. Please sign in.');
        return Response::redirect('/login');
    }

    public function showRegister(Request $request): Response
    {
        if (!(bool) env('ALLOW_REGISTRATION', false)) {
            throw HttpException::notFound();
        }
        return $this->app->render('auth/register');
    }

    /** Self-service tenant sign-up (only when ALLOW_REGISTRATION=true). */
    public function register(Request $request): Response
    {
        if (!(bool) env('ALLOW_REGISTRATION', false)) {
            throw HttpException::notFound();
        }
        $v = Validator::make($request->post, [
            'company' => 'required|string|max:160', 'name' => 'required|string|max:160', 'email' => 'required|email|max:191',
        ]);
        $errors = $v->errors();
        $data = $v->validated();
        $password = (string) ($request->post['password'] ?? '');
        if (!isset($errors['email']) && $this->app->db->scalar('SELECT 1 FROM users WHERE email = ?', [strtolower((string) $data['email'])]) !== null) {
            $errors['email'] = 'This email is already registered.';
        }
        if (($err = PasswordPolicy::check($password, (string) ($data['email'] ?? ''))) !== null) {
            $errors['password'] = $err;
        }
        if ($errors !== []) {
            return $this->backWithErrors($request, $errors, '/register');
        }
        $planId = $this->app->db->scalar('SELECT id FROM subscription_plans WHERE is_active = 1 ORDER BY price_cents LIMIT 1');
        $ids = (new TenantProvisioner($this->app->db))->provision(
            (string) $data['company'], (string) $data['name'], (string) $data['email'], $password, $planId !== null ? (int) $planId : null
        );
        $this->app->audit->record('tenant.registered', $ids['tenant_id'], $ids['user_id'], 'tenant', $ids['tenant_id'], ip: $request->ip());
        $user = (array) $this->app->db->first('SELECT * FROM users WHERE id = ?', [$ids['user_id']]);
        $this->app->auth->login($user, $request->ip(), $request->userAgent());
        return Response::redirect('/');
    }
}
