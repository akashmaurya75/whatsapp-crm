<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\PasswordPolicy;
use App\Core\Env;
use App\WhatsApp\MetaPlatform;
use Tests\TestCase;

final class MetaAutoConfigureTest extends TestCase
{
    private const SECRET = '0123456789abcdef0123456789abcdef';
    private \App\Core\Session $admin;

    public function setUp(): void
    {
        parent::setUp();
        Env::set('APP_URL', 'https://crm.example.com');
        $id = $this->db->insert('users', ['tenant_id' => null, 'name' => 'Root', 'email' => 'root@test.local', 'is_super_admin' => 1,
            'password_hash' => PasswordPolicy::hash('Correct-horse-42'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->admin = $this->sessionFor($id);
    }

    private function save(array $post): \App\Core\Response
    {
        return $this->web('POST', '/admin/meta', $this->admin, $post + ['app_id' => '111222333', 'app_secret' => self::SECRET, 'config_id' => '444555666']);
    }

    private function page(): string
    {
        return $this->web('GET', '/admin/meta', $this->admin)->body;
    }

    public function testSaveAndAutoConfigureRegistersWebhookWithoutCopying(): void
    {
        $this->http->queue(200, ['name' => 'Acme CRM', 'app_domains' => ['crm.example.com'], 'id' => '111222333']);
        $this->http->queue(200, ['success' => true]);
        $this->http->queue(200, ['data' => [['object' => 'whatsapp_business_account', 'callback_url' => 'https://crm.example.com/webhooks/meta', 'active' => true,
            'fields' => [['name' => 'messages', 'version' => 'v25.0'], ['name' => 'message_template_status_update'], ['name' => 'account_update']]]]]);
        $r = $this->save(['autoconfigure' => '1']);
        $this->assertStatus(302, $r);
        $this->assertSame('444555666', MetaPlatform::config($this->db)['config_id'], 'settings saved first');

        $sub = $this->http->requests[1];
        $this->assertSame('POST', $sub['method']);
        $this->assertContains('/v25.0/111222333/subscriptions', $sub['url']);
        parse_str((string) $sub['body'], $form);
        $verify = MetaPlatform::config($this->db)['verify_token'];
        $this->assertTrue($verify !== '', 'verify token generated');
        $this->assertSame('whatsapp_business_account', $form['object']);
        $this->assertSame('https://crm.example.com/webhooks/meta', $form['callback_url']);
        $this->assertSame($verify, $form['verify_token'], 'the token Meta is given is the one /webhooks/meta expects');
        $this->assertSame('messages,message_template_status_update,account_update', $form['fields']);
        $this->assertSame('111222333|' . self::SECRET, $form['access_token']);

        // Meta's verification call succeeds against our own endpoint with that token.
        $challenge = $this->web('GET', '/webhooks/meta?hub.mode=subscribe&hub.verify_token=' . rawurlencode($verify) . '&hub.challenge=abc123');
        $this->assertSame('abc123', $challenge->body);

        $body = $this->page();
        $this->assertContains('Auto-configure result', $body);
        $this->assertContains('Meta verified https://crm.example.com/webhooks/meta', $body);
        $this->assertContains('Subscribed to messages, message_template_status_update, account_update', $body);
        $this->assertContains('crm.example.com is listed under App domains', $body);
        $this->assertNotContains(self::SECRET, $body, 'secret never rendered');
        $this->assertSame(1, (int) $this->db->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'admin.meta_autoconfigured'"));
    }

    public function testSaveOnlyDoesNotCallMeta(): void
    {
        $this->assertStatus(302, $this->save([]));
        $this->assertSame(0, count($this->http->requests));
        $this->assertNotContains('Auto-configure result', $this->page());
    }

    public function testRejectedCredentialsStopEarly(): void
    {
        $this->http->queue(400, ['error' => ['message' => 'Error validating client secret.', 'code' => 1]]);
        $this->save(['autoconfigure' => '1']);
        $this->assertSame(1, count($this->http->requests), 'nothing registered with bad credentials');
        $body = $this->page();
        $this->assertContains('Meta rejected them: Error validating client secret.', $body);
        $this->assertContains('/apps/111222333/settings/basic/', $body);
    }

    public function testVerificationFailureExplainsReachability(): void
    {
        $this->http->queue(200, ['name' => 'Acme CRM']);
        $this->http->queue(400, ['error' => ['message' => 'The URL couldn\'t be validated. Callback verification failed', 'code' => 2200]]);
        $this->save(['autoconfigure' => '1']);
        $this->assertSame(2, count($this->http->requests));
        $body = $this->page();
        $this->assertContains('Callback verification failed', $body);
        $this->assertContains('opens from the internet', $body);
    }

    public function testNonHttpsAppUrlIsReportedBeforeRegistering(): void
    {
        Env::set('APP_URL', 'http://localhost:8000');
        $this->http->queue(200, ['name' => 'Acme CRM']);
        $this->save(['autoconfigure' => '1']);
        $this->assertSame(1, count($this->http->requests));
        $this->assertContains('public https:// address', $this->page());
    }

    public function testMissingDomainAndFieldsAreWarnings(): void
    {
        $this->http->queue(200, ['name' => 'Acme CRM', 'app_domains' => ['old-host.example.net']]);
        $this->http->queue(200, ['success' => true]);
        $this->http->queue(200, ['data' => [['object' => 'whatsapp_business_account', 'active' => true, 'fields' => ['messages']]]]);
        MetaPlatform::save($this->db, ['app_id' => '111222333', 'app_secret' => self::SECRET], null);
        $this->web('POST', '/admin/meta/autoconfigure', $this->admin);
        $body = $this->page();
        $this->assertContains('Not subscribed yet: message_template_status_update, account_update', $body);
        $this->assertContains('Add crm.example.com under App settings', $body);
    }

    public function testCompanyUsersCannotRunIt(): void
    {
        $t = $this->tenant('Acme', 'owner@acme.test');
        // Admin routes are hidden from company users (404, like the rest of /admin).
        $this->assertStatus(404, $this->web('POST', '/admin/meta/autoconfigure', $this->sessionFor($t['user_id'])));
        $this->assertSame(0, count($this->http->requests));
    }
}
