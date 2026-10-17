<?php

declare(strict_types=1);

namespace App\Core;

/** JSON-lines structured logger that redacts secrets. */
final class Logger
{
    /** Keys containing any of these substrings are redacted. */
    private const REDACT = ['password', 'token', 'secret', 'authorization', 'api_key', 'credentials', 'totp', 'cookie'];

    /** Keys redacted only on exact match (e.g. a 2FA "code", but not "error_code"). */
    private const REDACT_EXACT = ['code', 'otp', 'recovery_code'];

    public static function log(string $level, string $message, array $context = []): void
    {
        $line = json_encode([
            'ts' => gmdate('c'),
            'level' => $level,
            'message' => $message,
            'request_id' => $GLOBALS['__request_id'] ?? null,
            'context' => self::redact($context),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $file = (string) env('LOG_FILE', base_path('storage/logs/app.log'));
        if ($file === 'stderr' || !@file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX)) {
            error_log((string) $line);
        }
    }

    public static function info(string $m, array $c = []): void { self::log('info', $m, $c); }
    public static function warning(string $m, array $c = []): void { self::log('warning', $m, $c); }
    public static function error(string $m, array $c = []): void { self::log('error', $m, $c); }

    public static function redact(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_string($key)) {
                if (in_array(strtolower($key), self::REDACT_EXACT, true)) {
                    $context[$key] = '[redacted]';
                    continue;
                }
                foreach (self::REDACT as $needle) {
                    if (str_contains(strtolower($key), $needle)) {
                        $context[$key] = '[redacted]';
                        continue 2;
                    }
                }
            }
            if (is_array($value)) {
                $context[$key] = self::redact($value);
            }
        }
        return $context;
    }
}
