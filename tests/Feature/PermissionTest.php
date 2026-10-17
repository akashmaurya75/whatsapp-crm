<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Contacts\ContactService;
use App\Domain\Conversations\InboxService;
use App\Tenancy\TenantContext;
use Tests\TestCase;

final class PermissionTest extends TestCase
{
    public function testAnalystIsReadOnly(): void
    {
        $t = $this->tenant('Acme', 'owner@acme.test');
        $analyst = $this->sessionFor($this->user($t['tenant_id'], 'analyst', 'analyst@acme.test'));
        $this->assertStatus(200, $this->web('GET', '/contacts', $analyst));
        $this->assertStatus(403, $this->web('POST', '/contacts', $analyst, ['phone' => '+919800000002']));
        $this->assertStatus(403, $this->web('GET', '/contacts/export', $analyst));
        $this->assertStatus(403, $this->web('GET', '/users', $analyst));
        $this->assertStatus(403, $this->web('GET', '/settings/api-tokens', $analyst));
        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM contacts'));
    }

    public function testAgentSeesOnlyOwnTeamOrUnassignedConversations(): void
    {
        $t = $this->tenant('Acme', 'owner@acme.test');
        $agent1 = $this->user($t['tenant_id'], 'support_agent', 'a1@acme.test');
        $agent2 = $this->user($t['tenant_id'], 'support_agent', 'a2@acme.test');
        $ctx = new TenantContext();
        $ctx->set($t['tenant_id']);
        $acc = $this->account($t['tenant_id']);
        $inbox = new InboxService($this->db, $ctx);
        $c1 = (new ContactService($this->db, $ctx))->create(['phone' => '+919800000010', 'name' => 'Mine']);
        $c2 = (new ContactService($this->db, $ctx))->create(['phone' => '+919800000011', 'name' => 'Theirs']);
        $conv1 = $inbox->findOrCreateConversation($c1, (int) $acc['id']);
        $conv2 = $inbox->findOrCreateConversation($c2, (int) $acc['id']);
        $inbox->assign($conv2, $agent2, null, $t['user_id']);

        $s1 = $this->sessionFor($agent1);
        $this->assertStatus(200, $this->web('GET', '/inbox/' . $conv1['id'], $s1), 'unassigned is visible');
        $this->assertStatus(404, $this->web('GET', '/inbox/' . $conv2['id'], $s1), "another agent's conversation is hidden");
        // Agents may claim an unassigned conversation, but not assign it to someone else.
        $this->web('POST', '/inbox/' . $conv1['id'] . '/assign', $s1, ['assigned_user_id' => (string) $agent1]);
        $this->assertSame($agent1, (int) $this->db->scalar('SELECT assigned_user_id FROM conversations WHERE id = ?', [$conv1['id']]));
        $this->assertStatus(403, $this->web('POST', '/inbox/' . $conv1['id'] . '/assign', $s1, ['assigned_user_id' => (string) $agent2]));

        // Team membership grants visibility.
        $team = $this->db->insert('teams', ['tenant_id' => $t['tenant_id'], 'name' => 'Support', 'created_at' => now(), 'updated_at' => now()]);
        $this->db->insert('team_members', ['team_id' => $team, 'user_id' => $agent1, 'tenant_id' => $t['tenant_id']]);
        $inbox->assign($conv2, null, $team, $t['user_id']);
        $this->assertStatus(200, $this->web('GET', '/inbox/' . $conv2['id'], $s1));
    }

    public function testAdminCannotGrantOwnerRoleOrEditOwner(): void
    {
        $t = $this->tenant('Acme', 'owner@acme.test');
        $admin = $this->user($t['tenant_id'], 'admin', 'admin@acme.test');
        $agent = $this->user($t['tenant_id'], 'sales_agent', 'agent@acme.test');
        $ownerRole = (int) $this->db->scalar("SELECT id FROM roles WHERE tenant_id = ? AND slug = 'owner'", [$t['tenant_id']]);
        $s = $this->sessionFor($admin);
        $this->assertStatus(403, $this->web('POST', '/users/' . $agent, $s, ['role_id' => (string) $ownerRole]));
        $this->assertStatus(403, $this->web('POST', '/users/' . $t['user_id'], $s, ['status' => 'suspended']));
        $this->assertSame('active', $this->db->scalar('SELECT status FROM users WHERE id = ?', [$t['user_id']]));
    }

    public function testRoleChangeForcesReauthentication(): void
    {
        $t = $this->tenant('Acme', 'owner@acme.test');
        $agent = $this->user($t['tenant_id'], 'sales_agent', 'agent@acme.test');
        $agentSession = $this->sessionFor($agent);
        $this->assertStatus(200, $this->web('GET', '/contacts', $agentSession));
        $analystRole = (int) $this->db->scalar("SELECT id FROM roles WHERE tenant_id = ? AND slug = 'analyst'", [$t['tenant_id']]);
        $this->web('POST', '/users/' . $agent, $this->sessionFor($t['user_id']), ['role_id' => (string) $analystRole]);
        $this->assertSame('/login', $this->web('GET', '/contacts', $agentSession)->headers['Location'] ?? null);
    }

    public function testCustomRolePermissionsAreEnforced(): void
    {
        $t = $this->tenant('Acme', 'owner@acme.test');
        $owner = $this->sessionFor($t['user_id']);
        $this->web('POST', '/roles', $owner, ['name' => 'Exporter']);
        $roleId = (int) $this->db->scalar("SELECT id FROM roles WHERE tenant_id = ? AND name = 'Exporter'", [$t['tenant_id']]);
        $this->web('POST', '/roles/' . $roleId, $owner, ['permissions' => ['contacts.view' => '1', 'contacts.export' => '1', 'not.a.permission' => '1']]);
        $this->assertSame(2, (int) $this->db->scalar('SELECT COUNT(*) FROM role_permissions WHERE role_id = ?', [$roleId]));
        $uid = $this->db->insert('users', ['tenant_id' => $t['tenant_id'], 'role_id' => $roleId, 'name' => 'E', 'email' => 'e@acme.test',
            'password_hash' => 'x', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $s = $this->sessionFor($uid);
        $this->assertStatus(200, $this->web('GET', '/contacts/export', $s));
        $this->assertStatus(403, $this->web('GET', '/inbox', $s));
    }
}
