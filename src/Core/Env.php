<?php

declare(strict_types=1);

namespace App\Core;

/** Minimal .env loader. Real environment variables take precedence over the file. */
final class Env
{
    /** @var array<string,string> */
    private static array $values = [];

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            self::$values[$key] = $value;
        }
    }

    public static function set(string $key, string $value): void
    {
        self::$values[$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $real = getenv($key);
        $value = $real !== false ? $real : (self::$values[$key] ?? null);
        if ($value === null) {
            return $default;
        }
        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            'null', '' => $default,
            default => $value,
        };
    }
}
