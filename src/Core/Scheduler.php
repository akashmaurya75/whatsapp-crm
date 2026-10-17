<?php

declare(strict_types=1);

namespace App\Core;

use App\Domain\Campaigns\CampaignRunner;
use App\WhatsApp\AccountRepository;
use Throwable;

/**
 * Periodic maintenance: cleanup, retention, campaign batches and the hourly account health check.
 * Safe to call as often as you like: it runs at most once per minute (and health checks once per hour),
 * tracked in small files under storage/.
 */
final class Scheduler
{
    public const STAMP = 'storage/schedule.last';
    public const HEALTH_STAMP = 'storage/health.last';

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

    /** @return array{ran:bool,campaigns:array<string,int>,health:int} */
    public static function run(Database $db, bool $force = false, bool $forceHealth = false): array
    {
        if (!$force && !self::due()) {
            return ['ran' => false, 'campaigns' => [], 'health' => 0];
        }
        self::touch(self::STAMP);

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
        $campaigns = CampaignRunner::tick(new App($db, Session::memory()));

        $checked = 0;
        if ($forceHealth || time() - self::stamp(self::HEALTH_STAMP) >= 3600) {
            self::touch(self::HEALTH_STAMP);
            $checked = self::healthCheck($db);
        }
        return ['ran' => true, 'campaigns' => $campaigns, 'health' => $checked];
    }

    private static function healthCheck(Database $db): int
    {
        $app = new App($db, Session::memory());
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
            $db->update('whatsapp_accounts', $r['ok']
                ? ['status' => 'connected', 'last_synced_at' => now()]
                : ['status' => 'error', 'last_error' => mb_substr((string) $r['message'], 0, 500), 'last_error_at' => now()], ['id' => $acc['id']]);
            if (!$r['ok']) {
                Logger::warning('Account health check failed', ['account_id' => $acc['id']]);
            }
            $n++;
        }
        return $n;
    }
}
