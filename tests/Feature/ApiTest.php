<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\ApiTokenService;
use App\Core\Env;
use Tests\TestCase;

final class ApiTest extends TestCase
{
    private array $t;
    private string $token;

    public function setUp(): void
    {
        parent::setUp();
        $this->t = $this->tenant('Acme', 'owner@acme.test');
        $this->token = (new ApiTokenService($this->db))->create($this->t['tenant_id'], $this->t['user_id'], 'test')['token'];
    }

    public function testAuthenticationRequired(): void
    {
        $r = $this->api('GET', '/api/v1/contacts', null);
        $this->assertStatus(401, $r);
        $body = json_decode($r->body, true);
        $this->assertSame('unauthenticated', $body['error']['code']);
        $this->assertTrue(isset($r->headers['X-Request-Id']));
        $this->assertStatus(401, $this->api('GET', '/api/v1/contacts', 'wcrm_not-a-real-token'));
    }

    public function testTokensAreHashedAndRevocable(): void
    {
        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM api_tokens WHERE token_hash = ?', [$this->token]));
        $this->assertStatus(200, $this->api('GET', '/api/v1/me', $this->token));
        $id = (int) $this->db->scalar('SELECT id FROM api_tokens');
        (new ApiTokenService($this->db))->revoke($this->t['tenant_id'], $id);
        $this->assertStatus(401, $this->api('GET', '/api/v1/me', $this->token));
    }

    public function testCreateValidateAndListContacts(): void
    {
        $bad = $this->api('POST', '/api/v1/contacts', $this->token, ['phone' => 'not-a-number']);
        $this->assertStatus(422, $bad);
        $this->assertTrue(isset(json_decode($bad->body, true)['error']['details']['phone']));
        $ok = $this->api('POST', '/api/v1/contacts', $this->token, ['phone' => '098765 43210', 'name' => 'Meera', 'tags' => ['vip']]);
        $this->assertStatus(201, $ok);
        $this->assertSame('+919876543210', json_decode($ok->body, true)['data']['phone']);
        $dup = $this->api('POST', '/api/v1/contacts', $this->token, ['phone' => '+91 98765-43210']);
        $this->assertStatus(422, $dup, 'duplicate phone in another format');
        $list = json_decode($this->api('GET', '/api/v1/contacts?q=Meera&per_page=10', $this->token)->body, true);
        $this->assertSame(1, $list['meta']['total']);
        $this->assertSame(10, $list['meta']['per_page']);
    }

    public function testIdempotencyKeyReplaysAndConflicts(): void
    {
        $h = ['idempotency-key' => 'abc-123'];
        $r1 = $this->api('POST', '/api/v1/contacts', $this->token, ['phone' => '+919800000099'], $h);
        $r2 = $this->api('POST', '/api/v1/contacts', $this->token, ['phone' => '+919800000099'], $h);
        $this->assertStatus(201, $r1);
        $this->assertStatus(201, $r2);
        $this->assertSame($r1->body, $r2->body);
        $this->assertSame('true', $r2->headers['Idempotent-Replayed'] ?? null);
        $this->assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM contacts'));
        $this->assertStatus(409, $this->api('POST', '/api/v1/contacts', $this->token, ['phone' => '+919800000098'], $h));
    }

    public function testPermissionsApplyToApiTokens(): void
    {
        $uid = $this->user($this->t['tenant_id'], 'analyst', 'an@acme.test');
        $tok = (new ApiTokenService($this->db))->create($this->t['tenant_id'], $uid, 'ro')['token'];
        $this->assertStatus(200, $this->api('GET', '/api/v1/contacts', $tok));
        $this->assertStatus(403, $this->api('POST', '/api/v1/contacts', $tok, ['phone' => '+919800000097']));
    }

    public function testRateLimit(): void
    {
        Env::set('API_RATE_LIMIT_PER_MINUTE', '3');
        for ($i = 0; $i < 3; $i++) {
            $this->assertStatus(200, $this->api('GET', '/api/v1/me', $this->token));
        }
        $r = $this->api('GET', '/api/v1/me', $this->token);
        $this->assertStatus(429, $r);
        $this->assertTrue(isset($r->headers['Retry-After']));
    }

    public function testSendMessageReturns202AndQueues(): void
    {
        $acc = $this->account($this->t['tenant_id']);
        $c = json_decode($this->api('POST', '/api/v1/contacts', $this->token, ['phone' => '+919811100000'])->body, true)['data'];
        $convId = $this->db->insert('conversations', ['tenant_id' => $this->t['tenant_id'], 'contact_id' => $c['id'], 'whatsapp_account_id' => $acc['id'],
            'status' => 'open', 'last_inbound_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $r = $this->api('POST', "/api/v1/conversations/$convId/messages", $this->token, ['body' => 'Hi from API'], ['idempotency-key' => 'm-1']);
        $this->assertStatus(202, $r);
        $this->assertSame('queued', json_decode($r->body, true)['data']['status']);
        $this->api('POST', "/api/v1/conversations/$convId/messages", $this->token, ['body' => 'Hi from API'], ['idempotency-key' => 'm-1']);
        $this->assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM messages'));
    }

    public function testSuspendedTenantTokenStopsWorking(): void
    {
        $this->db->update('tenants', ['status' => 'suspended'], ['id' => $this->t['tenant_id']]);
        $this->assertStatus(401, $this->api('GET', '/api/v1/me', $this->token));
    }
}
