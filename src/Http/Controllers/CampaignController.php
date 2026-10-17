<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Campaigns\CampaignService;
use App\Domain\Contacts\ContactService;
use App\Domain\Templates\TemplateService;
use App\Domain\ValidationException;

final class CampaignController extends Controller
{
    private function svc(): CampaignService
    {
        return new CampaignService($this->app->db, $this->app->tenant, $this->app->queue, $this->app->providers);
    }

    public function index(Request $request): Response
    {
        $status = (string) ($request->query['status'] ?? '');
        $params = [$this->app->tenant->id()];
        $where = 'c.tenant_id = ?';
        if (in_array($status, ['draft', 'scheduled', 'running', 'paused', 'completed', 'cancelled', 'failed'], true)) {
            $where .= ' AND c.status = ?';
            $params[] = $status;
        }
        $rows = $this->app->db->all("SELECT c.*, t.name AS template_name, a.name AS account_name,
                (SELECT COUNT(*) FROM campaign_recipients r WHERE r.campaign_id = c.id AND r.status IN ('submitted','sent','delivered','read')) AS sent_n,
                (SELECT COUNT(*) FROM campaign_recipients r WHERE r.campaign_id = c.id AND r.status IN ('delivered','read')) AS delivered_n,
                (SELECT COUNT(*) FROM campaign_recipients r WHERE r.campaign_id = c.id AND r.status = 'read') AS read_n,
                (SELECT COUNT(*) FROM campaign_recipients r WHERE r.campaign_id = c.id AND r.replied_at IS NOT NULL) AS replied_n,
                (SELECT COUNT(*) FROM campaign_recipients r WHERE r.campaign_id = c.id AND r.status = 'failed') AS failed_n
            FROM campaigns c JOIN message_templates t ON t.id = c.template_id JOIN whatsapp_accounts a ON a.id = c.whatsapp_account_id
            WHERE $where ORDER BY COALESCE(c.scheduled_at, c.created_at) DESC LIMIT 300", $params);
        return $this->app->render('campaigns/index', [
            'campaigns' => $rows, 'status' => $status, 'tz' => $this->svc()->timezone(),
            'templates' => (new TemplateService($this->app->db, $this->app->tenant))->sendable(),
        ]);
    }

    public function store(Request $request): Response
    {
        try {
            $id = $this->svc()->create($request->post, $this->app->userId());
            $this->app->auditLog('campaign.created', 'campaign', $id);
            return Response::redirect('/campaigns/' . $id . '/edit');
        } catch (ValidationException $e) {
            return $this->backWithErrors($request, $e->errors, '/campaigns');
        }
    }

    public function edit(Request $request): Response
    {
        $svc = $this->svc();
        $c = $svc->repo->findOrFail($request->intParam('id'));
        if (!in_array($c['status'], CampaignService::EDITABLE, true)) {
            return Response::redirect('/campaigns/' . $c['id']);
        }
        $t = $svc->template($c);
        $tid = $this->app->tenant->id();
        return $this->app->render('campaigns/edit', [
            'c' => $c, 't' => $t, 'tz' => $svc->timezone(),
            'audience' => CampaignService::audience($c), 'map' => CampaignService::variableMap($c) + TemplateService::defaultMap($t),
            'tags' => array_column($this->app->db->all('SELECT name FROM tags WHERE tenant_id = ? ORDER BY name', [$tid]), 'name'),
            'statuses' => ContactService::LEAD_STATUSES,
            'preview' => $svc->preview($c), 'problems' => $svc->problems($c),
        ]);
    }

    public function update(Request $request): Response
    {
        $id = $request->intParam('id');
        $file = $request->files['header_media'] ?? null;
        try {
            $this->svc()->saveSettings($id, $request->post, $this->app->userId(), is_array($file) ? $file : null);
            if (empty($request->post['require_consent'])) {
                $this->app->auditLog('campaign.consent_attested', 'campaign', $id);
            }
            $this->flash('success', 'Campaign saved. Review the audience below, then launch.');
        } catch (ValidationException $e) {
            return $this->backWithErrors($request, $e->errors, '/campaigns/' . $id . '/edit');
        }
        return Response::redirect('/campaigns/' . $id . '/edit#review');
    }

    public function show(Request $request): Response
    {
        $svc = $this->svc();
        $c = $svc->repo->findOrFail($request->intParam('id'));
        $filter = (string) ($request->query['r'] ?? '');
        $page = $this->page($request);
        $params = [$c['id'], $this->app->tenant->id()];
        $where = 'r.campaign_id = ? AND r.tenant_id = ?';
        if ($filter === 'replied') {
            $where .= ' AND r.replied_at IS NOT NULL';
        } elseif (in_array($filter, ['pending', 'queued', 'submitted', 'sent', 'delivered', 'read', 'failed', 'skipped'], true)) {
            $where .= ' AND r.status = ?';
            $params[] = $filter;
        }
        $total = (int) $this->app->db->scalar("SELECT COUNT(*) FROM campaign_recipients r WHERE $where", $params);
        return $this->app->render('campaigns/show', [
            'c' => $c, 't' => $svc->template($c), 'stats' => $svc->stats($c), 'tz' => $svc->timezone(), 'filter' => $filter,
            'recipients' => $this->app->db->all("SELECT r.*, ct.name, ct.phone_e164 FROM campaign_recipients r JOIN contacts ct ON ct.id = r.contact_id
                WHERE $where ORDER BY r.id LIMIT 50 OFFSET " . (($page - 1) * 50), $params),
            'total' => $total, 'page' => $page,
            'skips' => $this->app->db->all("SELECT skip_reason, COUNT(*) AS n FROM campaign_recipients WHERE campaign_id = ? AND status = 'skipped' GROUP BY skip_reason", [$c['id']]),
        ]);
    }

    public function action(Request $request): Response
    {
        $id = $request->intParam('id');
        $svc = $this->svc();
        $action = $request->str('action');
        try {
            match ($action) {
                'launch' => $svc->launch($id),
                'pause' => $svc->pause($id),
                'resume' => $svc->resume($id),
                'cancel' => $svc->cancel($id),
                default => throw HttpException::notFound(),
            };
            $this->app->auditLog('campaign.' . $action, 'campaign', $id);
            $c = (array) $svc->repo->find($id);
            $this->flash('success', match ($action) {
                'launch' => strtotime($c['scheduled_at'] . ' UTC') > time() + 60 ? 'Campaign scheduled.' : 'Campaign is sending now.',
                'pause' => 'Campaign paused. Messages not yet sent are on hold.',
                'resume' => 'Campaign resumed.',
                default => 'Campaign cancelled. Messages not yet sent were dropped.',
            });
        } catch (ValidationException $e) {
            $this->flash('error', $e->getMessage());
            return Response::redirect('/campaigns/' . $id . ($action === 'launch' ? '/edit#review' : ''));
        }
        return Response::redirect('/campaigns/' . $id);
    }

    public function test(Request $request): Response
    {
        $id = $request->intParam('id');
        try {
            $this->svc()->sendTest($id, $request->str('test_phone'), $this->app->userId());
            $this->flash('success', 'Test message queued. Check the phone in a few seconds.');
        } catch (ValidationException $e) {
            return $this->backWithErrors($request, $e->errors, '/campaigns/' . $id . '/edit#review');
        }
        return Response::redirect('/campaigns/' . $id . '/edit#review');
    }

    public function duplicate(Request $request): Response
    {
        $id = $this->svc()->duplicate($request->intParam('id'), $this->app->userId());
        $this->flash('success', 'Campaign copied as a draft.');
        return Response::redirect('/campaigns/' . $id . '/edit');
    }

    public function destroy(Request $request): Response
    {
        try {
            $this->svc()->delete($request->intParam('id'));
            $this->flash('success', 'Campaign deleted.');
        } catch (ValidationException $e) {
            $this->flash('error', $e->getMessage());
        }
        return Response::redirect('/campaigns');
    }

    public function export(Request $request): Response
    {
        $svc = $this->svc();
        $c = $svc->repo->findOrFail($request->intParam('id'));
        $fh = fopen('php://temp', 'w+');
        fputcsv($fh, ['name', 'phone', 'status', 'skip_reason', 'error', 'replied_at', 'updated_at'], ',', '"', '\\');
        foreach ($this->app->db->all('SELECT r.*, ct.name, ct.phone_e164 FROM campaign_recipients r JOIN contacts ct ON ct.id = r.contact_id
            WHERE r.campaign_id = ? AND r.tenant_id = ? ORDER BY r.id', [$c['id'], $this->app->tenant->id()]) as $r) {
            fputcsv($fh, array_map([ContactService::class, 'csvSafe'], [$r['name'], $r['phone_e164'], $r['status'], $r['skip_reason'], $r['error'], $r['replied_at'], $r['updated_at']]), ',', '"', '\\');
        }
        rewind($fh);
        $this->app->auditLog('campaign.exported', 'campaign', (int) $c['id']);
        return new Response((string) stream_get_contents($fh), 200, ['Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="campaign-' . (int) $c['id'] . '-report.csv"', 'Cache-Control' => 'no-store']);
    }
}
