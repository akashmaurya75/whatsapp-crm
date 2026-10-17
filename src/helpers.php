<?php

declare(strict_types=1);

use App\Core\Env;

/** HTML-escape any value for output in templates. */
function e(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    if (is_bool($value)) {
        $value = $value ? '1' : '0';
    }
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

function env(string $key, mixed $default = null): mixed
{
    return Env::get($key, $default);
}

function base_path(string $path = ''): string
{
    return BASE_PATH . ($path !== '' ? '/' . ltrim($path, '/') : '');
}

function now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function url(string $path = ''): string
{
    $base = rtrim((string) env('APP_URL', ''), '/');
    return $base . '/' . ltrim($path, '/');
}

/** Render WhatsApp text formatting (*bold* _italic_ ~strike~ ```mono```) safely: escape first, then add tags. */
function wa_format(?string $text): string
{
    $html = e((string) $text);
    $html = (string) preg_replace('/```(.+?)```/s', '<code>$1</code>', $html);
    $html = (string) preg_replace('/(?<![\w*])\*(?!\s)([^*\n]+?)(?<!\s)\*(?![\w*])/u', '<b>$1</b>', $html);
    $html = (string) preg_replace('/(?<![\w_])_(?!\s)([^_\n]+?)(?<!\s)_(?![\w_])/u', '<i>$1</i>', $html);
    $html = (string) preg_replace('/(?<![\w~])~(?!\s)([^~\n]+?)(?<!\s)~(?![\w~])/u', '<s>$1</s>', $html);
    return nl2br($html);
}

function csrf_field(App\Core\App $app): string
{
    return '<input type="hidden" name="_token" value="' . e($app->csrfToken()) . '">';
}

/** Field error markup for templates. */
function field_error(array $errors, string $field): string
{
    return isset($errors[$field]) ? '<p class="field-error" role="alert">' . e($errors[$field]) . '</p>' : '';
}

function old_value(array $old, string $field, mixed $default = ''): string
{
    $v = $old[$field] ?? $default;
    return is_scalar($v) || $v === null ? (string) $v : '';
}

/** Human-friendly relative time for a UTC 'Y-m-d H:i:s' string. */
function time_ago(?string $utc): string
{
    if ($utc === null || $utc === '') {
        return '—';
    }
    $diff = time() - (int) strtotime($utc . ' UTC');
    return match (true) {
        $diff < 60 => 'just now',
        $diff < 3600 => intdiv($diff, 60) . 'm ago',
        $diff < 86400 => intdiv($diff, 3600) . 'h ago',
        $diff < 7 * 86400 => intdiv($diff, 86400) . 'd ago',
        default => gmdate('j M Y', (int) strtotime($utc . ' UTC')),
    };
}

/** Build a query string from the current filters with overrides. */
function query_with(array $base, array $overrides): string
{
    $q = array_filter(array_merge($base, $overrides), static fn ($v) => $v !== '' && $v !== null && $v !== false && $v !== 0);
    return $q === [] ? '' : '?' . http_build_query($q);
}

/** Escape LIKE wildcards; use with ESCAPE '!' (portable across MySQL and SQLite). */
function like_escape(string $value): string
{
    return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
}

/** Generate a URL-safe random token. */
function random_token(int $bytes = 32): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

/**
 * URL of a file in public/assets. Normally "/assets/x". When PHP's built-in test server was started in the
 * application folder instead of public/ ("php -S localhost:8000"), static files live under /public/assets.
 */
function asset(string $file): string
{
    static $prefix = null;
    if ($prefix === null) {
        $prefix = '';
        if (PHP_SAPI === 'cli-server') {
            $root = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
            if ($root !== false && $root === realpath(BASE_PATH)) {
                $prefix = '/public';
            }
        }
    }
    $path = BASE_PATH . '/public/assets/' . $file;
    $v = is_file($path) ? '?v=' . substr((string) filemtime($path), -6) : '';
    return $prefix . '/assets/' . $file . $v;
}
