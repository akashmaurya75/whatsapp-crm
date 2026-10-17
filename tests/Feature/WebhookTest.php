<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class WebhookTest extends TestCase
{
    private function metaPayload(array $account, array $messages = [], array $statuses = [], string $name = 'Ravi'): string
    {
        return (string) json_encode(['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => 'WABA', 'changes' => [['field' => 'messages', 'value' => [
                'messaging_product' => 'whatsapp',
                'metadata' => ['display_phone_number' => '911234567890', 'phone_number_id' => $account['phone_number_id']],
                'contacts' => [['wa_id' => '919876543210', 'profile' => ['name' => $name]]],
                'messages' => $messages, 'statuses' => $statuses,
            ]]],
        ]]]);
    }

    private function textMsg(string $id, string $body, int $ts = 0): array
    {
        return ['from' => '919876543210', 'id' => $id, 'timestamp' => (string) ($ts ?: time()), 'type' => 'text', 'text' => ['body' => $body]];
    }

    private function post(array $account, string $body, ?string $secret = 'app-secret-123'): \App\Core\Response
    {
        $headers = ['content-type' => 'application/json'];
        if ($secret !== null) {
            $headers['x-hub-signature-256'] = 'sha256=' . hash_hmac('sha256', $body, $secret);
        }
        return $this->raw('POST', '/webhooks/whatsapp/' . $account['webhook_token'], $body, $headers);
    }

    public function testMetaVerificationChallenge(): void
    {
        $t = $this->tenant('Acme');
        $acc = $this->account($t['tenant_id']);
        $ok = $this->raw('GET', '/webhooks/whatsapp/' . $acc['webhook_token'] . '?hub_mode=subscribe&hub_verify_token=verify-me&hub_challenge=12345', '');
        $this->assertStatus(200, $ok);
        $this->assertSame('12345', $ok->body);
        $bad = $this->raw('GET', '/webhooks/whatsapp/' . $acc['webhook_token'] . '?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=12345', '');
        $this->assertStatus(403, $bad);
        $this->assertStatus(404, $this->raw('GET', '/webhooks/whatsapp/' . str_repeat('a', 43), ''));
    }

    public function testInvalidSignatureIsRejected(): void
    {
        $t = $this->tenant('Acme');
        $acc = $this->account($t['tenant_id']);
        $body = $this->metaPayload($acc, [$this->textMsg('wamid.1', 'hi')]);
        $this->assertStatus(401, $this->post($acc, $body, 'wrong-secret'));
        $this->assertStatus(401, $this->post($acc, $body, null));
        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM jobs'));
    }

    public function testInboundMessageIsStoredOnceDespiteDuplicateDeliveries(): void
    {
        $t = $this->tenant('Acme');
        $acc = $this->account($t['tenant_id']);
        $body = $this->metaPayload($acc, [$this->textMsg('wamid.ABC', 'Hello, is this in stock?')]);
        $this->assertStatus(200, $this->post($acc, $body));
        $this->assertStatus(200, $this->post($acc, $body)); // Meta retry
        $this->assertSame(2, (int) $this->db->scalar('SELECT COUNT(*) FROM jobs'), 'processing is asynchronous');
        $this->runQueue();
        $this->assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM messages'));
        $contact = $this->db->first('SELECT * FROM contacts');
        $this->assertSame('+919876543210', $contact['phone_e164']);
        $this->assertSame('Ravi', $contact['name']);
        $this->assertSame($t['tenant_id'], (int) $contact['tenant_id']);
        $conv = $this->db->first('SELECT * FROM conversations');
        $this->assertSame(1, (int) $conv['unread_count']);
        $this->assertSame('Hello, is this in stock?', $conv['last_message_preview']);
        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM jobs'), 'payload removed after processing');
    }

    public function testEventsForAnotherPhoneNumberIdAreIgnored(): void
    {
        $t = $this->tenant('Acme');
        $acc = $this->account($t['tenant_id']);
        $other = $acc;
        $other['phone_number_id'] = '999999';
        $this->post($acc, $this->metaPayload($other, [$this->textMsg('wamid.X', 'spoof')]));
        $this->runQueue();
        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM messages'));
    }

    public function testWebhookOnlyWritesIntoTheAccountsTenant(): void
    {
        $a = $this->tenant('Alpha');
        $b = $this->tenant('Bravo');
        $accA = $this->account($a['tenant_id']);
        $this->account($b['tenant_id']);
        $this->post($accA, $this->metaPayload($accA, [$this->textMsg('wamid.T', 'hi')]));
        $this->runQueue();
        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM contacts WHERE tenant_id = ?', [$b['tenant_id']]));
        $this->assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM contacts WHERE tenant_id = ?', [$a['tenant_id']]));
    }

    public function testStatusUpdatesNeverDowngrade(): void
    {
        $t = $this->tenant('Acme');
        $acc = $this->account($t['tenant_id']);
        $this->post($acc, $this->metaPayload($acc, [$this->textMsg('wamid.IN', 'hi')]));
        $this->runQueue();
        $conv = $this->db->first('SELECT * FROM conversations');
        $msgId = $this->db->insert('messages', ['tenant_id' => $t['tenant_id'], 'conversation_id' => $conv['id'], 'whatsapp_account_id' => $acc['id'],
            'direction' => 'out', 'type' => 'text', 'body' => 'reply', 'provider_message_id' => 'wamid.OUT', 'status' => 'submitted',
            'created_at' => now(), 'updated_at' => now()]);
        $st = static fn (string $s) => ['id' => 'wamid.OUT', 'status' => $s, 'timestamp' => (string) time(), 'recipient_id' => '919876543210'];
        $this->post($acc, $this->metaPayload($acc, [], [$st('read')]));
        $this->post($acc, $this->metaPayload($acc, [], [$st('delivered')])); // arrives late
        $this->post($acc, $this->metaPayload($acc, [], [$st('read')]));      // duplicate
        $this->runQueue();
        $this->assertSame('read', $this->db->scalar('SELECT status FROM messages WHERE id = ?', [$msgId]));
        $this->assertSame(2, (int) $this->db->scalar('SELECT COUNT(*) FROM message_status_events WHERE message_id = ?', [$msgId]));
    }

    public function testFailedStatusRecordsProviderError(): void
    {
        $t = $this->tenant('Acme');
        $acc = $this->account($t['tenant_id']);
        $this->post($acc, $this->metaPayload($acc, [$this->textMsg('wamid.IN2', 'hi')]));
        $this->runQueue();
        $conv = $this->db->first('SELECT * FROM conversations');
        $msgId = $this->db->insert('messages', ['tenant_id' => $t['tenant_id'], 'conversation_id' => $conv['id'], 'whatsapp_account_id' => $acc['id'],
            'direction' => 'out', 'type' => 'text', 'body' => 'r', 'provider_message_id' => 'wamid.F', 'status' => 'submitted', 'created_at' => now(), 'updated_at' => now()]);
        $this->post($acc, $this->metaPayload($acc, [], [['id' => 'wamid.F', 'status' => 'failed', 'timestamp' => (string) time(),
            'errors' => [['code' => 131026, 'title' => 'Message undeliverable']]]]));
        $this->runQueue();
        $row = $this->db->first('SELECT * FROM messages WHERE id = ?', [$msgId]);
        $this->assertSame('failed', $row['status']);
        $this->assertSame('131026', $row['error_code']);
    }

    public function testStopKeywordOptsContactOut(): void
    {
        $t = $this->tenant('Acme');
        $acc = $this->account($t['tenant_id']);
        $this->post($acc, $this->metaPayload($acc, [$this->textMsg('wamid.S', 'STOP')]));
        $this->runQueue();
        $c = $this->db->first('SELECT * FROM contacts');
        $this->assertTrue($c['opted_out_at'] !== null);
        $this->assertSame('revoked', $c['consent_status']);
    }

    public function testOpenWaWebhookAndOptionalSignature(): void
    {
        $t = $this->tenant('Acme');
        $acc = $this->account($t['tenant_id'], 'openwa', ['signing_secret' => 'gw-sign']);
        $body = (string) json_encode(['event' => 'onMessage', 'ts' => time(), 'data' => [
            'id' => 'false_447700900123@c.us_3EB0AA', 'from' => '447700900123@c.us', 'body' => 'Hi from WA web', 'type' => 'chat',
            't' => time(), 'fromMe' => false, 'isGroupMsg' => false, 'sender' => ['pushname' => 'Tom'],
        ]]);
        $this->assertStatus(401, $this->raw('POST', '/webhooks/whatsapp/' . $acc['webhook_token'], $body, ['content-type' => 'application/json']));
        $sig = 'sha256=' . hash_hmac('sha256', $body, 'gw-sign');
        $this->assertStatus(200, $this->raw('POST', '/webhooks/whatsapp/' . $acc['webhook_token'], $body, ['content-type' => 'application/json', 'x-signature-256' => $sig]));
        $this->runQueue();
        $this->assertSame('+447700900123', $this->db->scalar('SELECT phone_e164 FROM contacts'));
        $this->assertSame('Hi from WA web', $this->db->scalar('SELECT body FROM messages'));
    }
}
