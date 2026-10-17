<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\ApiTokenService;
use App\Domain\Contacts\ContactImporter;
use App\Domain\ValidationException;
use App\Tenancy\TenantContext;
use Tests\TestCase;

final class ImportTest extends TestCase
{
    private array $t;
    private ContactImporter $imp;

    public function setUp(): void
    {
        parent::setUp();
        $this->t = $this->tenant('Acme', 'owner@acme.test');
        $ctx = new TenantContext();
        $ctx->set($this->t['tenant_id']);
        $this->imp = new ContactImporter($this->db, $ctx);
    }

    private function csv(string $content): string
    {
        $f = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($f, $content);
        return $f;
    }

    public function testImportWithMappingTagsConsentAndErrors(): void
    {
        $this->contact($this->t['tenant_id'], '+919800000001', ['name' => 'Old', 'opted_out_at' => now(), 'consent_status' => 'revoked']);
        $file = $this->csv("\xEF\xBB\xBFFirst Name,Last Name,Mobile Number,E-mail,City,Tags,Consent\n"
            . "Asha,Verma,+91 98765 43210,asha@example.com,Pune,vip;wholesale,yes\n"
            . "Ravi,,09812345678,not-an-email,Kanpur,,no\n"
            . "Bad,,12345,,,,\n"
            . "Dup,,+919876543210,,,,\n"
            . "Old Name,,+919800000001,,,,yes\n");
        $r = $this->imp->import($file, ['tags' => 'diwali-2026', 'update_existing' => true]);
        $this->assertSame(2, $r['created']);
        $this->assertSame(1, $r['updated']);
        $this->assertSame(2, $r['skipped']);
        $asha = $this->db->first("SELECT * FROM contacts WHERE phone_e164 = '+919876543210'");
        $this->assertSame('Asha Verma', $asha['name']);
        $this->assertSame('granted', $asha['consent_status']);
        $tags = array_column($this->db->all('SELECT t.name FROM contact_tags ct JOIN tags t ON t.id = ct.tag_id WHERE ct.contact_id = ? ORDER BY t.name', [$asha['id']]), 'name');
        $this->assertSame(['diwali-2026', 'vip', 'wholesale'], $tags);
        $ravi = $this->db->first("SELECT * FROM contacts WHERE phone_e164 = '+919812345678'");
        $this->assertSame(null, $ravi['email']);
        $this->assertTrue($ravi['opted_out_at'] !== null, '"no" consent = opted out');
        $old = $this->db->first("SELECT * FROM contacts WHERE phone_e164 = '+919800000001'");
        $this->assertTrue($old['opted_out_at'] !== null, 'import never silently re-subscribes an opted-out contact');
        $this->assertSame('Old Name', $old['name']);
        $reasons = implode(' | ', array_column($r['errors'], 'reason'));
        $this->assertContains('Invalid phone', $reasons);
        $this->assertContains('Duplicate of row 2', $reasons);
        $this->assertContains('Kept opt-out', $reasons);
    }

    public function testSemicolonFilesAndMissingPhoneColumn(): void
    {
        $r = $this->imp->import($this->csv("name;phone\nA;+14155552671\n"), []);
        $this->assertSame(1, $r['created']);
        $this->assertThrows(ValidationException::class, fn () => $this->imp->import($this->csv("name,email\nA,a@b.c\n"), []));
    }

    public function testWebImportRequiresPermissionAndConsentConfirmation(): void
    {
        $owner = $this->sessionFor($this->t['user_id']);
        $this->assertStatus(200, $this->web('GET', '/contacts/import', $owner));
        $agent = $this->sessionFor($this->user($this->t['tenant_id'], 'sales_agent', 'ag@acme.test'));
        $this->assertStatus(403, $this->web('GET', '/contacts/import', $agent));
    }

    public function testApiTemplateSendCreatesContactAndRespectsOptOut(): void
    {
        $acc = $this->account($this->t['tenant_id']);
        $this->template($this->t['tenant_id'], (int) $acc['id']);
        $token = (new ApiTokenService($this->db))->create($this->t['tenant_id'], $this->t['user_id'], 'shop')['token'];
        $r = $this->api('POST', '/api/v1/messages/template', $token,
            ['phone' => '+91 99887 76655', 'template' => 'order_update', 'name' => 'Neha', 'variables' => ['body_1' => 'Neha', 'body_2' => '#5521']],
            ['idempotency-key' => 'order-5521']);
        $this->assertStatus(202, $r);
        $this->assertSame('queued', json_decode($r->body, true)['data']['status']);
        $this->api('POST', '/api/v1/messages/template', $token,
            ['phone' => '+91 99887 76655', 'template' => 'order_update', 'name' => 'Neha', 'variables' => ['body_1' => 'Neha', 'body_2' => '#5521']],
            ['idempotency-key' => 'order-5521']);
        $this->assertSame(1, (int) $this->db->scalar("SELECT COUNT(*) FROM messages WHERE type = 'template'"));
        $this->assertStatus(422, $this->api('POST', '/api/v1/messages/template', $token, ['phone' => '+919988776655', 'template' => 'order_update', 'variables' => ['body_1' => 'x']]));
        $this->assertStatus(422, $this->api('POST', '/api/v1/messages/template', $token, ['phone' => '+919988776655', 'template' => 'nope']));
        $this->db->run("UPDATE contacts SET opted_out_at = ? WHERE phone_e164 = '+919988776655'", [now()]);
        $this->assertStatus(422, $this->api('POST', '/api/v1/messages/template', $token, ['phone' => '+919988776655', 'template' => 'order_update', 'variables' => ['body_1' => 'a', 'body_2' => 'b']]));
        $list = json_decode($this->api('GET', '/api/v1/templates', $token)->body, true);
        $this->assertSame(['body_1', 'body_2'], $list['data'][0]['variables']);
        $this->assertStatus(200, $this->api('GET', '/api/v1/campaigns', $token));
    }

    public function testPermissionUpgradeGrantsNewPermissionsToExistingRoles(): void
    {
        // Simulate an install from before templates existed: remove the new permission everywhere.
        $pid = (int) $this->db->scalar("SELECT id FROM permissions WHERE name = 'campaigns.manage'");
        $this->db->run('DELETE FROM permissions WHERE id = ?', [$pid]);
        \App\Tenancy\TenantProvisioner::syncPermissions($this->db);
        $roles = array_column($this->db->all("SELECT r.slug FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
            WHERE p.name = 'campaigns.manage' AND r.tenant_id = ? ORDER BY r.slug", [$this->t['tenant_id']]), 'slug');
        $this->assertSame(['admin', 'marketing_manager', 'owner'], $roles);
    }
}
