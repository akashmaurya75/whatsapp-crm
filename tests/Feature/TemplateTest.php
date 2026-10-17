<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Conversations\InboxService;
use App\Domain\Templates\TemplateService;
use App\Domain\ValidationException;
use App\Tenancy\TenantContext;
use App\WhatsApp\ProviderFactory;
use Tests\TestCase;

final class TemplateTest extends TestCase
{
    private array $t;
    private array $acc;
    private TemplateService $svc;

    public function setUp(): void
    {
        parent::setUp();
        $this->t = $this->tenant('Acme', 'owner@acme.test');
        $this->acc = $this->account($this->t['tenant_id']);
        $this->db->update('whatsapp_accounts', ['business_account_id' => '5550001'], ['id' => $this->acc['id']]);
        $this->acc['business_account_id'] = '5550001';
        $ctx = new TenantContext();
        $ctx->set($this->t['tenant_id']);
        $this->svc = new TemplateService($this->db, $ctx, new ProviderFactory($this->http));
    }

    private function input(array $over = []): array
    {
        return $over + [
            'whatsapp_account_id' => (string) $this->acc['id'], 'name' => 'diwali_offer', 'language' => 'en', 'category' => 'marketing',
            'header_type' => 'text', 'header_text' => 'Hello {{1}}', 'header_sample' => 'Asha',
            'body' => "Hi {{1}}, enjoy *20% off* on all orders this Diwali with code {{2}}. Shop now!",
            'body_samples' => [1 => 'Asha', 2 => 'DIWALI20'], 'footer' => 'Reply STOP to unsubscribe',
            'buttons' => [
                ['type' => 'url', 'text' => 'Shop now', 'value' => 'https://shop.example.com/offer/{{1}}'],
                ['type' => 'quick_reply', 'text' => 'Not interested', 'value' => ''],
            ],
        ];
    }

    public function testValidationEnforcesMetaRules(): void
    {
        $pairs = [
            [['name' => 'Bad Name!'], 'name'],
            [['body' => '{{1}} is your code', 'body_samples' => [1 => 'x']], 'body'],
            [['body' => 'Hello {{1}} and here is {{3}} for you', 'body_samples' => [1 => 'a', 3 => 'b']], 'body'],
            [['body_samples' => [1 => 'Asha', 2 => '']], 'body_samples'],
            [['header_text' => 'Hi {{1}} {{2}}'], 'header_text'],
            [['buttons' => [['type' => 'url', 'text' => 'Go', 'value' => 'http://insecure.test']]], 'buttons'],
            [['buttons' => [['type' => 'url', 'text' => 'Go', 'value' => 'https://x.test/{{1}}/end']]], 'buttons'],
            [['buttons' => [['type' => 'phone', 'text' => 'Call', 'value' => 'abc']]], 'buttons'],
            [['footer' => 'Footer {{1}}'], 'footer'],
            [['category' => 'authentication'], 'category'],
        ];
        foreach ($pairs as [$over, $field]) {
            try {
                $this->svc->validate($over + $this->input());
                $this->assertTrue(false, "expected error on $field for " . json_encode($over));
            } catch (ValidationException $e) {
                $this->assertTrue(isset($e->errors[$field]), "expected error on $field, got " . json_encode($e->errors));
            }
        }
        $ok = $this->svc->validate($this->input());
        $this->assertSame('diwali_offer', $ok['name']);
    }

    public function testMetaPayloadAndSubmission(): void
    {
        $id = $this->svc->saveDraft($this->input(), $this->t['user_id']);
        $payload = TemplateService::metaPayload((array) $this->svc->repo->find($id));
        $this->assertSame('MARKETING', $payload['category']);
        $types = array_column($payload['components'], 'type');
        $this->assertSame(['HEADER', 'BODY', 'FOOTER', 'BUTTONS'], $types);
        $this->assertSame([['Asha', 'DIWALI20']], $payload['components'][1]['example']['body_text']);
        $this->assertSame(['Asha'], $payload['components'][0]['example']['header_text']);
        // Quick replies are grouped before call-to-action buttons.
        $this->assertSame('QUICK_REPLY', $payload['components'][3]['buttons'][0]['type']);
        $this->assertSame('https://shop.example.com/offer/{{1}}', $payload['components'][3]['buttons'][1]['url']);

        $this->http->queue(200, ['id' => '99887766', 'status' => 'PENDING', 'category' => 'MARKETING']);
        $t = $this->svc->submit($id);
        $this->assertSame('pending', $t['status']);
        $this->assertSame('99887766', $t['provider_template_id']);
        $req = $this->http->requests[0];
        $this->assertContains('/5550001/message_templates', $req['url']);
        $this->assertSame('diwali_offer', json_decode((string) $req['body'], true)['name']);
        $this->assertThrows(ValidationException::class, fn () => $this->svc->submit($id));
    }

