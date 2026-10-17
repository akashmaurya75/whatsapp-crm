<?php

declare(strict_types=1);

namespace App\WhatsApp;

use App\Core\Crypto;
use App\Core\Database;

/**
 * The platform's own Meta app, used for "Continue with Facebook" (WhatsApp Embedded Signup)
 * and for the shared webhook endpoint /webhooks/meta.
 * Values come from platform_settings (set by the super admin, encrypted) or fall back to .env:
 *   META_APP_ID, META_APP_SECRET, META_ES_CONFIG_ID, META_WEBHOOK_VERIFY_TOKEN
 */
final class MetaPlatform
{
    public const KEYS = ['app_id', 'app_secret', 'config_id', 'verify_token'];
    private const ENV = ['app_id' => 'META_APP_ID', 'app_secret' => 'META_APP_SECRET', 'config_id' => 'META_ES_CONFIG_ID', 'verify_token' => 'META_WEBHOOK_VERIFY_TOKEN'];

    /** @var array<int,array<string,string>> cache per connection */
    private static array $cache = [];

    /** @return array{app_id:string,app_secret:string,config_id:string,verify_token:string} */
    public static function config(?Database $db = null): array
    {
        $db ??= Database::connection();
        $key = spl_object_id($db);
        if (!isset(self::$cache[$key])) {
            $stored = [];
            try {
                foreach ($db->all("SELECT setting_key, value_enc FROM platform_settings WHERE setting_key LIKE 'meta.%'") as $r) {
                    $stored[substr((string) $r['setting_key'], 5)] = Crypto::decrypt((string) $r['value_enc']);
                }
            } catch (\Throwable) {
                $stored = []; // table not migrated yet
            }
            $cfg = [];
            foreach (self::KEYS as $k) {
                $cfg[$k] = (string) ($stored[$k] ?? '') !== '' ? (string) $stored[$k] : (string) env(self::ENV[$k], '');
            }
            self::$cache[$key] = $cfg;
        }
        return self::$cache[$key];
    }

    /** Ready for Embedded Signup (App ID, secret and configuration ID all present). */
    public static function signupReady(?Database $db = null): bool
    {
        $c = self::config($db);
        return preg_match('/^\d+$/', $c['app_id']) === 1 && $c['app_secret'] !== '' && preg_match('/^\d+$/', $c['config_id']) === 1;
    }

    /** @param array<string,string> $values only provided, non-empty values are changed */
    public static function save(Database $db, array $values, ?int $userId): void
    {
        foreach (self::KEYS as $k) {
            if (!array_key_exists($k, $values) || $values[$k] === '') {
                continue;
            }
            $db->run('DELETE FROM platform_settings WHERE setting_key = ?', ['meta.' . $k]);
            $db->insert('platform_settings', ['setting_key' => 'meta.' . $k, 'value_enc' => Crypto::encrypt($values[$k]),
                'updated_by' => $userId, 'updated_at' => now()]);
        }
        self::$cache = [];
    }

    /** Make sure a webhook verify token exists (generated once). */
    public static function ensureVerifyToken(Database $db): string
    {
        $c = self::config($db);
        if ($c['verify_token'] === '') {
            self::save($db, ['verify_token' => random_token(24)], null);
            $c = self::config($db);
        }
        return $c['verify_token'];
    }

    public static function graphVersion(): string
    {
        $v = (string) env('META_GRAPH_VERSION', 'v25.0');
        return preg_match('/^v\d+\.\d+$/', $v) ? $v : 'v25.0';
    }

    public static function resetCache(): void
    {
        self::$cache = [];
    }
}
