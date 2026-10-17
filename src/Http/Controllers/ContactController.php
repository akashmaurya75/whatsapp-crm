<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Domain\Contacts\ContactService;
use App\Domain\Conversations\InboxService;
use App\Domain\ValidationException;
use App\WhatsApp\AccountRepository;

final class ContactController extends Controller
{
    private function service(): ContactService
    {
        return new ContactService($this->app->db, $this->app->tenant);
    }

    private function filters(Request $request): array
    {
        return [
            'q' => mb_substr((string) ($request->query['q'] ?? ''), 0, 100),
            'status' => (string) ($request->query['status'] ?? ''),
            'tag' => mb_substr((string) ($request->query['tag'] ?? ''), 0, 60),
            'archived' => ($request->query['archived'] ?? '') === '1',
            'sort' => (string) ($request->query['sort'] ?? 'created_at'),
            'dir' => (string) ($request->query['dir'] ?? 'desc'),
        ];
    }

    public function index(Request $request): Response
    {
        $svc = $this->service();
        $filters = $this->filters($request);
        $page = $this->page($request);
        $result = $svc->repo->search($filters, $page, 25);
        return $this->app->render('contacts/index', [
            'contacts' => $result['data'], 'total' => $result['total'], 'page' => $page, 'perPage' => 25,
            'filters' => $filters, 'statuses' => ContactService::LEAD_STATUSES, 'tags' => $svc->repo->allTagNames(),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->app->render('contacts/form', ['contact' => null, 'users' => $this->users()]);
    }

    public function store(Request $request): Response
    {
        return $this->handleValidation($request, function () use ($request): Response {
            $id = $this->service()->create($request->post);
            $this->app->auditLog('contact.created', 'contact', $id);
            $this->flash('success', 'Contact created.');
            return Response::redirect('/contacts/' . $id);
        }, '/contacts/new');
    }

    public function show(Request $request): Response
    {
        $svc = $this->service();
        $contact = $svc->repo->findOrFail($request->intParam('id'));
        $tid = $this->app->tenant->id();
        return $this->app->render('contacts/show', [
            'contact' => $contact,
            'tags' => $svc->repo->tags((int) $contact['id']),
            'notes' => $svc->repo->notes((int) $contact['id']),
            'users' => $this->users(),
            'statuses' => ContactService::LEAD_STATUSES,
            'conversations' => $this->app->db->all(
                'SELECT cv.*, a.name AS account_name FROM conversations cv JOIN whatsapp_accounts a ON a.id = cv.whatsapp_account_id
                 WHERE cv.contact_id = ? AND cv.tenant_id = ? ORDER BY cv.last_message_at DESC',
                [$contact['id'], $tid]
            ),
            'accounts' => (new AccountRepository($this->app->db, $this->app->tenant))->allForTenant(),
        ]);
    }

    public function update(Request $request): Response
    {
        $id = $request->intParam('id');
        return $this->handleValidation($request, function () use ($request, $id): Response {
            $input = array_intersect_key($request->post, array_flip([
                'name', 'phone', 'email', 'company', 'city', 'address', 'source', 'lead_status', 'consent_status', 'owner_user_id', 'next_follow_up_at',
            ]));
            $this->service()->update($id, $input);
            $this->app->auditLog('contact.updated', 'contact', $id, ['fields' => array_keys($input)]);
            $this->flash('success', 'Contact saved.');
            return Response::redirect('/contacts/' . $id);
        }, '/contacts/' . $id);
    }

    public function archive(Request $request): Response
    {
        $id = $request->intParam('id');
        $this->service()->archive($id);
        $this->app->auditLog('contact.archived', 'contact', $id);
        $this->flash('success', 'Contact archived.');
        return Response::redirect('/contacts');
    }

    public function restore(Request $request): Response
    {
        $id = $request->intParam('id');
        $this->service()->restore($id);
        $this->app->auditLog('contact.restored', 'contact', $id);
        $this->flash('success', 'Contact restored.');
        return Response::redirect('/contacts/' . $id);
    }

    public function tags(Request $request): Response
    {
        $svc = $this->service();
        $contact = $svc->repo->findOrFail($request->intParam('id'));
        $names = array_filter(array_map('trim', explode(',', $request->str('tags'))));
        $svc->repo->syncTags((int) $contact['id'], array_slice($names, 0, 30));
        $this->flash('success', 'Tags updated.');
        return $this->app->back($request, '/contacts/' . $contact['id']);
    }

    public function note(Request $request): Response
    {
        $svc = $this->service();
        $contact = $svc->repo->findOrFail($request->intParam('id'));
        $body = $request->str('body');
        if ($body === '' || mb_strlen($body) > 5000) {
            return $this->backWithErrors($request, ['body' => 'Note must be 1–5000 characters.']);
        }
        $svc->repo->addNote((int) $contact['id'], null, $this->app->userId(), $body);
        $this->flash('success', 'Note added.');
        return $this->app->back($request, '/contacts/' . $contact['id']);
    }

    /** Open (or create) a conversation with this contact on a chosen account. */
    public function startConversation(Request $request): Response
    {
        $inbox = new InboxService($this->app->db, $this->app->tenant, $this->app->queue);
        $contact = $inbox->contacts->repo->findOrFail($request->intParam('id'));
        $account = $inbox->accounts->findOrFail((int) $request->input('account_id', 0));
        $conv = $inbox->findOrCreateConversation((int) $contact['id'], (int) $account['id']);
        return Response::redirect('/inbox/' . $conv['id']);
    }

    public function export(Request $request): Response
    {
        $csv = $this->service()->exportCsv($this->filters($request));
        $this->app->auditLog('contact.exported', null, null, ['filters' => $this->filters($request)]);
        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="contacts-' . gmdate('Ymd-His') . '.csv"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function importForm(Request $request): Response
    {
        return $this->app->render('contacts/import', ['result' => $this->app->session->getFlash('import_result')]);
    }

    public function import(Request $request): Response
    {
        $file = $request->files['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->backWithErrors($request, ['file' => 'Choose a CSV file to upload.'], '/contacts/import');
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'], true)) {
            return $this->backWithErrors($request, ['file' => 'Upload a .csv file. In Excel or Google Sheets use File → Save as / Download → CSV.'], '/contacts/import');
        }
        if (!empty($request->post['consent_all']) && empty($request->post['consent_confirm'])) {
            return $this->backWithErrors($request, ['consent_all' => 'Tick the confirmation to mark everyone as consented.'], '/contacts/import');
        }
        try {
            $result = (new \App\Domain\Contacts\ContactImporter($this->app->db, $this->app->tenant))->import((string) $file['tmp_name'], [
                'tags' => $request->str('tags'), 'consent_all' => !empty($request->post['consent_all']),
                'update_existing' => !empty($request->post['update_existing']), 'source' => $request->str('source'),
            ]);
        } catch (ValidationException $e) {
            return $this->backWithErrors($request, $e->errors, '/contacts/import');
        }
        $this->app->auditLog('contact.imported', null, null, ['file' => mb_substr((string) $file['name'], 0, 100), 'created' => $result['created'],
            'updated' => $result['updated'], 'skipped' => $result['skipped'], 'consent_all' => !empty($request->post['consent_all'])]);
        $this->app->session->flash('import_result', $result);
        return Response::redirect('/contacts/import');
    }

    private function users(): array
    {
        return $this->app->db->all("SELECT id, name FROM users WHERE tenant_id = ? AND status = 'active' ORDER BY name", [$this->app->tenant->id()]);
    }
}
