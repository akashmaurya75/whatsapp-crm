<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Domain\Automation\AutomationService;
use App\Domain\Contacts\ContactService;
use App\Domain\Templates\TemplateRepository;
use App\Domain\Templates\TemplateService;
use App\Domain\ValidationException;

final class AutomationController extends Controller
{
    private function svc(): AutomationService
    {
        return new AutomationService($this->app->db, $this->app->tenant, $this->app->queue);
    }

    public function index(Request $request): Response
    {
        $svc = $this->svc();
        $rules = $svc->rules();
        return $this->app->render('automations/index', [
            'welcome' => array_values(array_filter($rules, static fn ($r) => $r['type'] === 'welcome'))[0] ?? null,
            'away' => array_values(array_filter($rules, static fn ($r) => $r['type'] === 'away'))[0] ?? null,
            'keywords' => array_values(array_filter($rules, static fn ($r) => $r['type'] === 'keyword')),
            'runs' => $svc->recentRuns(25),
        ]);
    }

    public function create(Request $request): Response
    {
        $type = (string) ($request->query['type'] ?? 'keyword');
        if (!isset(AutomationService::TYPES[$type])) {
            $type = 'keyword';
        }
        return $this->form($request, null, $type);
    }

    public function edit(Request $request): Response
    {
        $rule = $this->svc()->find($request->intParam('id'));
        return $this->form($request, $rule, (string) $rule['type']);
    }

    private function form(Request $request, ?array $rule, string $type): Response
    {
        $cfg = $rule ? AutomationService::config($rule) : [];
        $tid = $this->app->tenant->id();
        // Template replies: the template can be switched with ?template=ID to show its variables.
        $templateId = (int) ($request->query['template'] ?? ($cfg['reply']['template_id'] ?? 0));
        $template = $templateId > 0 ? (new TemplateRepository($this->app->db, $this->app->tenant))->find($templateId) : null;
        return $this->app->render('automations/form', [
            'rule' => $rule, 'type' => $type, 'cfg' => $cfg, 'template' => $template,
            'map' => $template ? (($cfg['reply']['template_id'] ?? 0) === (int) $template['id'] ? (array) $cfg['reply']['map'] : []) + TemplateService::defaultMap($template) : [],
            'templates' => (new TemplateService($this->app->db, $this->app->tenant))->sendable(),
            'teams' => $this->app->db->all('SELECT id, name FROM teams WHERE tenant_id = ? ORDER BY name', [$tid]),
            'users' => $this->app->db->all("SELECT id, name FROM users WHERE tenant_id = ? AND status = 'active' ORDER BY name", [$tid]),
            'statuses' => ContactService::LEAD_STATUSES,
            'tz' => (string) env('APP_TIMEZONE', 'UTC'),
        ]);
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
        try {
            $id = $this->svc()->save($request->post, $id);
            $this->app->auditLog('automation.saved', 'automation_rule', $id);
            $this->flash('success', 'Automation saved.');
            return Response::redirect('/automations');
        } catch (ValidationException $e) {
            $back = $id ? '/automations/' . $id : '/automations/new?type=' . urlencode((string) ($request->post['type'] ?? 'keyword'));
            return $this->backWithErrors($request, $e->errors, $back);
        }
    }

    public function toggle(Request $request): Response
    {
        $this->svc()->toggle($request->intParam('id'));
        $this->app->auditLog('automation.toggled', 'automation_rule', $request->intParam('id'));
        return Response::redirect('/automations');
    }

    public function destroy(Request $request): Response
    {
        $this->svc()->delete($request->intParam('id'));
        $this->app->auditLog('automation.deleted', 'automation_rule', $request->intParam('id'));
        $this->flash('success', 'Automation deleted.');
        return Response::redirect('/automations');
    }
}
