<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    /** @var array<string,string> route parameters */
    public array $params = [];

    /** @var array<string,mixed> arbitrary per-request attributes set by middleware */
    public array $attributes = [];

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,string> $headers lower-cased header names
     * @param array<string,mixed> $server
     * @param array<string,mixed> $files
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $headers = [],
        public readonly string $body = '',
        public readonly array $server = [],
        public readonly array $files = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        $path = '/' . trim(rawurldecode($path), '/');
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $body = (string) file_get_contents('php://input');
        $post = $_POST;
        // Allow method spoofing for HTML forms (PATCH/DELETE) only from POST.
        if ($method === 'POST' && isset($post['_method']) && in_array(strtoupper((string) $post['_method']), ['PATCH', 'PUT', 'DELETE'], true)) {
            $method = strtoupper((string) $post['_method']);
        }
        return new self($method, $path, $_GET, $post, $headers, $body, $_SERVER, $_FILES);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $json = $this->json();
        return $this->post[$key] ?? $json[$key] ?? $this->query[$key] ?? $default;
    }

    public function str(string $key, string $default = ''): string
    {
        $v = $this->input($key, $default);
        return is_scalar($v) ? trim((string) $v) : $default;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return array_merge($this->query, $this->json(), $this->post);
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        static $cache = [];
        $id = spl_object_id($this);
        if (!array_key_exists($id, $cache)) {
            $cache[$id] = [];
            if ($this->body !== '' && str_contains($this->header('content-type'), 'json')) {
                $decoded = json_decode($this->body, true);
                $cache[$id] = is_array($decoded) ? $decoded : [];
            }
        }
        return $cache[$id];
    }

    public function header(string $name, string $default = ''): string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function ip(): string
    {
        // Only trust X-Forwarded-For when explicitly configured behind a known proxy.
        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
        $trusted = array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))));
        if ($trusted !== [] && in_array($remote, $trusted, true) && ($fwd = $this->header('x-forwarded-for')) !== '') {
            $first = trim(explode(',', $fwd)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        return $remote;
    }

    public function userAgent(): string
    {
        return mb_substr($this->header('user-agent'), 0, 255);
    }

    public function isSecure(): bool
    {
        $https = strtolower((string) ($this->server['HTTPS'] ?? ''));
        return ($https !== '' && $https !== 'off')
            || ($this->server['REQUEST_SCHEME'] ?? '') === 'https'
            || (string) ($this->server['SERVER_PORT'] ?? '') === '443'
            || (in_array((string) ($this->server['REMOTE_ADDR'] ?? ''), array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))), true)
                && $this->header('x-forwarded-proto') === 'https');
    }

    public function wantsJson(): bool
    {
        return str_starts_with($this->path, '/api/') || str_contains($this->header('accept'), 'application/json');
    }

    public function param(string $key): string
    {
        return $this->params[$key] ?? '';
    }

    public function intParam(string $key): int
    {
        $v = $this->params[$key] ?? '';
        return ctype_digit($v) ? (int) $v : 0;
    }
}
