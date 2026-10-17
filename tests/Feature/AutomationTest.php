<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Automation\AutomationService;
use App\Domain\ValidationException;
use App\Tenancy\TenantContext;
use Tests\TestCase;

final class AutomationTest extends TestCase
{
    private array $t;
    private array $acc;
    private AutomationService $svc;

    public function setUp(): void
    {
        parent::setUp();
        $this->t = $this->tenant('Acme', 'owner@acme.test');
        $this->acc = $this->account($this->t['tenant_id']);
        $ctx = new TenantContext();
        $ctx->set($this->t['tenant_id']);
        $this->svc = new AutomationService($this->db, $ctx, $this->app()->queue);
    }

    private function inbound(string $id, string $body, string $from = '919876500001'): void
    {
        $payload = (string) json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => 'W', 'changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => $this->acc['phone_number_id']], 'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Priya Shah']]],
            'messages' => [['from' => $from, 'id' => $id, 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => $body]]],
        ]]]]]]);
        $this->raw('POST', '/webhooks/whatsapp/' . $this->acc['webhook_token'], $payload,
            ['content-type' => 'application/json', 'x-hub-signature-256' => 'sha256=' . hash_hmac('sha256', $payload, 'app-secret-123')]);
        $this->runQueue();
    }

    /** @return list<string> outbound auto-reply bodies, oldest first */
    private function autoReplies(): array
    {
        return array_column($this->db->all("SELECT body FROM messages WHERE direction = 'out' AND sent_by_user_id IS NULL ORDER BY id"), 'body');
    }

    private function closedAllWeek(): array
    {
        $h = [];
        foreach (array_keys(AutomationService::DAYS) as $d) {
            $h[$d] = ['start' => '09:00', 'end' => '18:00'];
        }
        return $h;
    }

    public function testWelcomeIsSentOnceToNewContacts(): void
    {
        $this->svc->save(['type' => 'welcome', 'is_active' => '1', 'reply' => ['type' => 'text', 'text' => 'Welcome to Acme! 👋']]);
        $this->http->queue(200, ['messages' => [['id' => 'wamid.W1']]]);
        $this->inbound('wamid.a1', 'Hi');
        $this->inbound('wamid.a2', 'Hello again');
        $this->assertSame(['Welcome to Acme! 👋'], $this->autoReplies());
        $this->assertSame('submitted', $this->db->scalar("SELECT status FROM messages WHERE body = 'Welcome to Acme! 👋'"));
    }

    public function testAwayReplyOutsideHoursWithCooldown(): void
    {
        $this->svc->save(['type' => 'away', 'is_active' => '1', 'timezone' => 'Asia/Kolkata', 'hours' => $this->closedAllWeek(), 'cooldown_minutes' => '240',
            'reply' => ['type' => 'text', 'text' => "We're closed now, we'll reply tomorrow."]]);
        $this->http->queue(200, ['messages' => [['id' => 'wamid.A1']]]);
        $this->inbound('wamid.b1', 'Are you open?');
        $this->inbound('wamid.b2', 'Hello??');
        $this->assertSame(["We're closed now, we'll reply tomorrow."], $this->autoReplies(), 'cooldown prevents repeats');
    }

    public function testBusinessHoursCalculation(): void
    {
        $cfg = ['timezone' => 'Asia/Kolkata', 'hours' => AutomationService::defaultHours()];
        // Monday 2026-10-12 10:00 IST = 04:30 UTC → open; 20:00 IST → closed; Sunday → closed.
        $this->assertTrue(AutomationService::isOpen($cfg, (int) strtotime('2026-10-12 04:30:00 UTC')));
        $this->assertFalse(AutomationService::isOpen($cfg, (int) strtotime('2026-10-12 14:30:00 UTC')));
        $this->assertFalse(AutomationService::isOpen($cfg, (int) strtotime('2026-10-11 06:00:00 UTC')));
        $night = ['timezone' => 'UTC', 'hours' => ['mon' => ['open' => true, 'start' => '22:00', 'end' => '06:00']]];
        $this->assertTrue(AutomationService::isOpen($night, (int) strtotime('2026-10-12 23:00:00 UTC')));
    }

    public function testKeywordReplyAndActions(): void
    {
        $team = $this->db->insert('teams', ['tenant_id' => $this->t['tenant_id'], 'name' => 'Sales', 'created_at' => now(), 'updated_at' => now()]);
        $this->svc->save(['type' => 'keyword', 'is_active' => '1', 'name' => 'Pricing', 'keywords' => 'price, rate', 'match' => 'contains',
            'reply' => ['type' => 'text', 'text' => 'Our price list: https://acme.example.com/prices'],
            'actions' => ['add_tags' => 'price-enquiry', 'assign_team_id' => (string) $team, 'lead_status' => 'qualified']]);
        $this->http->queue(200, ['messages' => [['id' => 'wamid.K1']]]);
        $this->inbound('wamid.c1', 'What is the PRICE of the blue one?');
        $this->assertSame(['Our price list: https://acme.example.com/prices'], $this->autoReplies());
        $contact = $this->db->first('SELECT * FROM contacts');
        $this->assertSame('qualified', $contact['lead_status']);
        $this->assertSame('price-enquiry', $this->db->scalar('SELECT t.name FROM contact_tags ct JOIN tags t ON t.id = ct.tag_id WHERE ct.contact_id = ?', [$contact['id']]));
        $this->assertSame($team, (int) $this->db->scalar('SELECT assigned_team_id FROM conversations'));
        // Whole-word match: "pricey" or "enterprise" must not trigger.
        $this->assertFalse(AutomationService::matches(['keywords' => ['price'], 'match' => 'contains'], 'that is pricey'));
        $this->assertTrue(AutomationService::matches(['keywords' => ['order status'], 'match' => 'contains'], 'my Order  Status please'));
        $this->assertTrue(AutomationService::matches(['keywords' => ['hi'], 'match' => 'exact'], ' HI '));
    }

    public function testOptedOutContactsGetNoAutoReplies(): void
    {
        $this->svc->save(['type' => 'welcome', 'is_active' => '1', 'reply' => ['type' => 'text', 'text' => 'Welcome!']]);
        $this->svc->save(['type' => 'keyword', 'is_active' => '1', 'keywords' => 'stop', 'match' => 'exact', 'reply' => ['type' => 'text', 'text' => 'ok']]);
        $this->inbound('wamid.d1', 'STOP');
        $this->assertSame([], $this->autoReplies());
        $this->assertSame(0, count($this->http->requests));
    }

    public function testInactiveRulesAndPerMessageLimit(): void
    {
        $id = $this->svc->save(['type' => 'welcome', 'is_active' => '1', 'reply' => ['type' => 'text', 'text' => 'Welcome!']]);
        $this->svc->save(['type' => 'keyword', 'is_active' => '1', 'keywords' => 'hi', 'match' => 'contains', 'reply' => ['type' => 'text', 'text' => 'Hi there!']]);
        $this->svc->save(['type' => 'away', 'is_active' => '1', 'timezone' => 'UTC', 'hours' => $this->closedAllWeek(), 'reply' => ['type' => 'text', 'text' => 'Closed']]);
        $this->http->queue(200, ['messages' => [['id' => 'wamid.L1']]]);
        $this->http->queue(200, ['messages' => [['id' => 'wamid.L2']]]);
        $this->inbound('wamid.e1', 'hi');
        $this->assertSame(['Hi there!', 'Welcome!'], $this->autoReplies(), 'max 2 replies, away not added on top of welcome');
        $this->svc->toggle($id);
        $this->assertSame(0, (int) $this->svc->find($id)['is_active']);
    }

    public function testValidation(): void
    {
        $this->assertThrows(ValidationException::class, fn () => $this->svc->save(['type' => 'keyword', 'keywords' => '', 'reply' => ['type' => 'text', 'text' => 'x']]));
        $this->assertThrows(ValidationException::class, fn () => $this->svc->save(['type' => 'keyword', 'keywords' => 'x', 'reply' => ['type' => 'text', 'text' => '']]));
        $this->assertThrows(ValidationException::class, fn () => $this->svc->save(['type' => 'welcome', 'reply' => ['type' => 'text', 'text' => '']]));
        $this->assertThrows(ValidationException::class, fn () => $this->svc->save(['type' => 'away', 'timezone' => 'Nowhere/City', 'reply' => ['type' => 'text', 'text' => 'x']]));
        $this->svc->save(['type' => 'welcome', 'reply' => ['type' => 'text', 'text' => 'Hi']]);
        $this->assertThrows(ValidationException::class, fn () => $this->svc->save(['type' => 'welcome', 'reply' => ['type' => 'text', 'text' => 'Again']]));
        $other = $this->tenant('Other', 'o@other.test');
        $foreignTeam = $this->db->insert('teams', ['tenant_id' => $other['tenant_id'], 'name' => 'X', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertThrows(ValidationException::class, fn () => $this->svc->save(['type' => 'keyword', 'keywords' => 'x',
            'reply' => ['type' => 'text', 'text' => 'y'], 'actions' => ['assign_team_id' => (string) $foreignTeam]]));
    }

    public function testWebPagesAndIsolation(): void
    {
        $owner = $this->sessionFor($this->t['user_id']);
        $this->assertStatus(200, $this->web('GET', '/automations', $owner));
        $this->assertStatus(200, $this->web('GET', '/automations/new?type=away', $owner));
        $this->web('POST', '/automations', $owner, ['type' => 'keyword', 'is_active' => '1', 'name' => 'Hours', 'keywords' => 'timing', 'match' => 'contains',
            'reply' => ['type' => 'text', 'text' => 'We are open 9 to 6.']]);
        $id = (int) $this->db->scalar("SELECT id FROM automation_rules WHERE name = 'Hours'");
        $this->assertTrue($id > 0);
        $this->assertStatus(200, $this->web('GET', '/automations/' . $id, $owner));
        $agent = $this->sessionFor($this->user($this->t['tenant_id'], 'support_agent', 'ag@acme.test'));
        $this->assertStatus(403, $this->web('GET', '/automations', $agent));
        $other = $this->tenant('Other', 'o@other.test');
        $this->assertStatus(404, $this->web('POST', '/automations/' . $id . '/delete', $this->sessionFor($other['user_id'])));
        $this->assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM automation_rules WHERE id = ?', [$id]));
    }
}
