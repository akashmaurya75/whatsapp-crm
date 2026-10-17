<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Campaigns\CampaignRunner;
use App\Domain\Campaigns\CampaignService;
use App\Domain\Contacts\ContactRepository;
use App\Domain\ValidationException;
use App\Tenancy\TenantContext;
use App\WhatsApp\ProviderFactory;
use Tests\TestCase;

final class CampaignTest extends TestCase
{
    private array $t;
    private array $acc;
    private array $tpl;
    private CampaignService $svc;
    private ContactRepository $contacts;

    public function setUp(): void
    {
        parent::setUp();
        $this->t = $this->tenant('Acme', 'owner@acme.test');
        $this->acc = $this->account($this->t['tenant_id']);
        $this->tpl = $this->template($this->t['tenant_id'], (int) $this->acc['id'], ['name' => 'festive_offer', 'category' => 'marketing']);
        $ctx = new TenantContext();
        $ctx->set($this->t['tenant_id']);
        $this->svc = new CampaignService($this->db, $ctx, $this->app()->queue, new ProviderFactory($this->http));
        $this->contacts = new ContactRepository($this->db, $ctx);
    }

    /** 5 consented VIPs, 1 VIP without consent, 1 opted-out VIP, 1 non-VIP. */
    private function seedAudience(): void
    {
        $tid = $this->t['tenant_id'];
        for ($i = 1; $i <= 5; $i++) {
            $id = $this->contact($tid, '+91980000000' . $i, ['name' => 'Vip ' . $i]);
            $this->contacts->syncTags($id, ['vip']);
        }
        $this->contacts->syncTags($this->contact($tid, '+919800000006', ['consent_status' => 'unknown']), ['vip']);
        $this->contacts->syncTags($this->contact($tid, '+919800000007', ['opted_out_at' => now(), 'consent_status' => 'revoked']), ['vip']);
        $this->contact($tid, '+919800000008', ['name' => 'Regular']);
    }

    private function settings(array $over = []): array
    {
        return $over + [
            'tags_any' => ['vip'], 'map' => ['body_1' => ['source' => 'first_name', 'fallback' => 'there'], 'body_2' => ['source' => 'static', 'value' => 'DIWALI20']],
            'require_consent' => '1', 'frequency_cap_hours' => '24', 'rate_per_minute' => '60', 'send_mode' => 'now',
        ];
    }

    private function draft(array $settings = []): int
    {
        $id = $this->svc->create(['name' => 'Diwali blast', 'template_id' => (string) $this->tpl['id']], $this->t['user_id']);
        $this->svc->saveSettings($id, $this->settings($settings), $this->t['user_id']);
        return $id;
    }