    public function testMetaRejectionAtSubmitIsShown(): void
    {
        $id = $this->svc->saveDraft($this->input(), null);
        $this->http->queue(400, ['error' => ['message' => 'Invalid parameter', 'error_user_title' => 'Template name already exists', 'error_user_msg' => 'Use another name.']]);
        try {
            $this->svc->submit($id);
            $this->assertTrue(false, 'should fail');
        } catch (ValidationException $e) {
            $this->assertContains('Template name already exists', $e->getMessage());
        }
        $this->assertSame('draft', $this->svc->repo->find($id)['status']);
    }

    public function testStatusWebhookApprovesAndRejects(): void
    {
        $id = $this->svc->saveDraft($this->input(), null);
        $this->http->queue(200, ['id' => '4242', 'status' => 'PENDING']);
        $this->svc->submit($id);
        $payload = static fn (string $event, string $reason = 'NONE') => (string) json_encode(['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => '5550001', 'time' => time() + random_int(1, 999), 'changes' => [['field' => 'message_template_status_update', 'value' => [
                'event' => $event, 'message_template_id' => 4242, 'message_template_name' => 'diwali_offer', 'message_template_language' => 'en', 'reason' => $reason,
            ]]],
        ]]]);
        $post = fn (string $body) => $this->raw('POST', '/webhooks/whatsapp/' . $this->acc['webhook_token'], $body,
            ['content-type' => 'application/json', 'x-hub-signature-256' => 'sha256=' . hash_hmac('sha256', $body, 'app-secret-123')]);
        $this->assertStatus(200, $post($payload('APPROVED')));
        $this->runQueue();
        $this->assertSame('approved', $this->svc->repo->find($id)['status']);
        $post($payload('REJECTED', 'INCORRECT_CATEGORY'));
        $this->runQueue();
        $row = $this->svc->repo->find($id);
        $this->assertSame('rejected', $row['status']);
        $this->assertSame('INCORRECT_CATEGORY', $row['rejected_reason']);
        // Event for another WABA is ignored.
        $other = str_replace('"5550001"', '"999"', $payload('APPROVED'));
        $post($other);
        $this->runQueue();
        $this->assertSame('rejected', $this->svc->repo->find($id)['status']);
    }

    public function testSyncImportsUpdatesAndMarksDeleted(): void
    {
        $this->template($this->t['tenant_id'], (int) $this->acc['id'], ['name' => 'old_one', 'provider_template_id' => '1']);
        $this->http->queue(200, ['data' => [
            ['id' => '2', 'name' => 'payment_due', 'language' => 'en_US', 'status' => 'APPROVED', 'category' => 'UTILITY', 'quality_score' => ['score' => 'GREEN'],
                'components' => [
                    ['type' => 'HEADER', 'format' => 'IMAGE', 'example' => ['header_handle' => ['h']]],
                    ['type' => 'BODY', 'text' => 'Dear {{1}}, payment of {{2}} is due on {{3}}.', 'example' => ['body_text' => [['Asha', '₹500', '5 Nov']]]],
                    ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => 'Pay', 'url' => 'https://pay.example.com/{{1}}'], ['type' => 'COPY_CODE', 'text' => 'Copy']]],
                ]],
            ['id' => '3', 'name' => 'welcome', 'language' => 'hi', 'status' => 'REJECTED', 'category' => 'MARKETING', 'rejected_reason' => 'TAG_CONTENT_MISMATCH',
                'components' => [['type' => 'BODY', 'text' => 'Namaste!']]],
        ]]);
        [$c, $u, $r] = $this->svc->sync((int) $this->acc['id']);
        $this->assertSame([2, 0, 1], [$c, $u, $r]);
        $pay = $this->db->first("SELECT * FROM message_templates WHERE name = 'payment_due'");
        $this->assertSame('image', $pay['header_type']);
        $this->assertSame('green', $pay['quality_score']);
        $this->assertSame(['body_1', 'body_2', 'body_3', 'button_0'], array_keys(TemplateService::slots($pay)));
        $this->assertTrue(TemplateService::unsendableReason($pay) !== null, 'COPY_CODE button is not supported for sending');
        $this->assertSame('deleted', $this->db->scalar("SELECT status FROM message_templates WHERE name = 'old_one'"));
        $this->assertSame('TAG_CONTENT_MISMATCH', $this->db->scalar("SELECT rejected_reason FROM message_templates WHERE name = 'welcome'"));
    }

    public function testSendComponentsAndProviderPayload(): void
    {
        $tpl = $this->template($this->t['tenant_id'], (int) $this->acc['id'], [
            'header_type' => 'document', 'buttons' => '[{"type":"url","text":"Track","value":"https://t.example.com/{{1}}"}]',
        ]);
        $this->assertThrows(ValidationException::class, fn () => TemplateService::sendComponents($tpl, ['body_1' => 'A', 'body_2' => 'B', 'button_0' => 'X']));
        $this->assertThrows(ValidationException::class, fn () => TemplateService::sendComponents($tpl, ['body_1' => 'A', 'body_2' => ''], ['link' => 'https://x/y.pdf']));
        $comp = TemplateService::sendComponents($tpl, ['body_1' => "Asha\nVerma", 'body_2' => '#1042', 'button_0' => 'AB12'], ['link' => 'https://cdn.example.com/inv.pdf', 'filename' => 'inv.pdf']);
        $this->assertSame('document', $comp[0]['parameters'][0]['type']);
        $this->assertSame('inv.pdf', $comp[0]['parameters'][0]['document']['filename']);
        $this->assertSame('Asha Verma', $comp[1]['parameters'][0]['text'], 'newlines are not allowed in params');
        $this->assertSame(['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => 'AB12']]], $comp[2]);

        // End-to-end: queued template message → Meta "template" payload.
        $ctx = new TenantContext();
        $ctx->set($this->t['tenant_id']);
        $inbox = new InboxService($this->db, $ctx, $this->app()->queue);
        $cid = $this->contact($this->t['tenant_id'], '+919800011111');
        $conv = $inbox->findOrCreateConversation($cid, (int) $this->acc['id']); // window closed: templates still allowed
        $plain = $this->template($this->t['tenant_id'], (int) $this->acc['id'], ['name' => 'plain']);
        $msg = $inbox->queueTemplate($conv, null, $plain, ['body_1' => 'Asha', 'body_2' => '#1042']);
        $this->assertSame('template', $msg['type']);
        $this->assertContains('Hi Asha, your order #1042 has shipped.', (string) $msg['body']);
        $this->http->queue(200, ['messages' => [['id' => 'wamid.T1']]]);
        $this->runQueue();
        $sent = json_decode((string) $this->http->requests[0]['body'], true);
        $this->assertSame('template', $sent['type']);
        $this->assertSame('plain', $sent['template']['name']);
        $this->assertSame('en', $sent['template']['language']['code']);
        $this->assertSame('#1042', $sent['template']['components'][0]['parameters'][1]['text']);
        $this->assertSame('submitted', $this->db->scalar('SELECT status FROM messages WHERE id = ?', [$msg['id']]));
    }

    public function testUnapprovedOrForeignTemplatesCannotBeSent(): void
    {
        $ctx = new TenantContext();
        $ctx->set($this->t['tenant_id']);
        $inbox = new InboxService($this->db, $ctx);
        $conv = $inbox->findOrCreateConversation($this->contact($this->t['tenant_id'], '+919800022222'), (int) $this->acc['id']);
        $pending = $this->template($this->t['tenant_id'], (int) $this->acc['id'], ['name' => 'p', 'status' => 'pending']);
        $this->assertThrows(ValidationException::class, fn () => $inbox->queueTemplate($conv, null, $pending, ['body_1' => 'a', 'body_2' => 'b']));
        $other = $this->tenant('Other', 'o@other.test');
        $foreign = $this->template($other['tenant_id'], (int) $this->account($other['tenant_id'])['id'], ['name' => 'f']);
        $this->assertThrows(ValidationException::class, fn () => $inbox->queueTemplate($conv, null, $foreign, ['body_1' => 'a', 'body_2' => 'b']));
    }

    public function testWebPagesAndPermissions(): void
    {
        $owner = $this->sessionFor($this->t['user_id']);
        $this->assertStatus(200, $this->web('GET', '/templates', $owner));
        $this->assertStatus(200, $this->web('GET', '/templates/new', $owner));
        $r = $this->web('POST', '/templates', $owner, ['intent' => 'draft'] + $this->input());
        $this->assertStatus(302, $r);
        $id = (int) $this->db->scalar("SELECT id FROM message_templates WHERE name = 'diwali_offer'");
        $html = $this->web('GET', '/templates/' . $id, $owner)->body;
        $this->assertContains('diwali_offer', $html);
        $this->assertContains('data-tpl-form', $html);
        $agent = $this->sessionFor($this->user($this->t['tenant_id'], 'support_agent', 'agent@acme.test'));
        $this->assertStatus(200, $this->web('GET', '/templates', $agent));
        $this->assertStatus(403, $this->web('POST', '/templates', $agent, $this->input(['name' => 'x_y'])));
        $other = $this->tenant('Other', 'o@other.test');
        $this->assertStatus(404, $this->web('GET', '/templates/' . $id, $this->sessionFor($other['user_id'])));
        $this->assertStatus(404, $this->web('POST', '/templates/' . $id . '/delete', $this->sessionFor($other['user_id'])));
    }
}
