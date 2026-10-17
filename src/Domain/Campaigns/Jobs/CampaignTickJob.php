<?php

declare(strict_types=1);

namespace App\Domain\Campaigns\Jobs;

use App\Core\App;
use App\Core\Job;
use App\Domain\Campaigns\CampaignRunner;

/**
 * Advances one campaign, then re-queues itself a minute later while the campaign is still
 * scheduled/running, so campaigns progress even where cron is unavailable.
 * (`schedule:run` also calls CampaignRunner::tick every minute as a safety net.)
 */
final class CampaignTickJob implements Job
{
    public function maxAttempts(): int
    {
        return 3;
    }

    public function backoff(int $attempt): int
    {
        return 30;
    }

    public function handle(App $app, array $data): void
    {
        $id = (int) ($data['campaign_id'] ?? 0);
        CampaignRunner::tick($app, $id);
        $status = $app->db->scalar('SELECT status FROM campaigns WHERE id = ?', [$id]);
        if (!in_array($status, ['running', 'scheduled'], true)) {
            return;
        }
        // One pending tick per campaign is enough.
        $pending = (int) $app->db->scalar('SELECT COUNT(*) FROM jobs WHERE queue = ? AND reserved_at IS NULL AND payload LIKE ?',
            ['default', '%CampaignTickJob%"campaign_id":' . $id . '}%']);
        if ($pending === 0) {
            $when = $status === 'scheduled' ? max(60, (int) strtotime((string) $app->db->scalar('SELECT scheduled_at FROM campaigns WHERE id = ?', [$id]) . ' UTC') - time()) : 60;
            $app->queue->push(self::class, ['campaign_id' => $id], 'default', min($when, 3600));
        }
    }
}