    private function okAll(int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->http->queue(200, ['messages' => [['id' => 'wamid.C' . $i . bin2hex(random_bytes(3))]]]);
        }
    }

    public function testPreviewCountsAndExclusions(): void
    {
        $this->seedAudience();
        $id = $this->draft();
        $p = $this->svc->preview((array) $this->svc->repo->find($id));
        $this->assertSame(7, $p['matched']);
        $this->assertSame(5, $p['eligible']);
        $this->assertSame(['no_consent' => 1, 'opted_out' => 1], (function ($x) { ksort($x); return $x; })($p['excluded']));
        $this->assertContains('Hi Vip, your order DIWALI20 has shipped', $p['samples'][0]['text']);
        // Without the consent requirement (attested), the no-consent contact is included; opted-out never is.
        $this->svc->saveSettings($id, $this->settings(['require_consent' => '', 'consent_attested' => '1']), $this->t['user_id']);
        $this->assertSame(6, $this->svc->preview((array) $this->svc->repo->find($id))['eligible']);
    }

    public function testConsentMustBeAttestedWhenNotRequired(): void
    {
        $id = $this->svc->create(['name' => 'X', 'template_id' => (string) $this->tpl['id']], null);
        try {
            $this->svc->saveSettings($id, $this->settings(['require_consent' => '']), null);
            $this->assertTrue(false, 'expected error');
        } catch (ValidationException $e) {
            $this->assertTrue(isset($e->errors['consent_attested']));
        }
    }

    public function testRunSendsInThrottledBatchesAndCompletes(): void
    {
        $this->seedAudience();
        $id = $this->draft(['rate_per_minute' => '10']);
        $this->db->update('campaigns', ['rate_per_minute' => 3], ['id' => $id]); // force small batches for the test
        $this->svc->launch($id);
        $this->assertSame('scheduled', $this->svc->repo->find($id)['status']);
        $this->okAll(5);
        $this->runQueue(); // CampaignTickJob: start + first batch (3) + their sends
        $c = $this->svc->repo->find($id);
        $this->assertSame('running', $c['status']);
        $this->assertSame(5, (int) $c['total_recipients']);
        $this->assertSame(2, (int) $c['skipped_count']);
        $this->assertSame(3, (int) $this->db->scalar("SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = ? AND status = 'submitted'", [$id]));
        $this->assertSame(2, (int) $this->db->scalar("SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = ? AND status = 'pending'", [$id]));
        // Same minute: a second tick must not send another batch.
        $this->assertSame(0, CampaignRunner::tick($this->app(), $id)['queued']);
        // Next minute.
        $this->db->update('campaigns', ['last_batch_at' => gmdate('Y-m-d H:i:s', time() - 120)], ['id' => $id]);
        $this->assertSame(2, CampaignRunner::tick($this->app(), $id)['queued']);
        $this->runQueue();
        $this->assertSame('running', $this->svc->repo->find($id)['status']);
        $this->db->update('campaigns', ['last_batch_at' => gmdate('Y-m-d H:i:s', time() - 120)], ['id' => $id]);
        $this->assertSame(1, CampaignRunner::tick($this->app(), $id)['completed'], 'completes on the next tick once everything is sent');
        $this->assertSame('completed', $this->svc->repo->find($id)['status']);
        $sent = array_map(static fn ($r) => json_decode((string) $r['body'], true), $this->http->requests);
        $this->assertSame(5, count($sent));
        $this->assertSame('festive_offer', $sent[0]['template']['name']);
        $phones = array_column($sent, 'to');
        $this->assertFalse(in_array('919800000007', $phones, true), 'opted-out contact never messaged');
        $this->assertFalse(in_array('919800000006', $phones, true), 'contact without consent skipped');
        $this->assertSame(count($phones), count(array_unique($phones)), 'nobody messaged twice');
        // New threads from a broadcast don't flood the agents' open inbox.
        $this->assertSame(0, (int) $this->db->scalar("SELECT COUNT(*) FROM conversations WHERE status = 'open'"));
    }

    public function testStatusesMirrorRepliesAreAttributedAndReported(): void
    {
        $this->seedAudience();
        $id = $this->draft();
        $this->svc->launch($id);
        $this->okAll(5);
        $this->runQueue();
        $msgs = $this->db->all('SELECT * FROM messages WHERE campaign_id = ? ORDER BY id', [$id]);
        $this->assertSame(5, count($msgs));
        $hook = function (array $statuses = [], array $messages = []): void {
            $body = (string) json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => 'W', 'changes' => [['field' => 'messages', 'value' => [
                'metadata' => ['phone_number_id' => $this->acc['phone_number_id']], 'contacts' => [], 'statuses' => $statuses, 'messages' => $messages,
            ]]]]]]);
            $this->raw('POST', '/webhooks/whatsapp/' . $this->acc['webhook_token'], $body,
                ['content-type' => 'application/json', 'x-hub-signature-256' => 'sha256=' . hash_hmac('sha256', $body, 'app-secret-123')]);
        };
        $st = static fn (array $m, string $s) => ['id' => $m['provider_message_id'], 'status' => $s, 'timestamp' => (string) time()];
        $hook([$st($msgs[0], 'delivered'), $st($msgs[1], 'delivered'), $st($msgs[1], 'read'), $st($msgs[2], 'failed')]);
        $phone1 = ltrim((string) $this->db->scalar('SELECT c.phone_e164 FROM contacts c JOIN conversations cv ON cv.contact_id = c.id WHERE cv.id = ?', [$msgs[1]['conversation_id']]), '+');
        $hook([], [['from' => $phone1, 'id' => 'wamid.reply1', 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => 'Interested!']]]);
        $this->runQueue();
        $s = $this->svc->stats((array) $this->svc->repo->find($id));
        $this->assertSame(1, $s['read']);
        $this->assertSame(2, $s['delivered_total']);
        $this->assertSame(1, $s['failed']);
        $this->assertSame(4, $s['accepted']);
        $this->assertSame(1, $s['replied']);
        // The replying customer's thread is reopened for agents.
        $this->assertSame('open', $this->db->scalar('SELECT status FROM conversations WHERE id = ?', [$msgs[1]['conversation_id']]));
        $owner = $this->sessionFor($this->t['user_id']);
        $html = $this->web('GET', '/campaigns/' . $id, $owner)->body;
        $this->assertContains('Funnel', $html);
        $csv = $this->web('GET', '/campaigns/' . $id . '/export', $owner)->body;
        $this->assertSame(8, count(array_filter(explode("\n", trim($csv)))), 'header + 7 recipients (incl. skipped)');
    }

    public function testPauseHoldsQueuedMessagesAndCancelDropsThem(): void
    {
        $this->seedAudience();
        $id = $this->draft();
        $this->svc->launch($id);
        // Run only the tick job (default queue): messages get queued but not yet sent.
        $app = $this->app();
        $app->queue->work($app, 'default', 't');
        $this->assertSame(5, (int) $this->db->scalar("SELECT COUNT(*) FROM messages WHERE campaign_id = ? AND status = 'queued'", [$id]));
        $this->svc->pause($id);
        $this->runQueue();
        $this->assertSame(0, count($this->http->requests), 'paused: nothing sent');
        $this->assertSame(5, (int) $this->db->scalar("SELECT COUNT(*) FROM jobs WHERE queue = 'messages'"), 'jobs kept for later');
        $this->assertSame(5, (int) $this->db->scalar("SELECT MIN(attempts) FROM jobs WHERE queue = 'messages'") + 5, 'release does not consume attempts');
        $this->svc->cancel($id);
        $this->runQueue();
        $this->assertSame(0, count($this->http->requests));
        $this->assertSame(5, (int) $this->db->scalar("SELECT COUNT(*) FROM messages WHERE campaign_id = ? AND status = 'failed' AND error_code = 'cancelled'", [$id]));
        $this->assertSame('cancelled', $this->svc->repo->find($id)['status']);
    }

    public function testFrequencyCapSkipsRecentlyMessagedContacts(): void
    {
        $this->seedAudience();
        $first = $this->draft();
        $this->svc->launch($first);
        $this->okAll(5);
        $this->runQueue();
        $second = $this->draft();
        $p = $this->svc->preview((array) $this->svc->repo->find($second));
        $this->assertSame(0, $p['eligible']);
        $this->assertSame(5, $p['excluded']['frequency_cap']);
        $this->assertThrows(ValidationException::class, fn () => $this->svc->launch($second));
        $this->svc->saveSettings($second, $this->settings(['frequency_cap_hours' => '0']), null);
        $this->assertSame(5, $this->svc->preview((array) $this->svc->repo->find($second))['eligible']);
    }

    public function testScheduledCampaignWaitsUntilDue(): void
    {
        $this->seedAudience();
        $id = $this->svc->create(['name' => 'Later', 'template_id' => (string) $this->tpl['id']], null);
        $when = (new \DateTimeImmutable('+2 hours', new \DateTimeZone($this->svc->timezone())))->format('Y-m-d\TH:i');
        $this->svc->saveSettings($id, $this->settings(['send_mode' => 'later', 'scheduled_at' => $when]), null);
        $this->svc->launch($id);
        $this->assertSame(0, CampaignRunner::tick($this->app())['started']);
        $this->assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM jobs WHERE available_at > ?', [time() + 3600]), 'tick job delayed until send time');
        $this->db->update('campaigns', ['scheduled_at' => gmdate('Y-m-d H:i:s', time() - 5)], ['id' => $id]);
        $this->assertSame(1, CampaignRunner::tick($this->app())['started']);
        $c = $this->svc->repo->find($id);
        $this->assertTrue($c['started_at'] !== null);
        $this->assertSame('running', $c['status'], 'stays running while its messages are still queued');
        $this->assertSame(5, (int) $this->db->scalar("SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = ? AND status = 'queued'", [$id]));
        $this->assertThrows(ValidationException::class, fn () => $this->svc->saveSettings($id, $this->settings(['send_mode' => 'later', 'scheduled_at' => '2000-01-01T10:00']), null));
    }

    public function testTemplateNoLongerApprovedStopsCampaign(): void
    {
        $this->seedAudience();
        $id = $this->draft();
        $this->svc->launch($id);
        $this->db->update('message_templates', ['status' => 'paused'], ['id' => $this->tpl['id']]);
        $this->runQueue();
        $c = $this->svc->repo->find($id);
        $this->assertSame('failed', $c['status']);
        $this->assertContains('paused', (string) $c['last_error']);
    }

    public function testWebFlowIsolationAndPermissions(): void
    {
        $this->seedAudience();
        $owner = $this->sessionFor($this->t['user_id']);
        $this->assertStatus(200, $this->web('GET', '/campaigns', $owner));
        $r = $this->web('POST', '/campaigns', $owner, ['name' => 'Web camp', 'template_id' => (string) $this->tpl['id']]);
        $id = (int) $this->db->scalar("SELECT id FROM campaigns WHERE name = 'Web camp'");
        $this->assertSame('/campaigns/' . $id . '/edit', $r->headers['Location']);
        $this->assertContains('contacts will receive this', $this->web('GET', '/campaigns/' . $id . '/edit', $owner)->body);
        $this->web('POST', '/campaigns/' . $id, $owner, $this->settings());
        $this->web('POST', '/campaigns/' . $id . '/action', $owner, ['action' => 'launch']);
        $this->assertSame('scheduled', $this->db->scalar('SELECT status FROM campaigns WHERE id = ?', [$id]));
        $analyst = $this->sessionFor($this->user($this->t['tenant_id'], 'analyst', 'an@acme.test'));
        $this->assertStatus(200, $this->web('GET', '/campaigns/' . $id, $analyst));
        $this->assertStatus(403, $this->web('POST', '/campaigns/' . $id . '/action', $analyst, ['action' => 'cancel']));
        $other = $this->tenant('Other', 'o@other.test');
        $os = $this->sessionFor($other['user_id']);
        $this->assertStatus(404, $this->web('GET', '/campaigns/' . $id, $os));
        $this->assertStatus(404, $this->web('POST', '/campaigns/' . $id . '/action', $os, ['action' => 'cancel']));
        $this->web('POST', '/campaigns', $os, ['name' => 'Steal', 'template_id' => (string) $this->tpl['id']]);
        $this->assertSame(0, (int) $this->db->scalar("SELECT COUNT(*) FROM campaigns WHERE name = 'Steal'"), "can't use another company's template");
    }
}
