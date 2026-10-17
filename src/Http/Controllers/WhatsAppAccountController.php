<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Crypto;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Domain\Contacts\PhoneNormalizer;
use App\WhatsApp\AccountRepository;
use App\WhatsApp\WebhookDoctor;
use App\WhatsApp\Capability;

final class WhatsAppAccountController extends Controller
{
    private function repo(): AccountRepository
    {
        return new AccountRepository($this->app->db, $this->app->tenant);
    }

    public function index(Request $request): Response
    {
        $tid = $this->app->tenant->id();
        $usage = [];
        foreach ($this->app->db->all(
            "SELECT whatsapp_account_id, direction, COUNT(*) AS n FROM messages WHERE tenant_id = ? AND created_at >= ? GROUP BY whatsapp_account_id, direction",
            [$tid, gmdate('Y-m-01 00:00:00')]
        ) as $r) {
            $usage[$r['whatsapp_account_id']][$r['direction']] = (int) $r['n'];
        }
        return $this->app->render('whatsapp/index', [
            'accounts' => $this->repo()->allForTenant(), 'providers' => $this->app->providers->all(), 'usage' => $usage,
        ]);
    }

    public function create(Request $request): Response
    {
        $key = (string) ($request->query['provider'] ?? 'meta_cloud');
        if (!$this->app->providers->has($key)) {
            throw HttpException::notFound();
        }
        return $this->app->render('whatsapp/form', ['account' => null, 'provider' => $this->app->providers->get($key)]);
    }

    public function show(Request $request): Response
    {
        $account = $this->repo()->findOrFail($request->intParam('id'));
        $provider = $this->app->providers->get((string) $account['provider']);
        $errors = $this->app->db->all(
            "SELECT error_code, error_message, updated_at FROM messages WHERE tenant_id = ? AND whatsapp_account_id = ? AND status = 'failed' ORDER BY id DESC LIMIT 20",
            [$this->app->tenant->id(), $account['id']]
        );
        return $this->app->render('whatsapp/form', [
            'account' => $account, 'provider' => $provider, 'recentErrors' => $errors,
            'webhookUrl' => url('/webhooks/whatsapp/' . $account['webhook_token']),
            'canManage' => $this->app->can('whatsapp.manage'),
            'diagnosis' => $this->app->session->getFlash('diagnosis'),
        ]);
    }

