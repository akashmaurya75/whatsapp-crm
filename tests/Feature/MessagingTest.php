<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Contacts\ContactService;
use App\Domain\Conversations\InboxService;
use App\Domain\ValidationException;
use App\Tenancy\TenantContext;
use Tests\TestCase;

final class MessagingTest extends TestCase
{
    private array $t;
    private array $acc;
    private array $conv;
    private InboxService $inbox;

    public function setUp(): void
    {
        parent::setUp();
        $this->t = $this->tenant('Acme', 'owner@acme.test');
        $this->acc = $this->account($this->t['tenant_id']);
        $ctx = new TenantContext();
        $ctx->set($this->t['tenant_id']);
        $this->inbox = new InboxService($this->db, $ctx, $this->app()->queue);
        $cid = (new ContactService($this->db, $ctx))->create(['phone' => '+919876500000', 'name' => 'Priya']);
        $this->conv = $this->inbox->findOrCreateConversation($cid, (int) $this->acc['id']);
        // Customer wrote 1 hour ago → 24 h service window is open.
        $this->db->update('conversations', ['last_inbound_at' => gmdate('Y-m-d H:i:s', time() - 3600)], ['id' => $this->conv['id']]);
        $this->conv = (array) $this->db->first('SELECT * FROM conversations WHERE id = ?', [$this->conv['id']]);
    }

