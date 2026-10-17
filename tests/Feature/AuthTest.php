<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\Totp;
use App\Core\Crypto;
use App\Core\Session;
use Tests\TestCase;

final class AuthTest extends TestCase
{
    public function testGuestIsRedirectedToLogin(): void
    {
        $r = $this->web('GET', '/contacts');
        $this->assertStatus(302, $r);
        $this->assertSame('/login', $r->headers['Location']);
    }

    public function testLoginWithValidCredentials(): void
    {
        $this->tenant('Acme', 'owner@acme.test');
        $session = Session::memory();
        $r = $this->web('POST', '/login', $session, ['email' => 'OWNER@acme.test', 'password' => 'Correct-horse-42']);
        $this->assertStatus(302, $r);
        $this->assertSame('/', $r->headers['Location']);
        $this->assertStatus(200, $this->web('GET', '/', $session));
        $this->assertSame(1, (int) $this->db->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'auth.login'"));
    }

    public function testWrongPasswordIsRejectedAndThrottled(): void
    {
        $this->tenant('Acme', 'owner@acme.test');
        for ($i = 0; $i < 5; $i++) {
            $session = Session::memory();
            $this->web('POST', '/login', $session, ['email' => 'owner@acme.test', 'password' => 'wrong-password-1']);
            $this->assertSame(null, $session->get('user_id'));
        }
        // Even the correct password is refused while throttled.
        $session = Session::memory();
        $this->web('POST', '/login', $session, ['email' => 'owner@acme.test', 'password' => 'Correct-horse-42']);
        $this->assertSame(null, $session->get('user_id'), 'throttled login must not authenticate');
        $errors = $session->get('_flash.errors');
        $this->assertContains('Too many login attempts', (string) ($errors['email'] ?? ''));
    }

    public function testCsrfTokenRequiredForPost(): void
    {
        $this->tenant('Acme', 'owner@acme.test');
        $r = $this->web('POST', '/login', null, ['email' => 'owner@acme.test', 'password' => 'Correct-horse-42'], csrf: false);
        $this->assertStatus(419, $r);
    }

    public function testSuspendedTenantCannotLoginAndSessionsEnd(): void
    {
        $t = $this->tenant('Acme', 'owner@acme.test');
        $session = $this->sessionFor($t['user_id']);
        $this->assertStatus(200, $this->web('GET', '/', $session));
        $this->db->update('tenants', ['status' => 'suspended'], ['id' => $t['tenant_id']]);
        $this->assertStatus(302, $this->web('GET', '/', $session), 'existing session must end');
        $s2 = Session::memory();
        $this->web('POST', '/login', $s2, ['email' => 'owner@acme.test', 'password' => 'Correct-horse-42']);
        $this->assertSame(null, $s2->get('user_id'));
    }

    public function testLogoutEverywhereInvalidatesOtherSessions(): void
    {
        $t = $this->tenant('Acme', 'owner@acme.test');
        $a = $this->sessionFor($t['user_id']);
        $b = $this->sessionFor($t['user_id']);
        $this->web('POST', '/account/logout-all', $a);
        $this->assertStatus(302, $this->web('GET', '/', $b));
        $this->assertSame('/login', $this->web('GET', '/', $b)->headers['Location']);
    }

    public function testTwoFactorFlow(): void
    {
        $t = $this->tenant('Acme', 'owner@acme.test');
        $secret = Totp::generateSecret();
        $this->db->update('users', ['totp_secret_enc' => Crypto::encrypt($secret), 'totp_enabled_at' => now()], ['id' => $t['user_id']]);
        $session = Session::memory();
        $r = $this->web('POST', '/login', $session, ['email' => 'owner@acme.test', 'password' => 'Correct-horse-42']);
        $this->assertSame('/two-factor', $r->headers['Location']);
        $this->assertSame(null, $session->get('user_id'), 'password alone must not log in');
        $this->web('POST', '/two-factor', $session, ['code' => '000000']);
        $this->assertSame(null, $session->get('user_id'));
        $this->web('POST', '/two-factor', $session, ['code' => Totp::code($secret)]);
        $this->assertSame($t['user_id'], $session->get('user_id'));
    }

    public function testRecoveryCodeWorksOnce(): void
    {
        $t = $this->tenant('Acme', 'owner@acme.test');
        $secret = Totp::generateSecret();
        $this->db->update('users', ['totp_secret_enc' => Crypto::encrypt($secret), 'totp_enabled_at' => now()], ['id' => $t['user_id']]);
        $codes = $this->app()->auth->generateRecoveryCodes($t['user_id']);
        $s = Session::memory();
        $this->web('POST', '/login', $s, ['email' => 'owner@acme.test', 'password' => 'Correct-horse-42']);
        $this->web('POST', '/two-factor', $s, ['code' => $codes[0]]);
        $this->assertSame($t['user_id'], $s->get('user_id'));
        $s2 = Session::memory();
        $this->web('POST', '/login', $s2, ['email' => 'owner@acme.test', 'password' => 'Correct-horse-42']);
        $this->web('POST', '/two-factor', $s2, ['code' => $codes[0]]);
        $this->assertSame(null, $s2->get('user_id'), 'recovery code must be single-use');
    }

    public function testPasswordResetDoesNotRevealAccountsAndTokenWorks(): void
    {
        $t = $this->tenant('Acme', 'owner@acme.test');
        $r1 = $this->web('POST', '/forgot-password', null, ['email' => 'nobody@acme.test']);
        $r2 = $this->web('POST', '/forgot-password', null, ['email' => 'owner@acme.test']);
        $this->assertSame($r1->headers['Location'], $r2->headers['Location']);
        $token = $this->app()->auth->createResetToken('owner@acme.test');
        $this->assertStatus(200, $this->web('GET', '/reset-password/' . $token));
        $this->web('POST', '/reset-password/' . $token, null, ['password' => 'Brand-new-pass-77', 'password_confirmation' => 'Brand-new-pass-77']);
        $hash = (string) $this->db->scalar('SELECT password_hash FROM users WHERE id = ?', [$t['user_id']]);
        $this->assertTrue(password_verify('Brand-new-pass-77', $hash));
        $this->assertStatus(404, $this->web('GET', '/reset-password/' . $token), 'token must be single-use');
    }

    public function testSuperAdminCannotEnterTenantWorkspaceAndTenantUserCannotSeeAdmin(): void
    {
        $t = $this->tenant('Acme', 'owner@acme.test');
        $adminId = $this->db->insert('users', ['tenant_id' => null, 'name' => 'Root', 'email' => 'root@platform.test',
            'password_hash' => password_hash('x', PASSWORD_BCRYPT), 'is_super_admin' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $admin = $this->sessionFor($adminId);
        $this->assertSame('/admin', $this->web('GET', '/contacts', $admin)->headers['Location'] ?? null);
        $this->assertStatus(200, $this->web('GET', '/admin', $admin));
        $this->assertStatus(404, $this->web('GET', '/admin', $this->sessionFor($t['user_id'])));
    }

    public function testSecurityHeadersPresent(): void
    {
        $r = $this->web('GET', '/login');
        $this->assertContains("default-src 'self'", $r->headers['Content-Security-Policy']);
        $this->assertSame('DENY', $r->headers['X-Frame-Options']);
        $this->assertSame('nosniff', $r->headers['X-Content-Type-Options']);
    }

    public function testOutputIsEscaped(): void
    {
        $t = $this->tenant('Acme', 'owner@acme.test');
        $s = $this->sessionFor($t['user_id']);
        $this->web('POST', '/contacts', $s, ['name' => '<script>alert(1)</script>', 'phone' => '+919800000001']);
        $html = $this->web('GET', '/contacts', $s)->body;
        $this->assertNotContains('<script>alert(1)</script>', $html);
        $this->assertContains('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }
}
