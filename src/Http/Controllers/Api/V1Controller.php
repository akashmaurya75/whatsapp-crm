<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Contacts\ContactService;
use App\Domain\Conversations\InboxService;
use App\Domain\ValidationException;
use App\WhatsApp\AccountRepository;

/** REST API v1. Tenant and permissions come from the bearer token's user. */
final class V1Controller extends ApiController
{
    public static function contactResource(array $c): array
    {
        return [
            'id' => (int) $c['id'], 'name' => $c['name'], 'phone' => $c['phone_e164'], 'email' => $c['email'],
            'company' => $c['company'], 'city' => $c['city'], 'source' => $c['source'], 'lead_status' => $c['lead_status'],
            'consent_status' => $c['consent_status'], 'opted_out_at' => $c['opted_out_at'],
            'next_follow_up_at' => $c['next_follow_up_at'], 'archived_at' => $c['archived_at'],
            'created_at' => $c['created_at'], 'updated_at' => $c['updated_at'],
        ];
    }

    public static function messageResource(array $m): array
    {
        return [
            'id' => (int) $m['id'], 'conversation_id' => (int) $m['conversation_id'], 'direction' => $m['direction'] === 'in' ? 'inbound' : 'outbound',
            'type' => $m['type'], 'body' => $m['body'], 'status' => $m['status'], 'error_code' => $m['error_code'],
            'error_message' => $m['error_message'], 'provider_message_id' => $m['provider_message_id'], 'created_at' => $m['created_at'],
        ];
    }

