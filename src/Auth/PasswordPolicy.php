<?php

declare(strict_types=1);

namespace App\Auth;

/** Configurable password policy (PASSWORD_MIN_LENGTH, PASSWORD_REQUIRE_MIXED). */
final class PasswordPolicy
{
    private const COMMON = ['password', 'password123', '12345678', '123456789', 'qwerty123', 'letmein', 'whatsapp', 'admin123', 'welcome1', 'iloveyou'];

    /** @return string|null error message, or null when acceptable */
    public static function check(string $password, string $email = ''): ?string
    {
        $min = max(8, (int) env('PASSWORD_MIN_LENGTH', 12));
        if (mb_strlen($password) < $min) {
            return "Password must be at least $min characters.";
        }
        if (mb_strlen($password) > 200) {
            return 'Password is too long.';
        }
        if ((bool) env('PASSWORD_REQUIRE_MIXED', true) && (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password))) {
            return 'Password must contain both letters and numbers.';
        }
        $lower = strtolower($password);
        if (in_array($lower, self::COMMON, true) || ($email !== '' && str_contains($lower, strtolower(explode('@', $email)[0])))) {
            return 'Password is too easy to guess.';
        }
        return null;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
    }
}
