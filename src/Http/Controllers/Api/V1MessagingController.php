<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Domain\Campaigns\CampaignService;
use App\Domain\Contacts\PhoneNormalizer;
use App\Domain\Conversations\InboxService;
use App\Domain\Templates\TemplateService;
use App\Domain\ValidationException;
use App\WhatsApp\AccountRepository;

/** Templates, template sending (transactional notifications) and campaign reporting. */
final class V1MessagingController extends ApiController
{
    public static function templateResource(array $t): array
    {
        return [
            'id' => (int) $t['id'], 'whatsapp_account_id' => (int) $t['whatsapp_account_id'], 'name' => $t['name'], 'language' => $t['language'],
            'category' => $t['category'], 'status' => $t['status'], 'rejected_reason' => $t['rejected_reason'],
            'header_type' => $t['header_type'], 'variables' => array_keys(TemplateService::slots($t)),
            'sendable' => TemplateService::unsendableReason($t) === null, 'preview' => TemplateService::render($t, []),
        ];
    }

    public function templates(Request $request): Response
    {
        $this->app->gate()->authorize('templates.view');
        $params = [$this->app->tenant->id()];
        $where = "tenant_id = ? AND status <> 'deleted'";
        if (($s = (string) ($request->query['status'] ?? '')) !== '') {
            $where .= ' AND status = ?';
            $params[] = $s;
        }
        $rows = $this->app->db->all("SELECT * FROM message_templates WHERE $where ORDER BY name", $params);
        return Response::json(['data' => array_map([self::class, 'templateResource'], $rows), 'request_id' => $this->app->requestId]);
    }

    /**
     * POST /api/v1/messages/template
     * {"phone":"+9198…","template":"order_update","language":"en","variables":{"body_1":"Asha","body_2":"#1042"},
     *  "media_url":"https://…/invoice.pdf","whatsapp_account_id":1,"name":"Asha"}
     * Creates the contact if needed. Respects opt-outs. Use an Idempotency-Key header to avoid duplicates.
     */
    public function sendTemplate(Request $request): Response
    {
        $this->app->gate()->authorize('inbox.reply');
        return $this->idempotent($request, function () use ($request): Response {
            $in = $request->json();
            $tid = $this->app->tenant->id();
            $phone = PhoneNormalizer::normalize((string) ($in['phone'] ?? ''));
            if ($phone === null) {
                return Response::apiError('validation_failed', 'phone must be a valid number with country code.', 422, ['phone' => 'invalid']);
            }
            $accounts = (new AccountRepository($this->app->db, $this->app->tenant))->allForTenant();
            $accountId = (int) ($in['whatsapp_account_id'] ?? 0);
            $account = null;
            foreach ($accounts as $a) {
                if ($a['provider'] === 'meta_cloud' && ($accountId === 0 || (int) $a['id'] === $accountId)) {
                    $account = $a;
                    break;
                }
            }
            if ($account === null) {
                return Response::apiError('validation_failed', 'No matching Meta Cloud API account.', 422, ['whatsapp_account_id' => 'not found']);
            }
            $lang = (string) ($in['language'] ?? '');
            $t = $this->app->db->first('SELECT * FROM message_templates WHERE tenant_id = ? AND whatsapp_account_id = ? AND name = ?'
                . ($lang !== '' ? ' AND language = ?' : '') . " AND status = 'approved' ORDER BY id LIMIT 1",
                array_merge([$tid, $account['id'], (string) ($in['template'] ?? '')], $lang !== '' ? [$lang] : []));
            if ($t === null) {
                return Response::apiError('validation_failed', 'No approved template with that name (and language) on this number.', 422, ['template' => 'not found']);
            }
            $values = array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', (array) ($in['variables'] ?? []));
            $media = null;
            if (TemplateService::needsMedia($t)) {
                $url = (string) ($in['media_url'] ?? '');
                if (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($url), 'https://')) {
                    return Response::apiError('validation_failed', 'media_url (https) is required for this template header.', 422, ['media_url' => 'required']);
                }
                $media = ['link' => $url, 'filename' => (string) ($in['filename'] ?? (basename((string) parse_url($url, PHP_URL_PATH)) ?: 'document.pdf'))];
            }
            $inbox = new InboxService($this->app->db, $this->app->tenant, $this->app->queue);
            try {
                $contact = $inbox->contacts->findOrCreateByPhone($phone, isset($in['name']) ? mb_substr((string) $in['name'], 0, 160) : null);
                $conv = $inbox->findOrCreateConversation((int) $contact['id'], (int) $account['id']);
                $msg = $inbox->queueTemplate($conv, $this->app->userId(), $t, $values, $media);
            } catch (ValidationException $e) {
                return Response::apiError('validation_failed', $e->getMessage(), 422, $e->errors);
            }
            return Response::json(['data' => V1Controller::messageResource($msg) + ['contact_id' => (int) $contact['id']], 'request_id' => $this->app->requestId], 202);
        });
    }

    public function campaigns(Request $request): Response
    {
        $this->app->gate()->authorize('campaigns.view');
        $svc = new CampaignService($this->app->db, $this->app->tenant);
        $rows = $this->app->db->all('SELECT * FROM campaigns WHERE tenant_id = ? ORDER BY id DESC LIMIT 100', [$this->app->tenant->id()]);
        return Response::json(['data' => array_map(static fn ($c) => self::campaignResource($c, $svc->stats($c)), $rows), 'request_id' => $this->app->requestId]);
    }

    public function campaign(Request $request): Response
    {
        $this->app->gate()->authorize('campaigns.view');
        $svc = new CampaignService($this->app->db, $this->app->tenant);
        $c = $svc->repo->findOrFail($request->intParam('id'));
        return Response::json(['data' => self::campaignResource($c, $svc->stats($c)), 'request_id' => $this->app->requestId]);
    }

    private static function campaignResource(array $c, array $s): array
    {
        return [
            'id' => (int) $c['id'], 'name' => $c['name'], 'status' => $c['status'], 'template_id' => (int) $c['template_id'],
            'scheduled_at' => $c['scheduled_at'], 'started_at' => $c['started_at'], 'completed_at' => $c['completed_at'],
            'stats' => ['recipients' => $s['audience'], 'skipped' => $s['skipped'], 'sent' => $s['accepted'], 'delivered' => $s['delivered_total'],
                'read' => $s['read'], 'replied' => $s['replied'], 'failed' => $s['failed'], 'pending' => $s['in_progress']],
        ];
    }
}
