<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Session wrapper. In web mode binds to $_SESSION (native, hardened config);
 * in tests uses an in-memory array.
 */
final class Session
{
    /** @var array<string,mixed> */
    private array $memory = [];

    private function __construct(private bool $native)
    {
    }

    public static function memory(): self
    {
        return new self(false);
    }

    public static function startNative(bool $secure): self
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.sid_length', '48');
            ini_set('session.sid_bits_per_character', '6');
            $savePath = base_path('storage/sessions');
            if (is_dir($savePath) && is_writable($savePath)) {
                session_save_path($savePath);
            }
            session_name((string) env('SESSION_COOKIE', 'wcrm_session'));
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
        return new self(true);
    }

    /** @return array<string,mixed> */
    private function &store(): array
    {
        if ($this->native) {
            return $_SESSION;
        }
        return $this->memory;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store()[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $store = &$this->store();
        $store[$key] = $value;
    }

    public function forget(string $key): void
    {
        $store = &$this->store();
        unset($store[$key]);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);
        return $value;
    }

    public function flash(string $key, mixed $value): void
    {
        $this->put('_flash.' . $key, $value);
    }

    public function getFlash(string $key): mixed
    {
        return $this->pull('_flash.' . $key);
    }

    /** Regenerate session id (prevents fixation) — call on login and privilege changes. */
    public function regenerate(): void
    {
        if ($this->native && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $this->forget('_csrf');
    }

    public function invalidate(): void
    {
        $store = &$this->store();
        $store = [];
        $this->regenerate();
    }
}
