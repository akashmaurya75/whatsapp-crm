<?php

declare(strict_types=1);

namespace App\Auth;

use App\Audit\AuditLogger;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\RateLimiter;
use App\Core\Session;

/**
 * Session authentication with throttling, optional TOTP 2FA, idle/absolute
 * timeouts and "log out everywhere" via users.session_version.
 */
final class AuthService
{
    public const MAX_ATTEMPTS = 5;
    public const DECAY_SECONDS = 900;

    private ?array $cachedUser = null;
    private bool $resolved = false;

    public function __construct(
        private Database $db,
        private Session $session,
        private RateLimiter $limiter,
        private AuditLogger $audit,
    ) {
    }

    /**
     * @return array{status:string,message?:string} status: ok | two_factor | invalid | throttled | suspended
     */
    public function attempt(string $email, string $password, string $ip, string $ua): array
    {
        $email = strtolower(trim($email));
        $keyEmail = 'login:' . $email . '|' . $ip;
        $keyIp = 'login-ip:' . $ip;
        if ($this->limiter->tooMany($keyEmail, self::MAX_ATTEMPTS) || $this->limiter->tooMany($keyIp, self::MAX_ATTEMPTS * 6)) {
            $wait = (int) ceil(max($this->limiter->availableIn($keyEmail), $this->limiter->availableIn($keyIp)) / 60);
            return ['status' => 'throttled', 'message' => "Too many login attempts. Try again in $wait minute(s)."];
        }

        $user = $this->db->first('SELECT * FROM users WHERE email = ?', [$email]);
        // Always run a hash verification to keep timing similar for unknown emails.
        static $dummy = null;
        $dummy ??= PasswordPolicy::hash(random_token(16));
        $hash = $user['password_hash'] ?? $dummy;
        $valid = password_verify($password, (string) $hash) && $user !== null;

        $this->db->insert('login_attempts', [
            'email' => mb_substr($email, 0, 191), 'ip' => $ip, 'success' => $valid ? 1 : 0,
            'user_agent' => mb_substr($ua, 0, 255), 'created_at' => now(),
        ]);

        if (!$valid) {
            $this->limiter->hit($keyEmail, self::MAX_ATTEMPTS, self::DECAY_SECONDS);
            $this->limiter->hit($keyIp, self::MAX_ATTEMPTS * 6, self::DECAY_SECONDS);
            return ['status' => 'invalid', 'message' => 'These credentials do not match our records.'];
        }
        if (!$this->accountUsable($user)) {
            return ['status' => 'suspended', 'message' => 'This account or company is not active. Contact your administrator.'];
        }
        $this->limiter->clear($keyEmail);

        if (password_needs_rehash((string) $user['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) {
            $this->db->update('users', ['password_hash' => PasswordPolicy::hash($password)], ['id' => $user['id']]);
        }

        if ($user['totp_enabled_at'] !== null) {
            $this->session->regenerate();
            $this->session->put('2fa_user_id', (int) $user['id']);
            $this->session->put('2fa_started', time());
            return ['status' => 'two_factor'];
        }
        $this->login($user, $ip, $ua);
        return ['status' => 'ok'];
    }

    /** Second step: verify TOTP or a one-time recovery code. */
    public function verifyTwoFactor(string $code, string $ip, string $ua): bool
    {
        $userId = (int) $this->session->get('2fa_user_id', 0);
        if ($userId === 0 || time() - (int) $this->session->get('2fa_started', 0) > 300) {
            $this->session->forget('2fa_user_id');
            return false;
        }
        $key = '2fa:' . $userId;
        if ($this->limiter->tooMany($key, self::MAX_ATTEMPTS)) {
            return false;
        }
        $user = $this->db->first('SELECT * FROM users WHERE id = ?', [$userId]);
        if ($user === null || $user['totp_secret_enc'] === null || !$this->accountUsable($user)) {
            return false;
        }
        $ok = Totp::verify(Crypto::decrypt((string) $user['totp_secret_enc']), $code);
        if (!$ok) {
            $ok = $this->consumeRecoveryCode($user, $code);
        }
        if (!$ok) {
            $this->limiter->hit($key, self::MAX_ATTEMPTS, self::DECAY_SECONDS);
            $this->audit->record('auth.2fa_failed', $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null, $userId, ip: $ip, userAgent: $ua);
            return false;
        }
        $this->limiter->clear($key);
        $this->session->forget('2fa_user_id');
        $this->login($user, $ip, $ua);
        return true;
    }

    private function consumeRecoveryCode(array $user, string $code): bool
    {
        $codes = json_decode((string) ($user['recovery_codes'] ?? '[]'), true) ?: [];
        $hash = hash('sha256', strtoupper(trim($code)));
        foreach ($codes as $i => $stored) {
            if (hash_equals((string) $stored, $hash)) {
                unset($codes[$i]);
                $this->db->update('users', ['recovery_codes' => json_encode(array_values($codes))], ['id' => $user['id']]);
                return true;
            }
        }
        return false;
    }

    public function login(array $user, string $ip, string $ua): void
    {
        $this->session->regenerate();
        $this->session->put('user_id', (int) $user['id']);
        $this->session->put('session_version', (int) $user['session_version']);
        $this->session->put('login_at', time());
        $this->session->put('last_activity', time());
        $this->db->update('users', ['last_login_at' => now(), 'last_login_ip' => $ip], ['id' => $user['id']]);
        $this->audit->record('auth.login', $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null, (int) $user['id'], ip: $ip, userAgent: $ua);
        $this->cachedUser = null;
        $this->resolved = false;
    }

    /** @return array<string,mixed>|null the authenticated, still-valid user */
    public function user(): ?array
    {
        if ($this->resolved) {
            return $this->cachedUser;
        }
        $this->resolved = true;
        $id = (int) $this->session->get('user_id', 0);
        if ($id === 0) {
            return null;
        }
        $idle = (int) env('SESSION_IDLE_MINUTES', 120) * 60;
        $absolute = (int) env('SESSION_ABSOLUTE_HOURS', 12) * 3600;
        $now = time();
        $user = $this->db->first('SELECT * FROM users WHERE id = ?', [$id]);
        if (
            $user === null
            || (int) $user['session_version'] !== (int) $this->session->get('session_version', -1)
            || $now - (int) $this->session->get('last_activity', 0) > $idle
            || $now - (int) $this->session->get('login_at', 0) > $absolute
            || !$this->accountUsable($user)
        ) {
            $this->session->invalidate();
            return null;
        }
        $this->session->put('last_activity', $now);
        return $this->cachedUser = $user;
    }

    public function logout(): void
    {
        $this->session->invalidate();
        $this->cachedUser = null;
        $this->resolved = true;
    }

    /** Invalidates every session of the user (including the current one). */
    public function logoutEverywhere(int $userId): void
    {
        $this->db->run('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$userId]);
        $this->logout();
    }

    private function accountUsable(array $user): bool
    {
        if ($user['status'] !== 'active') {
            return false;
        }
        if ($user['tenant_id'] === null) {
            return (int) $user['is_super_admin'] === 1;
        }
        $status = $this->db->scalar('SELECT status FROM tenants WHERE id = ?', [$user['tenant_id']]);
        return $status === 'active';
    }

    /** @return list<string> plaintext recovery codes (shown once); hashes are stored */
    public function generateRecoveryCodes(int $userId): array
    {
        $plain = [];
        for ($i = 0; $i < 8; $i++) {
            $plain[] = strtoupper(bin2hex(random_bytes(4)) . '-' . bin2hex(random_bytes(4)));
        }
        $this->db->update('users', ['recovery_codes' => json_encode(array_map(static fn ($c) => hash('sha256', $c), $plain))], ['id' => $userId]);
        return $plain;
    }

    /** Creates a password reset token; returns the plaintext token (to email) or null if no such user. */
    public function createResetToken(string $email): ?string
    {
        $user = $this->db->first('SELECT id FROM users WHERE email = ? AND status = ?', [strtolower(trim($email)), 'active']);
        if ($user === null) {
            return null;
        }
        $token = random_token(32);
        $this->db->update('users', [
            'reset_token_hash' => hash('sha256', $token),
            'reset_expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ], ['id' => $user['id']]);
        return $token;
    }

    /** @return array<string,mixed>|null */
    public function findByResetToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        return $this->db->first(
            'SELECT * FROM users WHERE reset_token_hash = ? AND reset_expires_at > ?',
            [hash('sha256', $token), now()]
        );
    }

    /** @return array<string,mixed>|null */
    public function findByInviteToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        return $this->db->first(
            'SELECT * FROM users WHERE invite_token_hash = ? AND invite_expires_at > ? AND status = ?',
            [hash('sha256', $token), now(), 'invited']
        );
    }

    public function setPassword(int $userId, string $password): void
    {
        $this->db->run(
            'UPDATE users SET password_hash = ?, reset_token_hash = NULL, reset_expires_at = NULL,
             session_version = session_version + 1, updated_at = ? WHERE id = ?',
            [PasswordPolicy::hash($password), now(), $userId]
        );
    }
}
