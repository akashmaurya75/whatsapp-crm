<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Scheduler;
use App\Domain\Contacts\ContactService;
use App\Domain\Conversations\InboxService;
use App\Tenancy\TenantContext;
use App\WhatsApp\MetaPlatform;
use App\WhatsApp\TokenMonitor;
use Tests\TestCase;

final class TokenLifecycleTest extends TestCase
{
    private array $t;
    private array $acc;

    public function setUp(): void
    {
        parent::setUp();
        MetaPlatform::save($this->db, ['app_id' => '111222333', 'app_secret' => '0123456789abcdef0123456789abcdef', 'config_id' => '444555666'], null);
        $this->t = $this->tenant('Acme', 'owner@acme.test');
        $this->acc = $this->account($this->t['tenant_id'], 'meta_cloud', ['managed' => true]);
    }

    private function monitor(): TokenMonitor
    {
        return new TokenMonitor($this->db, $this->http);
    }

    private function acc(): array
    {
        return (array) $this->db->first('SELECT * FROM whatsapp_accounts WHERE id = ?', [$this->acc['id']]);
    }

    private function campaign(string $status): int
    {
        $tpl = $this->template($this->t['tenant_id'], (int) $this->acc['id']);
        return $this->db->insert('campaigns', ['tenant_id' => $this->t['tenant_id'], 'whatsapp_account_id' => $this->acc['id'],
            'template_id' => $tpl['id'], 'name' => 'Promo', 'status' => $status, 'started_at' => $status === 'running' ? now() : null,
            'created_at' => now(), 'updated_at' => now()]);
    }

    private function mailCount(): int
    {
        return count(glob(base_path('storage/mail') . '/*.eml') ?: []);
    }

    public function testNeverExpiringTokenIsRecordedAsValid(): void
    {
        $this->http->queue(200, ['data' => ['is_valid' => true, 'expires_at' => 0, 'data_access_expires_at' => 0]]);
        $this->assertSame('valid', $this->monitor()->check($this->acc));
        $a = $this->acc();
        $this->assertSame('valid', $a['token_status']);
        $this->assertSame(null, $a['token_expires_at']);
        $this->assertTrue($a['token_checked_at'] !== null);
        $this->assertContains('debug_token', $this->http->requests[0]['url']);
        $this->assertContains('input_token=EAAtest', $this->http->requests[0]['url']);
    }

    public function testExpiringTokenWarnsOwnersOnce(): void
    {
        $mails = $this->mailCount();
        $soon = time() + 3 * 86400;
        $this->http->queue(200, ['data' => ['is_valid' => true, 'expires_at' => $soon]]);
        $this->assertSame('expiring', $this->monitor()->check($this->acc));
        $this->assertSame(gmdate('Y-m-d H:i:s', $soon), $this->acc()['token_expires_at']);
        $this->assertSame($mails + 1, $this->mailCount(), 'owner emailed');
        $this->http->queue(200, ['data' => ['is_valid' => true, 'expires_at' => $soon]]);
        $this->monitor()->check($this->acc());
        $this->assertSame($mails + 1, $this->mailCount(), 'not emailed again');
        $r = $this->web('GET', '/', $this->sessionFor($this->t['user_id']));
        $this->assertContains('expires within a week', $r->body);
        $this->assertContains('/whatsapp/connect?account=' . $this->acc['id'], $r->body);
    }