    public function store(Request $request): Response
    {
        $key = $request->str('provider');
        if (!$this->app->providers->has($key)) {
            throw HttpException::notFound();
        }
        $provider = $this->app->providers->get($key);
        $errors = [];
        $data = $this->validateCommon($request, $key, $errors);
        $creds = [];
        foreach ($provider->credentialFields() as $field => [$label, , $required]) {
            $creds[$field] = trim((string) ($request->post['cred_' . $field] ?? ''));
            if ($creds[$field] === '' && $required) {
                $errors['cred_' . $field] = "$label is required.";
            }
        }
        $plan = $this->app->db->first('SELECT p.max_whatsapp_accounts FROM tenants t JOIN subscription_plans p ON p.id = t.plan_id WHERE t.id = ?', [$this->app->tenant->id()]);
        if ($plan !== null && $plan['max_whatsapp_accounts'] !== null && count($this->repo()->allForTenant()) >= (int) $plan['max_whatsapp_accounts']) {
            $errors['name'] = 'Your plan allows ' . (int) $plan['max_whatsapp_accounts'] . ' WhatsApp number(s). Upgrade the plan to add another.';
        }
        if ($errors !== []) {
            return $this->backWithErrors($request, $errors, '/whatsapp/new?provider=' . $key);
        }
        $id = $this->repo()->create($data + [
            'provider' => $key,
            'credentials_enc' => Crypto::encryptArray($creds),
            'webhook_token' => random_token(32),
            'capabilities' => json_encode(array_map(static fn (Capability $c) => $c->value, $provider->capabilities())),
            'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->app->auditLog('whatsapp_account.created', 'whatsapp_account', $id, ['provider' => $key]);
        if ($key === 'meta_cloud') {
            // Meta only forwards incoming messages when the WABA is subscribed to the app; do it right away.
            $this->subscribeQuietly((int) $id);
        }
        $this->flash('success', 'Account saved. Configure the webhook URL below, then press "Check and fix".');
        return Response::redirect('/whatsapp/' . $id);
    }

    public function update(Request $request): Response
    {
        $account = $this->repo()->findOrFail($request->intParam('id'));
        $provider = $this->app->providers->get((string) $account['provider']);
        $errors = [];
        $data = $this->validateCommon($request, (string) $account['provider'], $errors);
        if ($errors !== []) {
            return $this->backWithErrors($request, $errors, '/whatsapp/' . $account['id']);
        }
        if (AccountRepository::isManaged($account)) {
            // Connected with Facebook: credentials are managed; only names/IDs are editable here.
            unset($data['phone_number_id'], $data['business_account_id']);
            $data['updated_at'] = now();
            $this->repo()->update((int) $account['id'], $data);
            $this->app->auditLog('whatsapp_account.updated', 'whatsapp_account', (int) $account['id']);
            $this->flash('success', 'Account updated.');
            return Response::redirect('/whatsapp/' . $account['id']);
        }
        // Blank secret fields keep the stored value (secrets are never echoed back to the browser).
        $creds = AccountRepository::credentials($account);
        $rotated = [];
        foreach (array_keys($provider->credentialFields()) as $field) {
            $value = trim((string) ($request->post['cred_' . $field] ?? ''));
            if ($value !== '') {
                $creds[$field] = $value;
                $rotated[] = $field;
            }
        }
        $data['credentials_enc'] = Crypto::encryptArray($creds);
        $data['updated_at'] = now();
        $this->repo()->update((int) $account['id'], $data);
        $this->app->auditLog('whatsapp_account.updated', 'whatsapp_account', (int) $account['id'], ['rotated_credentials' => $rotated]);
        $this->flash('success', 'Account updated.');
        return Response::redirect('/whatsapp/' . $account['id']);
    }

    private function subscribeQuietly(int $id): void
    {
        $account = $this->app->db->first('SELECT * FROM whatsapp_accounts WHERE id = ?', [$id]);
        $waba = (string) ($account['business_account_id'] ?? '');
        $token = (string) (AccountRepository::credentials((array) $account)['access_token'] ?? '');
        if ($account === null || !preg_match('/^\d+$/', $waba) || $token === '') {
            return;
        }
        try {
            $base = rtrim((string) env('META_GRAPH_URL', 'https://graph.facebook.com'), '/');
            $this->app->http->request('POST', $base . '/' . \App\WhatsApp\MetaPlatform::graphVersion() . '/' . $waba . '/subscribed_apps',
                ['Authorization' => 'Bearer ' . $token], null, 10);
        } catch (\Throwable) {
            // Best effort: "Check and fix" reports and retries this.
        }
    }

    public function rotateWebhookToken(Request $request): Response
    {
        $account = $this->repo()->findOrFail($request->intParam('id'));
        $this->repo()->update((int) $account['id'], ['webhook_token' => random_token(32), 'updated_at' => now()]);
        $this->app->auditLog('whatsapp_account.webhook_rotated', 'whatsapp_account', (int) $account['id']);
        $this->flash('success', 'Webhook URL rotated. Update it in your provider settings.');
        return Response::redirect('/whatsapp/' . $account['id']);
    }

    public function test(Request $request): Response
    {
        $account = $this->repo()->findOrFail($request->intParam('id'));
        $provider = $this->app->providers->get((string) $account['provider']);
        $result = $provider->checkHealth($account, AccountRepository::credentials($account));
        $update = ['status' => $result['ok'] ? 'connected' : 'error', 'updated_at' => now()];
        if ($result['ok']) {
            $update['last_synced_at'] = now();
            if ($account['provider'] === 'meta_cloud' && !AccountRepository::isManaged($account)) {
                $this->subscribeQuietly((int) $account['id']);
            }
        } else {
            $update['last_error'] = mb_substr($result['message'], 0, 500);
            $update['last_error_at'] = now();
        }
        $this->repo()->update((int) $account['id'], $update);
        $this->flash($result['ok'] ? 'success' : 'error', $result['message']);
        return Response::redirect('/whatsapp/' . $account['id']);
    }

    /** "Check and fix": finds why incoming messages don't arrive, and subscribes the WABA to the app if needed. */
    public function diagnose(Request $request): Response
    {
        $account = $this->repo()->findOrFail($request->intParam('id'));
        if ($account['provider'] !== 'meta_cloud') {
            return Response::redirect('/whatsapp/' . $account['id']);
        }
        $url = AccountRepository::isManaged($account) ? url('/webhooks/meta') : url('/webhooks/whatsapp/' . $account['webhook_token']);
        $checks = (new WebhookDoctor($this->app->db, $this->app->http))->diagnose($account, $url, true);
        foreach ($checks as $c) {
            if (!empty($c['fixed'])) {
                $this->app->auditLog('whatsapp_account.webhook_subscribed', 'whatsapp_account', (int) $account['id']);
            }
        }
        $this->app->session->flash('diagnosis', $checks);
        return Response::redirect('/whatsapp/' . $account['id'] . '#receiving');
    }

    public function destroy(Request $request): Response
    {
        $account = $this->repo()->findOrFail($request->intParam('id'));
        if ($request->str('confirm') !== (string) $account['name']) {
            return $this->backWithErrors($request, ['confirm' => 'Type the account name exactly to confirm deletion.'], '/whatsapp/' . $account['id']);
        }
        $this->repo()->delete((int) $account['id']);
        $this->app->auditLog('whatsapp_account.deleted', 'whatsapp_account', (int) $account['id'], ['name' => $account['name']]);
        $this->flash('success', 'Account and its conversations were deleted.');
        return Response::redirect('/whatsapp');
    }

    private function validateCommon(Request $request, string $provider, array &$errors): array
    {
        $v = Validator::make($request->post, [
            'name' => 'required|string|max:120',
            'phone' => 'nullable|string|max:32',
            'phone_number_id' => 'nullable|string|max:64',
            'business_account_id' => 'nullable|string|max:64',
            'base_url' => 'nullable|url|max:255',
        ]);
        $errors = $v->errors();
        $d = $v->validated();
        $phone = null;
        if (!empty($d['phone'])) {
            $phone = PhoneNormalizer::normalize((string) $d['phone']);
            if ($phone === null) {
                $errors['phone'] = 'Invalid phone number.';
            }
        }
        if ($provider === 'meta_cloud') {
            if (empty($d['phone_number_id']) || !ctype_digit((string) $d['phone_number_id'])) {
                $errors['phone_number_id'] = 'Phone number ID (numeric, from Meta WhatsApp Manager) is required.';
            }
            if (!empty($d['business_account_id']) && !ctype_digit((string) $d['business_account_id'])) {
                $errors['business_account_id'] = 'Must be numeric.';
            }
        } elseif (empty($d['base_url'])) {
            $errors['base_url'] = 'Gateway base URL is required.';
        }
        return [
            'name' => $d['name'], 'phone_e164' => $phone,
            'phone_number_id' => $provider === 'meta_cloud' ? $d['phone_number_id'] : null,
            'business_account_id' => $provider === 'meta_cloud' ? $d['business_account_id'] : null,
            'base_url' => $provider === 'meta_cloud' ? null : $d['base_url'],
        ];
    }
}