    public function me(Request $request): Response
    {
        $u = $this->user();
        $tenant = $this->app->db->first('SELECT id, name, slug FROM tenants WHERE id = ?', [$u['tenant_id']]);
        return Response::json(['data' => ['id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'tenant' => $tenant], 'request_id' => $this->app->requestId]);
    }

    public function contacts(Request $request): Response
    {
        $this->app->gate()->authorize('contacts.view');
        $svc = new ContactService($this->app->db, $this->app->tenant);
        $page = $this->page($request);
        $per = $this->perPage($request);
        $r = $svc->repo->search([
            'q' => mb_substr((string) ($request->query['q'] ?? ''), 0, 100), 'status' => (string) ($request->query['lead_status'] ?? ''),
            'tag' => (string) ($request->query['tag'] ?? ''), 'sort' => (string) ($request->query['sort'] ?? 'created_at'),
            'dir' => (string) ($request->query['dir'] ?? 'desc'), 'archived' => ($request->query['archived'] ?? '') === 'true',
        ], $page, $per);
        return $this->paginated($r['data'], $r['total'], $page, $per, [self::class, 'contactResource']);
    }

    public function contact(Request $request): Response
    {
        $this->app->gate()->authorize('contacts.view');
        $svc = new ContactService($this->app->db, $this->app->tenant);
        $c = $svc->repo->findOrFail($request->intParam('id'));
        return Response::json(['data' => self::contactResource($c) + ['tags' => $svc->repo->tags((int) $c['id'])], 'request_id' => $this->app->requestId]);
    }

    public function createContact(Request $request): Response
    {
        $this->app->gate()->authorize('contacts.create');
        return $this->idempotent($request, function () use ($request): Response {
            $svc = new ContactService($this->app->db, $this->app->tenant);
            try {
                $id = $svc->create($request->json());
            } catch (ValidationException $e) {
                return Response::apiError('validation_failed', 'The given data was invalid.', 422, $e->errors);
            }
            if (isset($request->json()['tags']) && is_array($request->json()['tags'])) {
                $svc->repo->syncTags($id, array_map('strval', array_slice($request->json()['tags'], 0, 30)));
            }
            $this->app->auditLog('contact.created', 'contact', $id, ['via' => 'api']);
            return Response::json(['data' => self::contactResource((array) $svc->repo->find($id)), 'request_id' => $this->app->requestId], 201);
        });
    }

    public function updateContact(Request $request): Response
    {
        $this->app->gate()->authorize('contacts.update');
        $svc = new ContactService($this->app->db, $this->app->tenant);
        $id = $request->intParam('id');
        $input = $request->json();
        try {
            $svc->update($id, array_intersect_key($input, array_flip(['name', 'phone', 'email', 'company', 'city', 'address', 'source', 'lead_status', 'consent_status', 'owner_user_id', 'next_follow_up_at'])));
        } catch (ValidationException $e) {
            return Response::apiError('validation_failed', 'The given data was invalid.', 422, $e->errors);
        }
        if (isset($input['tags']) && is_array($input['tags'])) {
            $svc->repo->syncTags($id, array_map('strval', array_slice($input['tags'], 0, 30)));
        }
        $this->app->auditLog('contact.updated', 'contact', $id, ['via' => 'api']);
        return Response::json(['data' => self::contactResource((array) $svc->repo->find($id)), 'request_id' => $this->app->requestId]);
    }

    public function deleteContact(Request $request): Response
    {
        $this->app->gate()->authorize('contacts.delete');
        (new ContactService($this->app->db, $this->app->tenant))->archive($request->intParam('id'));
        $this->app->auditLog('contact.archived', 'contact', $request->intParam('id'), ['via' => 'api']);
        return new Response('', 204);
    }

    public function conversations(Request $request): Response
    {
        $this->app->gate()->authorize('inbox.view');
        $inbox = new InboxService($this->app->db, $this->app->tenant);
        $page = $this->page($request);
        $per = $this->perPage($request);
        $r = $inbox->conversations->inbox($this->user(), $this->app->can('inbox.view_all'), [
            'status' => (string) ($request->query['status'] ?? 'open'), 'assigned' => (string) ($request->query['assigned'] ?? ''),
            'q' => mb_substr((string) ($request->query['q'] ?? ''), 0, 100), 'account' => (int) ($request->query['whatsapp_account_id'] ?? 0),
        ], $page, $per);
        return $this->paginated($r['data'], $r['total'], $page, $per, static fn (array $c) => [
            'id' => (int) $c['id'], 'contact' => ['id' => (int) $c['contact_id'], 'name' => $c['contact_name'], 'phone' => $c['phone_e164']],
            'whatsapp_account_id' => (int) $c['whatsapp_account_id'], 'status' => $c['status'], 'priority' => $c['priority'],
            'assigned_user_id' => $c['assigned_user_id'] !== null ? (int) $c['assigned_user_id'] : null,
            'unread_count' => (int) $c['unread_count'], 'last_message_at' => $c['last_message_at'], 'last_message_preview' => $c['last_message_preview'],
        ]);
    }

    private function visibleConversation(Request $request): array
    {
        $inbox = new InboxService($this->app->db, $this->app->tenant, $this->app->queue);
        return $inbox->conversations->findVisible($request->intParam('id'), $this->user(), $this->app->can('inbox.view_all')) ?? throw HttpException::notFound();
    }

    public function messages(Request $request): Response
    {
        $this->app->gate()->authorize('inbox.view');
        $conv = $this->visibleConversation($request);
        $before = isset($request->query['before_id']) ? (int) $request->query['before_id'] : null;
        $rows = (new InboxService($this->app->db, $this->app->tenant))->conversations->messages((int) $conv['id'], $this->perPage($request), $before);
        return Response::json(['data' => array_map([self::class, 'messageResource'], $rows), 'request_id' => $this->app->requestId]);
    }

    public function sendMessage(Request $request): Response
    {
        $this->app->gate()->authorize('inbox.reply');
        $conv = $this->visibleConversation($request);
        $key = trim($request->header('idempotency-key')) ?: null;
        try {
            $inbox = new InboxService($this->app->db, $this->app->tenant, $this->app->queue);
            $msg = $inbox->queueText($conv, $this->app->userId(), (string) ($request->json()['body'] ?? ''), $key !== null ? mb_substr($key, 0, 100) : null);
        } catch (ValidationException $e) {
            return Response::apiError('validation_failed', $e->getMessage(), 422, $e->errors);
        }
        // 202: accepted for delivery; final status arrives asynchronously.
        return Response::json(['data' => self::messageResource($msg), 'request_id' => $this->app->requestId], 202);
    }

    public function accounts(Request $request): Response
    {
        $this->app->gate()->authorize('whatsapp.view');
        $rows = (new AccountRepository($this->app->db, $this->app->tenant))->allForTenant();
        return Response::json(['data' => array_map(fn (array $a) => [
            'id' => (int) $a['id'], 'name' => $a['name'], 'provider' => $a['provider'],
            'official' => $this->app->providers->has((string) $a['provider']) && $this->app->providers->get((string) $a['provider'])->isOfficial(),
            'phone' => $a['phone_e164'], 'status' => $a['status'], 'capabilities' => AccountRepository::capabilities($a),
            'last_synced_at' => $a['last_synced_at'],
        ], $rows), 'request_id' => $this->app->requestId]);
    }
}