    public function testInvalidTokenNeedsReconnectAndPausesCampaigns(): void
    {
        $running = $this->campaign('running');
        $mails = $this->mailCount();
        $this->http->queue(200, ['data' => ['is_valid' => false, 'error' => ['message' => 'The user has not authorized application']]]);
        $this->assertSame('invalid', $this->monitor()->check($this->acc));
        $a = $this->acc();
        $this->assertSame('needs_reconnect', $a['status']);
        $this->assertContains('not authorized', (string) $a['last_error']);
        $this->assertSame('paused', $this->db->scalar('SELECT status FROM campaigns WHERE id = ?', [$running]));
        $this->assertSame($mails + 1, $this->mailCount());
        $this->assertSame(1, (int) $this->db->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'whatsapp_account.needs_reconnect'"));
        // Repeated detection does not spam.
        $this->monitor()->markNeedsReconnect((int) $this->acc['id'], 'again');
        $this->assertSame($mails + 1, $this->mailCount());
        $r = $this->web('GET', '/whatsapp/' . $this->acc['id'], $this->sessionFor($this->t['user_id']));
        $this->assertContains('Meta no longer accepts', $r->body);
        $agent = $this->sessionFor($this->user($this->t['tenant_id'], 'support_agent', 'ag@acme.test'));
        $this->assertNotContains('/whatsapp/connect?account=', $this->web('GET', '/inbox', $agent)->body);
    }

    public function testUnreachableMetaNeverMarksTokenInvalid(): void
    {
        $this->http->responses[] = new \RuntimeException('timeout');
        $this->assertSame(null, $this->monitor()->check($this->acc));
        $this->http->queue(500, ['error' => ['message' => 'Invalid app secret']]);
        $this->assertSame(null, $this->monitor()->check($this->acc));
        $this->assertSame('connected', $this->acc()['status']);
    }

    public function testManualAccountsAreNotInspected(): void
    {
        $manual = $this->account($this->t['tenant_id']);
        $this->assertSame(null, $this->monitor()->check($manual));
        $this->assertSame(0, count($this->http->requests));
    }

    public function testSendRejectedWithError190MarksAccountAndStopsFurtherSends(): void
    {
        $ctx = new TenantContext();
        $ctx->set($this->t['tenant_id']);
        $inbox = new InboxService($this->db, $ctx, $this->app()->queue);
        $conv = $inbox->findOrCreateConversation((new ContactService($this->db, $ctx))->create(['phone' => '+919876500000']), (int) $this->acc['id']);
        $this->db->update('conversations', ['last_inbound_at' => now()], ['id' => $conv['id']]);
        $conv = (array) $this->db->first('SELECT * FROM conversations WHERE id = ?', [$conv['id']]);
        $first = $inbox->queueText($conv, null, 'hi');
        $this->http->queue(401, ['error' => ['message' => 'Error validating access token: Session has expired', 'type' => 'OAuthException', 'code' => 190]]);
        $this->runQueue();
        $this->assertSame('failed', $this->db->scalar('SELECT status FROM messages WHERE id = ?', [$first['id']]));
        $this->assertSame('needs_reconnect', $this->acc()['status']);
        $this->assertSame(1, count($this->http->requests), 'token errors are not retried');
        $second = $inbox->queueText($conv, null, 'again');
        $this->runQueue();
        $this->assertSame('reconnect', $this->db->scalar('SELECT error_code FROM messages WHERE id = ?', [$second['id']]));
        $this->assertSame(1, count($this->http->requests), 'no call made with a dead token');
    }

    public function testHealthCheckDetectsDeadTokenAndKeepsStatusThroughNetworkErrors(): void
    {
        @unlink(base_path(Scheduler::STAMP));
        $this->db->update('whatsapp_accounts', ['credentials_enc' => \App\Core\Crypto::encryptArray(['access_token' => 'EAAmanual'])], ['id' => $this->acc['id']]);
        $this->http->queue(400, ['error' => ['message' => 'Invalid OAuth access token', 'code' => 190]]);
        $r = Scheduler::run($this->db, true, true, $this->http);
        $this->assertSame(1, $r['health']);
        $this->assertSame('needs_reconnect', $this->acc()['status']);
        $this->http->responses[] = new \RuntimeException('timeout');
        Scheduler::run($this->db, true, true, $this->http);
        $this->assertSame('needs_reconnect', $this->acc()['status'], 'network blip does not hide reconnect state');
        @unlink(base_path(Scheduler::STAMP));
        @unlink(base_path(Scheduler::HEALTH_STAMP));
        @unlink(base_path(Scheduler::TOKEN_STAMP));
    }

    public function testReconnectWithFacebookRestoresAccount(): void
    {
        $this->db->update('whatsapp_accounts', ['phone_number_id' => '8000001', 'business_account_id' => '9000001'], ['id' => $this->acc['id']]);
        $this->monitor()->markNeedsReconnect((int) $this->acc['id'], 'expired');
        $s = $this->sessionFor($this->t['user_id']);
        $page = $this->web('GET', '/whatsapp/connect?account=' . $this->acc['id'], $s);
        $this->assertContains('Reconnecting Main meta_cloud', $page->body);
        $this->http->queue(200, ['access_token' => 'EAANewToken']);
        $this->http->queue(200, ['data' => [['id' => '8000001', 'display_phone_number' => '+91 98765 00001', 'verified_name' => 'Acme']]]);
        $this->http->queue(200, ['success' => true]);
        $this->http->queue(200, ['success' => true]);
        $this->http->queue(200, ['data' => []]);
        $this->http->queue(200, ['data' => ['is_valid' => true, 'expires_at' => 0]]);
        $r = $this->web('POST', '/whatsapp/connect', $s, ['code' => 'AQB', 'waba_id' => '9000001', 'phone_number_id' => '8000001'], true, ['accept' => 'application/json']);
        $this->assertStatus(200, $r);
        $a = $this->acc();
        $this->assertSame('connected', $a['status']);
        $this->assertSame('valid', $a['token_status']);
        $this->assertSame(null, $a['reconnect_notified_at']);
        $this->assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM whatsapp_accounts WHERE tenant_id = ?', [$this->t['tenant_id']]), 'updated in place');
        $this->assertNotContains('Reconnect with Facebook</a>', $this->web('GET', '/', $s)->body);
    }

    public function testAdminSeesTokenSummary(): void
    {
        $this->db->update('whatsapp_accounts', ['token_checked_at' => now(), 'token_expires_at' => '2026-12-01 00:00:00'], ['id' => $this->acc['id']]);
        $admin = $this->db->insert('users', ['tenant_id' => null, 'name' => 'Root', 'email' => 'root@test.local', 'is_super_admin' => 1,
            'password_hash' => \App\Auth\PasswordPolicy::hash('Correct-horse-42'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $r = $this->web('GET', '/admin/meta', $this->sessionFor($admin));
        $this->assertStatus(200, $r);
        $this->assertContains('next: 2026-12-01', $r->body);
        $this->assertContains('Token expiration', $r->body);
    }
}
