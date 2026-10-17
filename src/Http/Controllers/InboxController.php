<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Contacts\ContactRepository;
use App\Domain\Conversations\InboxService;
use App\Domain\Templates\TemplateRepository;
use App\Domain\Templates\TemplateService;
use App\Domain\ValidationException;
use App\WhatsApp\AccountRepository;
use App\WhatsApp\Capability;

final class InboxController extends Controller
{
    private function inbox(): InboxService
    {
        return new InboxService($this->app->db, $this->app->tenant, $this->app->queue);
    }

    private function conversationOr404(InboxService $inbox, Request $request): array
    {
        return $inbox->conversations->findVisible($request->intParam('id'), $this->user(), $this->app->can('inbox.view_all'))
            ?? throw HttpException::notFound();
    }

    private function filters(Request $request): array
    {
        return [
            'q' => mb_substr((string) ($request->query['q'] ?? ''), 0, 100),
            'status' => in_array($request->query['status'] ?? '', ['open', 'closed', 'archived', 'all'], true) ? $request->query['status'] : 'open',
            'assigned' => in_array($request->query['assigned'] ?? '', ['me', 'unassigned', 'awaiting'], true) ? $request->query['assigned'] : '',
            'account' => (int) ($request->query['account'] ?? 0),
        ];
    }

    public function index(Request $request): Response
    {
        return $this->renderInbox($request, null);
    }

    public function show(Request $request): Response
    {
        $inbox = $this->inbox();
        $conv = $this->conversationOr404($inbox, $request);
        if ((int) $conv['unread_count'] > 0) {
            $inbox->setFlag($conv, 'read');
            $conv['unread_count'] = 0;
        }
        return $this->renderInbox($request, $conv);
    }

    private function renderInbox(Request $request, ?array $conv): Response
    {
        $inbox = $this->inbox();
        $filters = $this->filters($request);
        $list = $inbox->conversations->inbox($this->user(), $this->app->can('inbox.view_all'), $filters, $this->page($request), 30);
        $data = [
            'list' => $list['data'], 'total' => $list['total'], 'page' => $this->page($request), 'filters' => $filters,
            'conv' => $conv, 'accounts' => $inbox->accounts->allForTenant(),
        ];
        if ($conv !== null) {
            $tid = $this->app->tenant->id();
            $before = isset($request->query['before']) ? (int) $request->query['before'] : null;
            $repo = new ContactRepository($this->app->db, $this->app->tenant);
            $data += [
                'messages' => $inbox->conversations->messages((int) $conv['id'], 50, $before),
                'tags' => $repo->tags((int) $conv['contact_id']),
                'notes' => $repo->notes((int) $conv['contact_id']),
                'agents' => $this->app->db->all("SELECT id, name FROM users WHERE tenant_id = ? AND status = 'active' ORDER BY name", [$tid]),
                'teams' => $this->app->db->all('SELECT id, name FROM teams WHERE tenant_id = ? ORDER BY name', [$tid]),
                'windowOpen' => $inbox->withinServiceWindow($conv),
                'caps' => json_decode((string) $conv['capabilities'], true) ?: [],
                'templates' => $conv['provider'] === 'meta_cloud' ? (new TemplateService($this->app->db, $this->app->tenant))->sendable((int) $conv['whatsapp_account_id']) : [],
                'pickedTemplate' => $this->pickedTemplate($request, $conv),
                'history' => $this->app->db->all(
                    'SELECT ca.created_at, u1.name AS to_name, t.name AS team_name, u2.name AS by_name FROM conversation_assignments ca
                     LEFT JOIN users u1 ON u1.id = ca.to_user_id LEFT JOIN teams t ON t.id = ca.to_team_id LEFT JOIN users u2 ON u2.id = ca.assigned_by
                     WHERE ca.conversation_id = ? AND ca.tenant_id = ? ORDER BY ca.id DESC LIMIT 10',
                    [$conv['id'], $tid]
                ),
            ];
        }
        return $this->app->render('inbox/index', $data);
    }

    public function send(Request $request): Response
    {
        $inbox = $this->inbox();
        $conv = $this->conversationOr404($inbox, $request);
        try {
            $replyTo = (int) ($request->post['reply_to'] ?? 0);
            $inbox->queueText($conv, $this->app->userId(), (string) ($request->post['body'] ?? ''),
                mb_substr($request->str('idempotency_key'), 0, 100) ?: null, $replyTo > 0 ? $replyTo : null);
        } catch (ValidationException $e) {
            if ($request->wantsJson()) {
                return Response::apiError('validation_failed', $e->getMessage(), 422, $e->errors);
            }
            return $this->backWithErrors($request, $e->errors, '/inbox/' . $conv['id']);
        }
        if ($request->wantsJson()) {
            return Response::json(['ok' => true]);
        }
        return Response::redirect('/inbox/' . $conv['id']);
    }

    private function pickedTemplate(Request $request, array $conv): ?array
    {
        $id = (int) ($request->query['template'] ?? 0);
        if ($id === 0) {
            return null;
        }
        $t = (new TemplateRepository($this->app->db, $this->app->tenant))->find($id);
        if ($t === null || (int) $t['whatsapp_account_id'] !== (int) $conv['whatsapp_account_id'] || TemplateService::unsendableReason($t) !== null) {
            return null;
        }
        $contact = (array) $this->app->db->first('SELECT * FROM contacts WHERE id = ? AND tenant_id = ?', [$conv['contact_id'], $this->app->tenant->id()]);
        // Pre-fill variables: {{1}} → first name, others → sample values.
        $t['prefill'] = TemplateService::resolve(TemplateService::defaultMap($t), $contact);
        $t['slots'] = TemplateService::slots($t);
        return $t;
    }

