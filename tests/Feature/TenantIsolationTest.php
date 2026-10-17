<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\ApiTokenService;
use App\Domain\Contacts\ContactService;
use App\Domain\Conversations\InboxService;
use App\Tenancy\TenantContext;
use Tests\TestCase;

/** Every cross-tenant access path must behave as if the record does not exist. */
final class TenantIsolationTest extends TestCase
{
    private array $a;
    private array $b;
    private int $contactA;
    private int $convA;

    public function setUp(): void
    {
        parent::setUp();
        $this->a = $this->tenant('Alpha', 'owner@alpha.test');
        $this->b = $this->tenant('Bravo', 'owner@bravo.test');
        $ctx = new TenantContext();
        $ctx->set($this->a['tenant_id']);
        $this->contactA = (new ContactService($this->db, $ctx))->create(['name' => 'Alpha Secret Customer', 'phone' => '+919811111111']);
        $acc = $this->account($this->a['tenant_id']);
        $this->convA = (int) (new InboxService($this->db, $ctx))->findOrCreateConversation($this->contactA, (int) $acc['id'])['id'];
    }

    public function testWebContactPagesReturn404AcrossTenants(): void
    {
        $sb = $this->sessionFor($this->b['user_id']);
        $this->assertStatus(404, $this->web('GET', '/contacts/' . $this->contactA, $sb));
        $this->assertStatus(404, $this->web('POST', '/contacts/' . $this->contactA, $sb, ['name' => 'hijacked']));
        $this->assertStatus(404, $this->web('POST', '/contacts/' . $this->contactA . '/archive', $sb));
        $this->assertStatus(404, $this->web('POST', '/contacts/' . $this->contactA . '/notes', $sb, ['body' => 'x']));
        $this->assertSame('Alpha Secret Customer', $this->db->scalar('SELECT name FROM contacts WHERE id = ?', [$this->contactA]));
        $this->assertSame(null, $this->db->scalar('SELECT archived_at FROM contacts WHERE id = ?', [$this->contactA]));
        $this->assertNotContains('Alpha Secret Customer', $this->web('GET', '/contacts', $sb)->body);
        $this->assertNotContains('Alpha Secret Customer', $this->web('GET', '/contacts?q=Alpha', $sb)->body);
        $this->assertNotContains('Alpha Secret Customer', $this->web('GET', '/contacts/export', $sb)->body);
    }

    public function testInboxReturns404AcrossTenants(): void
    {
        $sb = $this->sessionFor($this->b['user_id']);
        $this->assertStatus(404, $this->web('GET', '/inbox/' . $this->convA, $sb));
        $this->assertStatus(404, $this->web('GET', '/inbox/' . $this->convA . '/poll', $sb));
        $this->assertStatus(404, $this->web('POST', '/inbox/' . $this->convA . '/messages', $sb, ['body' => 'hello']));
        $this->assertStatus(404, $this->web('POST', '/inbox/' . $this->convA . '/assign', $sb, ['assigned_user_id' => $this->b['user_id']]));
        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM messages'));
        $this->assertSame(null, $this->db->scalar('SELECT assigned_user_id FROM conversations WHERE id = ?', [$this->convA]));
    }

    public function testCannotAssignConversationToUserOfAnotherTenant(): void
    {
        $sa = $this->sessionFor($this->a['user_id']);
        $this->web('POST', '/inbox/' . $this->convA . '/assign', $sa, ['assigned_user_id' => $this->b['user_id']]);
        $this->assertSame(null, $this->db->scalar('SELECT assigned_user_id FROM conversations WHERE id = ?', [$this->convA]));
    }

    public function testApiReturns404AcrossTenants(): void
    {
        $tokenB = (new ApiTokenService($this->db))->create($this->b['tenant_id'], $this->b['user_id'], 'b')['token'];
        $this->assertStatus(404, $this->api('GET', '/api/v1/contacts/' . $this->contactA, $tokenB));
        $this->assertStatus(404, $this->api('PATCH', '/api/v1/contacts/' . $this->contactA, $tokenB, ['name' => 'x']));
        $this->assertStatus(404, $this->api('DELETE', '/api/v1/contacts/' . $this->contactA, $tokenB));
        $this->assertStatus(404, $this->api('GET', '/api/v1/conversations/' . $this->convA . '/messages', $tokenB));
        $this->assertStatus(404, $this->api('POST', '/api/v1/conversations/' . $this->convA . '/messages', $tokenB, ['body' => 'x']));
        $list = json_decode($this->api('GET', '/api/v1/contacts', $tokenB)->body, true);
        $this->assertSame(0, $list['meta']['total']);
    }

    public function testSamePhoneMayExistInBothTenants(): void
    {
        $ctx = new TenantContext();
        $ctx->set($this->b['tenant_id']);
        $id = (new ContactService($this->db, $ctx))->create(['phone' => '+91 98111 11111']);
        $this->assertTrue($id > 0 && $id !== $this->contactA);
    }

    public function testTenantContextCannotBeSwitchedOrSkipped(): void
    {
        $ctx = new TenantContext();
        $this->assertThrows(\LogicException::class, fn () => (new ContactService($this->db, $ctx))->repo->find($this->contactA));
        $ctx->set($this->b['tenant_id']);
        $this->assertThrows(\LogicException::class, fn () => $ctx->set($this->a['tenant_id']));
        $this->assertSame(null, (new ContactService($this->db, $ctx))->repo->find($this->contactA));
    }

    public function testRolesAndTeamsOfOtherTenantAreUnreachable(): void
    {
        $roleA = (int) $this->db->scalar("SELECT id FROM roles WHERE tenant_id = ? AND slug = 'analyst'", [$this->a['tenant_id']]);
        $sb = $this->sessionFor($this->b['user_id']);
        $this->assertStatus(404, $this->web('POST', '/roles/' . $roleA, $sb, ['permissions' => ['contacts.delete' => '1']]));
        // Inviting a user into tenant B with a role from tenant A is refused.
        $this->assertStatus(404, $this->web('POST', '/users/invite', $sb, ['name' => 'X', 'email' => 'x@bravo.test', 'role_id' => (string) $roleA]));
        $this->assertSame(0, (int) $this->db->scalar("SELECT COUNT(*) FROM users WHERE email = 'x@bravo.test'"));
    }
}
