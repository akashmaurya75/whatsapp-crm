<?php

declare(strict_types=1);

namespace App\Core;

use App\Domain\Campaigns\CampaignRunner;
use App\WhatsApp\AccountRepository;
use App\WhatsApp\TokenMonitor;
use Throwable;

/**
 * Periodic maintenance: cleanup, retention, campaign batches, the hourly account health check
 * and the daily Meta token check.
 * Safe to call as often as you like: it runs at most once per minute (health checks once per hour,
 * token checks once per day), tracked in small files under storage/.
 */
final class Scheduler
{
    public const STAMP = 'storage/schedule.last';
    public const HEALTH_STAMP = 'storage/health.last';
    public const TOKEN_STAMP = 'storage/tokens.last';

    public static function due(int $now = 0): bool
    {
        $now = $now ?: time();
        return $now - self::stamp(self::STAMP) >= 55;
    }

    public static function stamp(string $rel): int
    {
        $f = base_path($rel);
        return is_file($f) ? (int) @file_get_contents($f) : 0;
    }

    private static function touch(string $rel): void
    {
        @file_put_contents(base_path($rel), (string) time(), LOCK_EX);
    }

    /** @return array{ran:bool,campaigns:array<string,int>,health:int,tokens:int} */
    public static function run(Database $db, bool $force = false, bool $forceHealth = false, ?HttpClient $http = null): array
    {
        if (!$force && !self::due()) {
            return ['ran' => false, 'campaigns' => [], 'health' => 0, 'tokens' => 0];
        }
        self::touch(self::STAMP);
        $app = new App($db, Session::memory(), $http ?? new CurlHttpClient());

        $db->run('DELETE FROM rate_limits WHERE reset_at < ?', [time() - 60]);
        $db->run('DELETE FROM webhook_events WHERE received_at < ?', [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
        $db->run('DELETE FROM idempotency_keys WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 7 * 86400)]);
        $db->run('DELETE FROM login_attempts WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 90 * 86400)]);
        $retentionDays = (int) env('AUDIT_RETENTION_DAYS', 365);
        if ($retentionDays > 0) {
            $db->run('DELETE FROM audit_logs WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - $retentionDays * 86400)]);
        }
        $msgRetention = (int) env('MESSAGE_RETENTION_DAYS', 0);
        if ($msgRetention > 0) {
            $db->run('DELETE FROM messages WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - $msgRetention * 86400)]);
        }

        // Campaigns: start due ones and queue this minute's batches.
        $campaigns = CampaignRunner::tick($app);

        // Tokens first, so a dead token is reported as "needs_reconnect" rather than a generic health error.
        $tokens = 0;
        if ($forceHealth || time() - self::stamp(self::TOKEN_STAMP) >= 86400) {
            self::touch(self::TOKEN_STAMP);
            $tokens = (new TokenMonitor($db, $app->http, $app->mailer))->checkAll();
        }
        $checked = 0;
        if ($forceHealth || time() - self::stamp(self::HEALTH_STAMP) >= 3600) {
            self::touch(self::HEALTH_STAMP);
            $checked = self::healthCheck($app);
        }
        return ['ran' => true, 'campaigns' => $campaigns, 'health' => $checked, 'tokens' => $tokens];
    }

    private static function healthCheck(App $app): int
    {
        $db = $app->db;
        $n = 0;
        foreach ($db->all("SELECT a.id FROM whatsapp_accounts a JOIN tenants t ON t.id = a.tenant_id WHERE t.status = 'active'") as $row) {
            $acc = $db->first('SELECT * FROM whatsapp_accounts WHERE id = ?', [$row['id']]);
            if ($acc === null || !$app->providers->has((string) $acc['provider'])) {
                continue;
            }
            try {
                $r = $app->providers->get((string) $acc['provider'])->checkHealth($acc, AccountRepository::credentials($acc));
            } catch (Throwable $e) {
                $r = ['ok' => false, 'message' => $e->getMessage()];
            }
            $n++;
            if ($r['ok']) {
                $db->update('whatsapp_accounts', ['status' => 'connected', 'last_synced_at' => now()]
                    + ($acc['status'] === 'needs_reconnect' ? ['token_status' => null, 'reconnect_notified_at' => null] : []), ['id' => $acc['id']]);
                continue;
            }
            Logger::warning('Account health check failed', ['account_id' => $acc['id']]);
            if ($acc['provider'] === 'meta_cloud' && TokenMonitor::isAuthError($r['code'] ?? null)) {
                (new TokenMonitor($db, $app->http, $app->mailer))->markNeedsReconnect((int) $acc['id'], 'Meta rejected the access token: ' . $r['message']);
            } elseif ($acc['status'] !== 'needs_reconnect') {
                // A network blip must not hide that the account still needs reconnecting.
                $db->update('whatsapp_accounts', ['status' => 'error', 'last_error' => mb_substr((string) $r['message'], 0, 500), 'last_error_at' => now()], ['id' => $acc['id']]);
            }
        }
        return $n;
    }
}