    /** Send an approved template (works even when the 24-hour window is closed). */
    public function sendTemplate(Request $request): Response
    {
        $inbox = $this->inbox();
        $conv = $this->conversationOr404($inbox, $request);
        $t = (new TemplateRepository($this->app->db, $this->app->tenant))->findOrFail((int) ($request->post['template_id'] ?? 0));
        $values = [];
        foreach (array_keys(TemplateService::slots($t)) as $slot) {
            $values[$slot] = mb_substr(trim((string) (($request->post['vars'] ?? [])[$slot] ?? '')), 0, 1024);
        }
        $media = null;
        $link = trim((string) ($request->post['media_link'] ?? ''));
        if (TemplateService::needsMedia($t)) {
            if (!filter_var($link, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($link), 'https://')) {
                return $this->backWithErrors($request, ['template' => 'Paste a public https:// link to the ' . $t['header_type'] . ' for the header.'], '/inbox/' . $conv['id'] . '?template=' . $t['id']);
            }
            $media = ['link' => $link, 'filename' => basename((string) parse_url($link, PHP_URL_PATH)) ?: 'document.pdf'];
        }
        try {
            $inbox->queueTemplate($conv, $this->app->userId(), $t, $values, $media, mb_substr($request->str('idempotency_key'), 0, 100) ?: null);
        } catch (ValidationException $e) {
            return $this->backWithErrors($request, ['template' => $e->getMessage()], '/inbox/' . $conv['id'] . '?template=' . $t['id']);
        }
        $this->flash('success', 'Template message queued.');
        return Response::redirect('/inbox/' . $conv['id']);
    }

    public function assign(Request $request): Response
    {
        $inbox = $this->inbox();
        $conv = $this->conversationOr404($inbox, $request);
        $user = (int) ($request->post['assigned_user_id'] ?? 0);
        $team = (int) ($request->post['assigned_team_id'] ?? 0);
        // Agents without inbox.assign may only claim an unassigned conversation for themselves.
        if (!$this->app->can('inbox.assign') && !($user === $this->app->userId() && $conv['assigned_user_id'] === null && $team === 0)) {
            throw HttpException::forbidden();
        }
        return $this->handleValidation($request, function () use ($inbox, $conv, $user, $team): Response {
            $inbox->assign($conv, $user ?: null, $team ?: null, $this->app->userId());
            $this->app->auditLog('conversation.assigned', 'conversation', (int) $conv['id'], ['to_user' => $user ?: null, 'to_team' => $team ?: null]);
            $this->flash('success', 'Assignment updated.');
            return Response::redirect('/inbox/' . $conv['id']);
        }, '/inbox/' . $conv['id']);
    }

    public function flag(Request $request): Response
    {
        $inbox = $this->inbox();
        $conv = $this->conversationOr404($inbox, $request);
        return $this->handleValidation($request, function () use ($inbox, $conv, $request): Response {
            $action = $request->str('action');
            $inbox->setFlag($conv, $action);
            return Response::redirect($action === 'unread' || $action === 'archive' ? '/inbox' : '/inbox/' . $conv['id']);
        }, '/inbox/' . $conv['id']);
    }

    public function priority(Request $request): Response
    {
        $inbox = $this->inbox();
        $conv = $this->conversationOr404($inbox, $request);
        return $this->handleValidation($request, function () use ($inbox, $conv, $request): Response {
            $inbox->setPriority($conv, $request->str('priority'));
            return Response::redirect('/inbox/' . $conv['id']);
        }, '/inbox/' . $conv['id']);
    }

    public function note(Request $request): Response
    {
        $inbox = $this->inbox();
        $conv = $this->conversationOr404($inbox, $request);
        $body = $request->str('body');
        if ($body === '' || mb_strlen($body) > 5000) {
            return $this->backWithErrors($request, ['note' => 'Note must be 1–5000 characters.'], '/inbox/' . $conv['id']);
        }
        (new ContactRepository($this->app->db, $this->app->tenant))->addNote((int) $conv['contact_id'], (int) $conv['id'], $this->app->userId(), $body);
        $this->flash('success', 'Internal note added.');
        return Response::redirect('/inbox/' . $conv['id']);
    }

    /** JSON polling endpoint: messages newer than ?after=id plus status changes for recent ones. */
    public function poll(Request $request): Response
    {
        $inbox = $this->inbox();
        $conv = $this->conversationOr404($inbox, $request);
        $after = (int) ($request->query['after'] ?? 0);
        $rows = $this->app->db->all(
            'SELECT m.id, m.direction, m.type, m.body, m.status, m.error_message, m.created_at, u.name AS sender_name
             FROM messages m LEFT JOIN users u ON u.id = m.sent_by_user_id
             WHERE m.conversation_id = ? AND m.tenant_id = ? AND m.id > ? ORDER BY m.id LIMIT 100',
            [$conv['id'], $this->app->tenant->id(), $after]
        );
        $statuses = $this->app->db->all(
            "SELECT id, status, error_message FROM messages WHERE conversation_id = ? AND tenant_id = ? AND direction = 'out' ORDER BY id DESC LIMIT 50",
            [$conv['id'], $this->app->tenant->id()]
        );
        if ($rows !== [] && (int) $conv['unread_count'] > 0) {
            $inbox->setFlag($conv, 'read');
        }
        return Response::json(['messages' => $rows, 'statuses' => $statuses]);
    }
}
