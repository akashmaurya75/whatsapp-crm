<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\WhatsApp\WebhookDoctor;
use Tests\TestCase;

final class ReceivingTest extends TestCase
{
    private function payload(string $pnid, string $msgId = 'wamid.A1', string $body = 'Hello'): string
    {
        return (string) json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '7001', 'changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => $pnid],
            'contacts' => [['wa_id' => '919876543210', 'profile' => ['name' => 'Ravi']]],
            'messages' => [['from' => '919876543210', 'id' => $msgId, 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => $body]]],
        ]]]]]]);
    }

    private function deliver(array $acc, string $body, string $secret = 'app-secret-123'): int
    {
        return $this->raw('POST', '/webhooks/whatsapp/' . $acc['webhook_token'], $body,
            ['content-type' => 'application/json', 'x-hub-signature-256' => 'sha256=' . hash_hmac('sha256', $body, $secret)])->status;
    }

    private function row(int $id): array
    {
        return (array) $this->db->first('SELECT * FROM whatsapp_accounts WHERE id = ?', [$id]);
    }

    public function testDeliveriesAndRejectionsAreRecordedOnTheAccount(): void
    {
        $t = $this->tenant('Acme');
        $acc = $this->account($t['tenant_id']);
        $this->assertSame(401, $this->deliver($acc, $this->payload($acc['phone_number_id']), 'wrong-secret'));
        $this->assertContains('signature does not match the App Secret', (string) $this->row((int) $acc['id'])['last_webhook_result']);

        $this->assertSame(200, $this->deliver($acc, $this->payload($acc['phone_number_id'])));
        $this->assertSame('received', $this->row((int) $acc['id'])['last_webhook_result']);
        $this->runQueue();
        $this->assertTrue($this->row((int) $acc['id'])['last_message_in_at'] !== null, 'customer message time recorded');

        // A message for a different phone number ID is explained instead of silently dropped.
        $this->deliver($acc, $this->payload('999999', 'wamid.B2'));
        $this->runQueue();
        $this->assertContains('ignored: message was for phone number ID 999999', (string) $this->row((int) $acc['id'])['last_webhook_result']);
    }

    public function testDoctorSubscribesTheWabaWhenMissingAndChecksTheCallback(): void
    {
        $t = $this->tenant('Acme');
        $acc = $this->account($t['tenant_id'], 'meta_cloud', ['app_id' => '5555']);
        $this->db->update('whatsapp_accounts', ['business_account_id' => '7001'], ['id' => $acc['id']]);
        $acc = $this->row((int) $acc['id']);
        $url = 'https://crm.example.com/webhooks/whatsapp/' . $acc['webhook_token'];

        $this->http->queue(200, ['display_phone_number' => '+91 12345', 'verified_name' => 'Acme', 'platform_type' => 'CLOUD_API']);
        $this->http->queue(200, ['data' => [['id' => $acc['phone_number_id']]]]);
        $this->http->queue(200, ['data' => []]);                       // nobody subscribed
        $this->http->queue(200, ['success' => true]);                  // fix
        $this->http->queue(200, ['data' => [['object' => 'whatsapp_business_account', 'callback_url' => 'https://old.example.com/hook',
            'active' => true, 'fields' => [['name' => 'message_template_status_update']]]]]);
        $checks = (new WebhookDoctor($this->db, $this->http))->diagnose($acc, $url, true);
        $by = [];
        foreach ($checks as $c) {
            $by[$c['label']] = $c;
        }
        $this->assertSame('POST', $this->http->requests[3]['method']);
        $this->assertContains('/7001/subscribed_apps', $this->http->requests[3]['url']);
        $this->assertTrue($by['Your Meta app is subscribed to the WhatsApp Business Account']['fixed']);
        $this->assertSame('fail', $by['Callback URL points to this account']['state']);
        $this->assertContains('old.example.com', $by['Callback URL points to this account']['detail']);
        $this->assertSame('fail', $by['"messages" webhook field subscribed']['state']);
        $this->assertContains('5555|app-secret-123', urldecode($this->http->requests[4]['url']), 'app token used for app-level check');
        $this->assertSame('fail', $by['Meta has delivered to this server']['state']);
    }

    public function testDoctorReportsWrongAppSecretAndWrongIds(): void
    {
        $t = $this->tenant('Acme');
        $acc = $this->account($t['tenant_id'], 'meta_cloud', ['app_id' => '5555']);
        $this->db->update('whatsapp_accounts', ['business_account_id' => '7001'], ['id' => $acc['id']]);
        $acc = $this->row((int) $acc['id']);
        $this->http->queue(200, ['display_phone_number' => '+91 12345']);
        $this->http->queue(200, ['data' => [['id' => '424242']]]);     // number not in this WABA
        $this->http->queue(200, ['data' => [['whatsapp_business_api_data' => ['id' => '5555', 'name' => 'CRM']]]]);
        $this->http->queue(400, ['error' => ['message' => 'Invalid OAuth access token signature.']]);
        $checks = (new WebhookDoctor($this->db, $this->http))->diagnose($acc, 'https://x.test/w', false);
        $states = array_column($checks, 'state', 'label');
        $this->assertSame('fail', $states['Phone number belongs to this WhatsApp Business Account']);
        $this->assertSame('ok', $states['Your Meta app is subscribed to the WhatsApp Business Account']);
        $this->assertSame('fail', $states['App ID and App Secret match']);
        $this->assertSame(4, count($this->http->requests), 'no fix attempted when already subscribed');
    }

    public function testCheckAndFixButtonShowsResults(): void
    {
        $t = $this->tenant('Acme');
        $acc = $this->account($t['tenant_id']);
        $s = $this->sessionFor($t['user_id']);
        $this->http->queue(401, ['error' => ['message' => 'Invalid OAuth access token.']]);
        $r = $this->web('POST', '/whatsapp/' . $acc['id'] . '/diagnose', $s);
        $this->assertSame('/whatsapp/' . $acc['id'] . '#receiving', $r->headers['Location']);
        $page = $this->web('GET', '/whatsapp/' . $acc['id'], $s)->body;
        $this->assertContains('Receiving messages', $page);
        $this->assertContains('Invalid OAuth access token', $page);
        // Agents can't run it.
        $agent = $this->user($t['tenant_id'], 'support_agent', 'agent@acme.test');
        $this->assertStatus(403, $this->web('POST', '/whatsapp/' . $acc['id'] . '/diagnose', $this->sessionFor($agent)));
    }

    public function testInboxListRefreshesWhenANewChatArrives(): void
    {
        $t = $this->tenant('Acme');
        $acc = $this->account($t['tenant_id']);
        $s = $this->sessionFor($t['user_id']);
        $first = json_decode($this->web('GET', '/inbox/updates', $s)->body, true);
        $this->assertTrue(isset($first['html'], $first['stamp']));
        $same = json_decode($this->web('GET', '/inbox/updates?stamp=' . $first['stamp'], $s)->body, true);
        $this->assertFalse(isset($same['html']), 'nothing re-sent when unchanged');

        $this->deliver($acc, $this->payload($acc['phone_number_id'], 'wamid.N1', 'Is the bus on time?'));
        $this->runQueue();
        $next = json_decode($this->web('GET', '/inbox/updates?stamp=' . $first['stamp'], $s)->body, true);
        $this->assertTrue(isset($next['html']));
        $this->assertContains('Is the bus on time?', $next['html']);
        $this->assertContains('is-unread', $next['html']);
        $this->assertSame(1, $next['unread']);
        $this->assertContains('data-list-poll="/inbox/updates', $this->web('GET', '/inbox', $s)->body);

        // Other companies' chats never leak into the list.
        $other = $this->tenant('Other');
        $o = json_decode($this->web('GET', '/inbox/updates', $this->sessionFor($other['user_id']))->body, true);
        $this->assertNotContains('Is the bus on time?', (string) ($o['html'] ?? ''));
    }
}
