<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Domain\Templates\TemplateService;
use App\Domain\ValidationException;
use App\WhatsApp\AccountRepository;

final class TemplateController extends Controller
{
    private function svc(): TemplateService
    {
        return new TemplateService($this->app->db, $this->app->tenant, $this->app->providers);
    }

    private function metaAccounts(): array
    {
        return array_values(array_filter((new AccountRepository($this->app->db, $this->app->tenant))->allForTenant(), static fn ($a) => $a['provider'] === 'meta_cloud'));
    }

    private static function file(Request $request, string $key): ?array
    {
        $f = $request->files[$key] ?? null;
        return is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? $f : null;
    }

    public function index(Request $request): Response
    {
        $status = (string) ($request->query['status'] ?? '');
        $q = mb_substr((string) ($request->query['q'] ?? ''), 0, 60);
        $params = [$this->app->tenant->id()];
        $where = 't.tenant_id = ?';
        if (in_array($status, ['draft', 'pending', 'approved', 'rejected', 'paused', 'disabled', 'deleted'], true)) {
            $where .= ' AND t.status = ?';
            $params[] = $status;
        } else {
            $where .= " AND t.status <> 'deleted'";
        }
        if ($q !== '') {
            $where .= " AND (t.name LIKE ? ESCAPE '!' OR t.body LIKE ? ESCAPE '!')";
            $params[] = '%' . like_escape($q) . '%';
            $params[] = '%' . like_escape($q) . '%';
        }
        $counts = [];
        foreach ($this->app->db->all('SELECT status, COUNT(*) AS n FROM message_templates WHERE tenant_id = ? GROUP BY status', [$this->app->tenant->id()]) as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }
        return $this->app->render('templates/index', [
            'templates' => $this->app->db->all("SELECT t.*, a.name AS account_name FROM message_templates t JOIN whatsapp_accounts a ON a.id = t.whatsapp_account_id
                WHERE $where ORDER BY t.updated_at DESC LIMIT 500", $params),
            'counts' => $counts, 'status' => $status, 'q' => $q, 'accounts' => $this->metaAccounts(),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form(null);
    }

    private function form(?array $t, int $status = 200): Response
    {
        return $this->app->render('templates/form', [
            'template' => $t, 'accounts' => $this->metaAccounts(),
            'editable' => $t === null || in_array($t['status'], ['draft', 'rejected'], true),
        ], $status);
    }

    public function show(Request $request): Response
    {
        return $this->form($this->svc()->repo->findOrFail($request->intParam('id')));
    }

    public function store(Request $request): Response
    {
        return $this->save($request, null);
    }

    public function update(Request $request): Response
    {
        return $this->save($request, $request->intParam('id'));
    }

    private function save(Request $request, ?int $id): Response
    {
        $svc = $this->svc();
        try {
            $id = $svc->saveDraft($request->post, $this->app->userId(), $id, self::file($request, 'header_media'));
            $this->app->auditLog('template.saved', 'template', $id);
            if (($request->post['intent'] ?? '') === 'submit') {
                $t = $svc->submit($id);
                $this->app->auditLog('template.submitted', 'template', $id);
                $this->flash('success', $t['status'] === 'approved' ? 'Template approved by Meta.' : 'Submitted to Meta. Approval usually takes a few minutes to a few hours; the status updates automatically.');
            } else {
                $this->flash('success', 'Draft saved.');
            }
            return Response::redirect('/templates/' . $id);
        } catch (ValidationException $e) {
            return $this->backWithErrors($request, $e->errors, $id ? '/templates/' . $id : '/templates/new');
        }
    }

    public function submit(Request $request): Response
    {
        $id = $request->intParam('id');
        try {
            $t = $this->svc()->submit($id);
            $this->app->auditLog('template.submitted', 'template', $id);
            $this->flash('success', $t['status'] === 'approved' ? 'Template approved by Meta.' : 'Submitted to Meta for approval.');
        } catch (ValidationException $e) {
            $this->flash('error', $e->getMessage());
        }
        return Response::redirect('/templates/' . $id);
    }

    public function sync(Request $request): Response
    {
        $accountId = (int) ($request->post['whatsapp_account_id'] ?? 0);
        try {
            [$c, $u, $r] = $this->svc()->sync($accountId);
            $this->app->auditLog('template.synced', 'whatsapp_account', $accountId, ['created' => $c, 'updated' => $u, 'removed' => $r]);
            $this->flash('success', "Synced from Meta: $c new, $u updated" . ($r ? ", $r removed" : '') . '.');
        } catch (ValidationException $e) {
            $this->flash('error', $e->getMessage());
        }
        return Response::redirect('/templates');
    }

    public function duplicate(Request $request): Response
    {
        $id = $this->svc()->duplicate($request->intParam('id'), $this->app->userId());
        $this->flash('success', 'Copy created as a draft. Edit it and submit for approval.');
        return Response::redirect('/templates/' . $id);
    }

    public function destroy(Request $request): Response
    {
        $id = $request->intParam('id');
        try {
            $this->svc()->delete($id);
            $this->app->auditLog('template.deleted', 'template', $id);
            $this->flash('success', 'Template deleted.');
        } catch (ValidationException $e) {
            $this->flash('error', $e->getMessage());
            return Response::redirect('/templates/' . $id);
        }
        return Response::redirect('/templates');
    }
}
