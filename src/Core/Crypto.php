<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Authenticated symmetric encryption for secrets at rest, keyed by APP_KEY.
 *
 * v1: sodium secretbox (XSalsa20-Poly1305) — used when the sodium extension is available.
 * v2: AES-256-GCM via openssl — fallback for shared hosts that don't enable sodium.
 * Both formats are always decryptable when the matching extension exists.
 */
final class Crypto
{
    private const GCM_IV = 12;
    private const GCM_TAG = 16;

    /** Test hook: force the openssl path even when sodium is present. */
    public static bool $forceOpenssl = false;

    private static function key(): string
    {
        $raw = (string) env('APP_KEY', '');
        if (str_starts_with($raw, 'base64:')) {
            $raw = (string) base64_decode(substr($raw, 7), true);
        }
        if (strlen($raw) !== 32) {
            throw new RuntimeException('APP_KEY must be 32 bytes (base64:...). Run: php bin/console key:generate');
        }
        return $raw;
    }

    public static function hasSodium(): bool
    {
        return !self::$forceOpenssl && function_exists('sodium_crypto_secretbox');
    }

    public static function available(): bool
    {
        return function_exists('sodium_crypto_secretbox')
            || (function_exists('openssl_encrypt') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true));
    }

    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    public static function encrypt(string $plaintext): string
    {
        if (self::hasSodium()) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, self::key()));
        }
        $iv = random_bytes(self::GCM_IV);
        $tag = '';
        $ct = openssl_encrypt($plaintext, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, 'v2', self::GCM_TAG);
        if ($ct === false) {
            throw new RuntimeException('Encryption failed (openssl)');
        }
        return 'v2:' . base64_encode($iv . $tag . $ct);
    }

    public static function decrypt(string $payload): string
    {
        $version = substr($payload, 0, 3);
        $raw = base64_decode(substr($payload, 3), true);
        if ($version === 'v1:') {
            if (!function_exists('sodium_crypto_secretbox_open')) {
                throw new RuntimeException('This data was encrypted with sodium; enable the PHP sodium extension');
            }
            if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
                throw new RuntimeException('Malformed ciphertext');
            }
            $plain = sodium_crypto_secretbox_open(
                substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                self::key()
            );
        } elseif ($version === 'v2:') {
            if ($raw === false || strlen($raw) < self::GCM_IV + self::GCM_TAG) {
                throw new RuntimeException('Malformed ciphertext');
            }
            $plain = openssl_decrypt(
                substr($raw, self::GCM_IV + self::GCM_TAG),
                'aes-256-gcm',
                self::key(),
                OPENSSL_RAW_DATA,
                substr($raw, 0, self::GCM_IV),
                substr($raw, self::GCM_IV, self::GCM_TAG),
                'v2'
            );
        } else {
            throw new RuntimeException('Unknown ciphertext version');
        }
        if ($plain === false) {
            throw new RuntimeException('Decryption failed (wrong APP_KEY or tampered data)');
        }
        return $plain;
    }

    /** @param array<string,mixed> $data */
    public static function encryptArray(array $data): string
    {
        return self::encrypt((string) json_encode($data, JSON_THROW_ON_ERROR));
    }

    /** @return array<string,mixed> */
    public static function decryptArray(string $payload): array
    {
        $data = json_decode(self::decrypt($payload), true);
        return is_array($data) ? $data : [];
    }
}
