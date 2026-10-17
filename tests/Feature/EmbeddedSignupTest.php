<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\WhatsApp\AccountRepository;
use App\WhatsApp\MetaPlatform;
use Tests\TestCase;

final class EmbeddedSignupTest extends TestCase
{
    private const SECRET = '0123456789abcdef0123456789abcdef';
    private array $t;

    public function setUp(): void
    {
        parent::setUp();
        $this->t = $this->tenant('Acme', 'owner@acme.test');
    }

    private function configure(): void
    {
        MetaPlatform::save($this->db, ['app_id' => '111222333', 'app_secret' => self::SECRET, 'config_id' => '444555666', 'verify_token' => 'plat-verify'], null);
    }

    /** Queue the Graph responses for a successful onboarding with session info. */
    private function queueHappyPath(string $waba = '9000001', string $pnid = '8000001', bool $registerOk = true): void
    {
        $this->http->queue(200, ['access_token' => 'EAABusinessToken', 'token_type' => 'bearer']);
        $this->http->queue(200, ['data' => [['id' => $pnid, 'display_phone_number' => '+91 98765 00001', 'verified_name' => 'Acme Traders', 'quality_rating' => 'GREEN']]]);
        $this->http->queue(200, ['success' => true]);
        $this->http->queue($registerOk ? 200 : 400, $registerOk ? ['success' => true] : ['error' => ['message' => 'Two step verification PIN Mismatch', 'code' => 133005]]);
        $this->http->queue(200, ['data' => [['id' => '55', 'name' => 'hello_world', 'language' => 'en_US', 'status' => 'APPROVED', 'category' => 'UTILITY',
            'components' => [['type' => 'BODY', 'text' => 'Hello World']]]]]);
    }

    private function start(array $body, ?\App\Core\Session $s = null): array
    {
        $s ??= $this->sessionFor($this->t['user_id']);
        $r = $this->web('POST', '/whatsapp/connect', $s, $body, true, ['accept' => 'application/json']);
        return [$r, json_decode($r->body, true) ?: []];
    }

    public function testConnectPageStatesAndSecurityHeaders(): void
    {
        $s = $this->sessionFor($this->t['user_id']);
        $r = $this->web('GET', '/whatsapp/connect', $s);
        $this->assertStatus(200, $r);
        $this->assertContains("isn't set up yet", $r->body);
        $this->configure();
        $r = $this->web('GET', '/whatsapp/connect', $s);
        $this->assertContains('Continue with Facebook', $r->body);
        $this->assertContains('data-config-id="444555666"', $r->body);
        $this->assertNotContains(self::SECRET, $r->body, 'secret never reaches the browser');
        $this->assertContains('https://connect.facebook.net', $r->headers['Content-Security-Policy']);
        $this->assertSame('same-origin-allow-popups', $r->headers['Cross-Origin-Opener-Policy']);
        // Everywhere else keeps the strict policy.
        $other = $this->web('GET', '/whatsapp', $s);
        $this->assertNotContains('facebook', $other->headers['Content-Security-Policy']);
        $this->assertSame('same-origin', $other->headers['Cross-Origin-Opener-Policy']);
        $agent = $this->sessionFor($this->user($this->t['tenant_id'], 'support_agent', 'ag@acme.test'));
        $this->assertStatus(403, $this->web('GET', '/whatsapp/connect', $agent));
    }