    public function testMessageIsQueuedThenSubmittedOnlyAfterProviderAccepts(): void
    {
        $msg = $this->inbox->queueText($this->conv, $this->t['user_id'], 'Your order has shipped');
        $this->assertSame('queued', $msg['status']);
        $this->http->queue(200, ['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.SENT1']]]);
        $this->runQueue();
        $row = $this->db->first('SELECT * FROM messages WHERE id = ?', [$msg['id']]);
        $this->assertSame('submitted', $row['status']);
        $this->assertSame('wamid.SENT1', $row['provider_message_id']);
        $req = $this->http->requests[0];
        $this->assertContains('/v25.0/1000' . $this->t['tenant_id'] . '/messages', $req['url']);
        $this->assertSame('Bearer EAAtest', $req['headers']['Authorization']);
        $payload = json_decode((string) $req['body'], true);
        $this->assertSame('919876500000', $payload['to']);
        $this->assertSame('Your order has shipped', $payload['text']['body']);
    }

    public function testTransientErrorsRetryThenSucceed(): void
    {
        $msg = $this->inbox->queueText($this->conv, $this->t['user_id'], 'retry me');
        $this->http->queue(503, ['error' => ['code' => 2, 'message' => 'Service temporarily unavailable']]);
        $this->http->queue(429, ['error' => ['code' => 130429, 'message' => 'Rate limit hit']]);
        $this->http->queue(200, ['messages' => [['id' => 'wamid.OK']]]);
        $this->runQueue();
        $this->assertSame('submitted', $this->db->scalar('SELECT status FROM messages WHERE id = ?', [$msg['id']]));
        $this->assertSame(3, count($this->http->requests));
    }

    public function testPermanentErrorFailsWithoutRetry(): void
    {
        $msg = $this->inbox->queueText($this->conv, $this->t['user_id'], 'bad');
        $this->http->queue(400, ['error' => ['code' => 131026, 'message' => 'Message undeliverable', 'error_data' => ['details' => 'Recipient not on WhatsApp']]]);
        $this->runQueue();
        $row = $this->db->first('SELECT * FROM messages WHERE id = ?', [$msg['id']]);
        $this->assertSame('failed', $row['status']);
        $this->assertSame('131026', $row['error_code']);
        $this->assertSame('Recipient not on WhatsApp', $row['error_message']);
        $this->assertSame(1, count($this->http->requests));
    }

    public function testRetriesAreBoundedAndEndInFailed(): void
    {
        $msg = $this->inbox->queueText($this->conv, $this->t['user_id'], 'down');
        for ($i = 0; $i < 5; $i++) {
            $this->http->queue(500, 'oops');
        }
        $this->runQueue();
        $this->assertSame('failed', $this->db->scalar('SELECT status FROM messages WHERE id = ?', [$msg['id']]));
        $this->assertSame(5, count($this->http->requests));
        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM jobs'));
    }

    public function testOptedOutContactCannotBeMessaged(): void
    {
        $this->db->update('contacts', ['opted_out_at' => now()], ['id' => $this->conv['contact_id']]);
        $this->assertThrows(ValidationException::class, fn () => $this->inbox->queueText($this->conv, null, 'promo'));
        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM messages'));
    }

    public function testOptOutAfterQueueingStopsDispatch(): void
    {
        $msg = $this->inbox->queueText($this->conv, null, 'hello');
        $this->db->update('contacts', ['opted_out_at' => now()], ['id' => $this->conv['contact_id']]);
        $this->runQueue();
        $this->assertSame('failed', $this->db->scalar('SELECT status FROM messages WHERE id = ?', [$msg['id']]));
        $this->assertSame(0, count($this->http->requests));
    }

    public function testMetaServiceWindowIsEnforced(): void
    {
        $this->db->update('conversations', ['last_inbound_at' => gmdate('Y-m-d H:i:s', time() - 25 * 3600)], ['id' => $this->conv['id']]);
        $conv = (array) $this->db->first('SELECT * FROM conversations WHERE id = ?', [$this->conv['id']]);
        $this->assertThrows(ValidationException::class, fn () => $this->inbox->queueText($conv, null, 'late reply'));
    }

    public function testIdempotencyKeyPreventsDuplicateSends(): void
    {
        $a = $this->inbox->queueText($this->conv, null, 'once', 'key-1');
        $b = $this->inbox->queueText($this->conv, null, 'once', 'key-1');
        $this->assertSame($a['id'], $b['id']);
        $this->assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM jobs'));
    }

    public function testSendJobIsIdempotentWhenReplayed(): void
    {
        $msg = $this->inbox->queueText($this->conv, null, 'single');
        $this->http->queue(200, ['messages' => [['id' => 'wamid.ONE']]]);
        $this->runQueue();
        // Re-push the same job (e.g. after a worker crash) → no second provider call.
        $this->app()->queue->push(\App\WhatsApp\Jobs\SendMessageJob::class, ['tenant_id' => $this->t['tenant_id'], 'message_id' => (int) $msg['id']], 'messages');
        $this->runQueue();
        $this->assertSame(1, count($this->http->requests));
    }

    public function testInboxSendEndpointAndNotesStayInternal(): void
    {
        $s = $this->sessionFor($this->t['user_id']);
        $r = $this->web('POST', '/inbox/' . $this->conv['id'] . '/messages', $s, ['body' => 'From the UI', 'idempotency_key' => 'ui-1']);
        $this->assertStatus(302, $r);
        $this->web('POST', '/inbox/' . $this->conv['id'] . '/notes', $s, ['body' => 'Internal: VIP customer']);
        $this->assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM messages'), 'notes are never messages');
        $this->assertSame('queued', $this->db->scalar('SELECT status FROM messages'));
        $html = $this->web('GET', '/inbox/' . $this->conv['id'], $s)->body;
        $this->assertContains('From the UI', $html);
        $this->assertContains('Internal: VIP customer', $html);
    }

    public function testOpenWaSendUsesGatewayContract(): void
    {
        $gw = $this->account($this->t['tenant_id'], 'openwa');
        $conv = $this->inbox->findOrCreateConversation((int) $this->conv['contact_id'], (int) $gw['id']);
        $this->inbox->queueText($conv, null, 'via gateway'); // no 24h rule for this provider
        $this->http->queue(200, ['success' => true, 'response' => 'true_919876500000@c.us_3EB0XYZ']);
        $this->runQueue();
        $req = $this->http->requests[0];
        $this->assertSame('https://gw.test/sendText', $req['url']);
        $this->assertSame('gw-key', $req['headers']['api_key']);
        $this->assertSame('919876500000@c.us', json_decode((string) $req['body'], true)['args']['to']);
        $this->assertSame('submitted', $this->db->scalar("SELECT status FROM messages WHERE whatsapp_account_id = ?", [$gw['id']]));
    }
}