    public function testFullOnboardingCreatesManagedAccountAndRoutesWebhooks(): void
    {
        $this->configure();
        $this->queueHappyPath();
        [$r, $json] = $this->start(['code' => 'AQB-code', 'waba_id' => '9000001', 'phone_number_id' => '8000001']);
        $this->assertStatus(200, $r);
        $this->assertTrue($json['ok'] === true && $json['status'] === 'connected', json_encode($json));
        $acc = $this->db->first('SELECT * FROM whatsapp_accounts');
        $this->assertSame('/whatsapp/' . $acc['id'], $json['redirect']);
        $this->assertSame('Acme Traders', $acc['name']);
        $this->assertSame('+919876500001', $acc['phone_e164']);
        $this->assertSame('9000001', $acc['business_account_id']);
        $this->assertSame('connected', $acc['status']);
        $this->assertNotContains('EAABusinessToken', (string) $acc['credentials_enc'], 'token encrypted at rest');
        $creds = AccountRepository::credentials($acc);
        $this->assertTrue(AccountRepository::isManaged($acc));
        $this->assertSame('EAABusinessToken', $creds['access_token']);
        $this->assertSame(self::SECRET, $creds['app_secret'], 'platform secret merged at read time');
        $this->assertSame(1, (int) $this->db->scalar("SELECT COUNT(*) FROM message_templates WHERE name = 'hello_world' AND status = 'approved'"));

        [$ex, $pn, $sub, $reg] = $this->http->requests;
        $this->assertContains('/v25.0/oauth/access_token?client_id=111222333&client_secret=' . self::SECRET . '&code=AQB-code', $ex['url']);
        $this->assertContains('/9000001/phone_numbers', $pn['url']);
        $this->assertSame('Bearer EAABusinessToken', $pn['headers']['Authorization']);
        $this->assertContains('/9000001/subscribed_apps', $sub['url']);
        $this->assertSame('POST', $sub['method']);
        $this->assertContains('/8000001/register', $reg['url']);
        $regBody = json_decode((string) $reg['body'], true);
        $this->assertSame('whatsapp', $regBody['messaging_product']);
        $this->assertTrue(preg_match('/^\d{6}$/', $regBody['pin']) === 1);
        $this->assertSame($regBody['pin'], $creds['pin'], 'PIN kept (encrypted) for the business');
        $this->assertSame(1, (int) $this->db->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'whatsapp_account.connected_facebook'"));

        // Inbound message through the platform webhook (signed with the platform app secret).
        $body = (string) json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '9000001', 'changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => '8000001'], 'contacts' => [['wa_id' => '919811111111', 'profile' => ['name' => 'Ravi']]],
            'messages' => [['from' => '919811111111', 'id' => 'wamid.P1', 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => 'Hi from platform']]],
        ]]]]]]);
        $this->assertStatus(401, $this->raw('POST', '/webhooks/meta', $body, ['x-hub-signature-256' => 'sha256=' . hash_hmac('sha256', $body, 'wrong')]));
        $this->assertStatus(200, $this->raw('POST', '/webhooks/meta', $body, ['x-hub-signature-256' => 'sha256=' . hash_hmac('sha256', $body, self::SECRET)]));
        $this->runQueue();
        $this->assertSame('Hi from platform', $this->db->scalar('SELECT body FROM messages WHERE direction = \'in\''));
        $this->assertSame($this->t['tenant_id'], (int) $this->db->scalar('SELECT tenant_id FROM contacts WHERE phone_e164 = ?', ['+919811111111']));
    }

    public function testPlatformWebhookVerificationAndRouting(): void
    {
        $this->configure();
        $ok = $this->raw('GET', '/webhooks/meta?hub_mode=subscribe&hub_verify_token=plat-verify&hub_challenge=777', '');
        $this->assertStatus(200, $ok);
        $this->assertSame('777', $ok->body);
        $this->assertStatus(403, $this->raw('GET', '/webhooks/meta?hub_mode=subscribe&hub_verify_token=nope&hub_challenge=777', ''));
        // Events for a number nobody connected are accepted (200) but go nowhere.
        $body = (string) json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '123', 'changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => '999'], 'messages' => [['from' => '1', 'id' => 'x', 'type' => 'text', 'text' => ['body' => 'x']]]]]]]]]);
        $this->assertStatus(200, $this->raw('POST', '/webhooks/meta', $body, ['x-hub-signature-256' => 'sha256=' . hash_hmac('sha256', $body, self::SECRET)]));
        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM jobs'));
    }

    public function testNumberMustBelongToTheSharedAccount(): void
    {
        $this->configure();
        $this->http->queue(200, ['access_token' => 'EAAtok']);
        $this->http->queue(200, ['data' => [['id' => '8000002', 'display_phone_number' => '+1 555 0100', 'verified_name' => 'X']]]);
        [$r, $json] = $this->start(['code' => 'c', 'waba_id' => '9000001', 'phone_number_id' => '8000001']);
        $this->assertStatus(422, $r);
        $this->assertContains('does not belong', $json['error']);
        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM whatsapp_accounts'));
    }

    public function testExpiredCodeGivesClearError(): void
    {
        $this->configure();
        $this->http->queue(400, ['error' => ['message' => 'This authorization code has expired.', 'code' => 100]]);
        [$r, $json] = $this->start(['code' => 'old', 'waba_id' => '9000001', 'phone_number_id' => '8000001']);
        $this->assertStatus(422, $r);
        $this->assertContains('30 seconds', $json['error']);
        $this->assertContains('expired', $json['error']);
    }

    public function testWithoutSessionInfoMultipleNumbersAskTheUserToChoose(): void
    {
        $this->configure();
        $s = $this->sessionFor($this->t['user_id']);
        $this->http->queue(200, ['access_token' => 'EAAtok']);
        $this->http->queue(200, ['data' => ['granular_scopes' => [['scope' => 'whatsapp_business_management', 'target_ids' => ['9000001']],
            ['scope' => 'business_management', 'target_ids' => ['1']]]]]);
        $this->http->queue(200, ['data' => [
            ['id' => '8000001', 'display_phone_number' => '+91 98765 00001', 'verified_name' => 'Acme One'],
            ['id' => '8000002', 'display_phone_number' => '+91 98765 00002', 'verified_name' => 'Acme Two'],
        ]]);
        [$r, $json] = $this->start(['code' => 'c'], $s);
        $this->assertSame('choose', $json['status']);
        $this->assertContains('/debug_token?input_token=EAAtok&access_token=111222333%7C' . self::SECRET, $this->http->requests[1]['url']);
        $page = $this->web('GET', '/whatsapp/connect', $s)->body;
        $this->assertContains('Which number do you want to connect?', $page);
        $this->assertContains('Acme Two', $page);
        $this->assertNotContains('EAAtok', $page);
        // Picking a number that wasn't offered is refused; picking a listed one finishes.
        $this->web('POST', '/whatsapp/connect/choose', $s, ['phone_number_id' => '8000009']);
        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM whatsapp_accounts'));
        $this->http->queue(200, ['success' => true]);
        $this->http->queue(200, ['success' => true]);
        $this->http->queue(200, ['data' => []]);
        $r = $this->web('POST', '/whatsapp/connect/choose', $s, ['phone_number_id' => '8000002']);
        $this->assertSame('Acme Two', $this->db->scalar('SELECT name FROM whatsapp_accounts'));
        $this->assertContains('/whatsapp/', $r->headers['Location']);
        $this->assertSame(null, $s->get('meta_choice'), 'token removed from session after use');
    }

    public function testNumberOwnedByAnotherCompanyIsRefusedAndReconnectUpdates(): void
    {
        $this->configure();
        $this->queueHappyPath();
        $this->start(['code' => 'a', 'waba_id' => '9000001', 'phone_number_id' => '8000001']);
        $this->assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM whatsapp_accounts'));
        // Same company reconnects: account is updated, not duplicated.
        $this->queueHappyPath();
        [, $json] = $this->start(['code' => 'b', 'waba_id' => '9000001', 'phone_number_id' => '8000001']);
        $this->assertTrue($json['ok']);
        $this->assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM whatsapp_accounts'));
        // Another company tries to take the same number.
        $other = $this->tenant('Other', 'o@other.test');
        $this->http->queue(200, ['access_token' => 'EAAother']);
        $this->http->queue(200, ['data' => [['id' => '8000001', 'display_phone_number' => '+91 98765 00001', 'verified_name' => 'Acme Traders']]]);
        [$r, $json] = $this->start(['code' => 'c', 'waba_id' => '9000001', 'phone_number_id' => '8000001'], $this->sessionFor($other['user_id']));
        $this->assertStatus(422, $r);
        $this->assertContains('another company', $json['error']);
        $this->assertSame($this->t['tenant_id'], (int) $this->db->scalar('SELECT tenant_id FROM whatsapp_accounts'));
    }

    public function testRegistrationProblemIsAWarningNotAFailure(): void
    {
        $this->configure();
        $this->queueHappyPath('9000001', '8000001', false);
        [, $json] = $this->start(['code' => 'a', 'waba_id' => '9000001', 'phone_number_id' => '8000001', 'pin' => '123456']);
        $this->assertTrue($json['ok']);
        $this->assertSame('123456', json_decode((string) $this->http->requests[3]['body'], true)['pin'], 'user-supplied PIN used');
        $this->assertContains('PIN Mismatch', (string) $this->db->scalar('SELECT last_error FROM whatsapp_accounts'));
    }

    public function testPlanLimitOnWhatsAppNumbers(): void
    {
        $this->configure();
        $plan = $this->db->insert('subscription_plans', ['code' => 'one', 'name' => 'One', 'price_cents' => 0, 'currency' => 'USD', 'billing_interval' => 'month',
            'max_whatsapp_accounts' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->db->update('tenants', ['plan_id' => $plan], ['id' => $this->t['tenant_id']]);
        $this->account($this->t['tenant_id']);
        $this->http->queue(200, ['access_token' => 'EAAtok']);
        $this->http->queue(200, ['data' => [['id' => '8000001', 'display_phone_number' => '+91 98765 00001', 'verified_name' => 'Acme']]]);
        [$r, $json] = $this->start(['code' => 'a', 'waba_id' => '9000001', 'phone_number_id' => '8000001']);
        $this->assertStatus(422, $r);
        $this->assertContains('plan allows 1', $json['error']);
    }

    public function testAdminMetaSettingsPage(): void
    {
        $adminId = $this->db->insert('users', ['tenant_id' => null, 'name' => 'Root', 'email' => 'root@platform.test', 'password_hash' => 'x',
            'is_super_admin' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $admin = $this->sessionFor($adminId);
        $page = $this->web('GET', '/admin/meta', $admin);
        $this->assertStatus(200, $page);
        $this->assertContains('/webhooks/meta', $page->body);
        $verify = MetaPlatform::config($this->db)['verify_token'];
        $this->assertTrue(strlen($verify) >= 24, 'verify token generated automatically');
        $this->web('POST', '/admin/meta', $admin, ['app_id' => 'abc', 'app_secret' => 'short', 'config_id' => '1']);
        $this->assertFalse(MetaPlatform::signupReady($this->db), 'invalid values rejected');
        $this->web('POST', '/admin/meta', $admin, ['app_id' => '111222333', 'app_secret' => self::SECRET, 'config_id' => '444555666']);
        MetaPlatform::resetCache();
        $this->assertTrue(MetaPlatform::signupReady($this->db));
        $this->assertNotContains(self::SECRET, (string) $this->db->scalar("SELECT value_enc FROM platform_settings WHERE setting_key = 'meta.app_secret'"));
        $this->assertNotContains(self::SECRET, $this->web('GET', '/admin/meta', $admin)->body, 'secret not echoed');
        $this->http->queue(200, ['name' => 'Acme CRM', 'id' => '111222333']);
        $this->web('POST', '/admin/meta/test', $admin);
        $this->assertContains('111222333%7C' . self::SECRET, $this->http->requests[0]['url']);
        $this->assertStatus(404, $this->web('GET', '/admin/meta', $this->sessionFor($this->t['user_id'])));
    }
}
