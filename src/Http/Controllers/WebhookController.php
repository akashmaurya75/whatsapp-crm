<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\WhatsApp\AccountRepository;
use App\WhatsApp\MetaPlatform;
use App\WhatsApp\Jobs\ProcessWebhookJob;

/**
 * Inbound provider webhooks: /webhooks/whatsapp/{token}
 * The token identifies the account (and therefore the tenant). Signature is verified
 * synchronously against the raw body; processing is queued so we acknowledge quickly.
 */
final class WebhookController extends Controller
{
    private function account(Request $request): ?array
    {
        $token = $request->param('token');
        if (!preg_match('/^[A-Za-z0-9_\-]{43}$/', $token)) {
            return null;
        }
        return $this->app->db->first('SELECT * FROM whatsapp_accounts WHERE webhook_token = ?', [$token]);
    }

    public function verify(Request $request): Response
    {
        $account = $this->account($request);
        if ($account === null || !$this->app->providers->has((string) $account['provider'])) {
            return Response::text('Not found', 404);
        }
        $provider = $this->app->providers->get((string) $account['provider']);
        $challenge = $provider->verificationChallenge(AccountRepository::credentials($account), $request);
        return $challenge !== null ? Response::text($challenge) : Response::text('Forbidden', 403);
    }

    public function receive(Request $request): Response
    {
        $account = $this->account($request);
        if ($account === null || !$this->app->providers->has((string) $account['provider'])) {
            return Response::text('Not found', 404);
        }
        if (strlen($request->body) > 1_048_576) {
            return Response::text('Payload too large', 413);
        }
        $provider = $this->app->providers->get((string) $account['provider']);
        if (!$provider->verifySignature(AccountRepository::credentials($account), $request)) {
            Logger::warning('Webhook signature rejected', ['account_id' => $account['id'], 'ip' => $request->ip()]);
            return Response::text('Invalid signature', 401);
        }
        if (!is_array(json_decode($request->body, true))) {
            return Response::text('Bad request', 400);
        }
        $this->app->queue->push(ProcessWebhookJob::class, ['account_id' => (int) $account['id'], 'payload' => $request->body], 'webhooks');
        return Response::text('OK');
    }

    // ------------------------------------------------------------ platform (app-level) webhook

    /** GET /webhooks/meta — Meta's subscription challenge for the platform app's callback URL. */
    public function metaVerify(Request $request): Response
    {
        $expected = MetaPlatform::config($this->app->db)['verify_token'];
        $mode = (string) ($request->query['hub_mode'] ?? $request->query['hub.mode'] ?? '');
        $token = (string) ($request->query['hub_verify_token'] ?? $request->query['hub.verify_token'] ?? '');
        $challenge = (string) ($request->query['hub_challenge'] ?? $request->query['hub.challenge'] ?? '');
        if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, $token) && preg_match('/^[A-Za-z0-9_\-]{1,200}$/', $challenge)) {
            return Response::text($challenge);
        }
        return Response::text('Forbidden', 403);
    }

    /**
     * POST /webhooks/meta — events for every business connected through the platform's Meta app.
     * Signed with the platform app secret; routed to accounts by WABA id / phone number id.
     */
    public function metaReceive(Request $request): Response
    {
        $secret = MetaPlatform::config($this->app->db)['app_secret'];
        $header = $request->header('x-hub-signature-256');
        if ($secret === '' || !str_starts_with($header, 'sha256=') || !hash_equals('sha256=' . hash_hmac('sha256', $request->body, $secret), $header)) {
            Logger::warning('Platform webhook signature rejected', ['ip' => $request->ip()]);
            return Response::text('Invalid signature', 401);
        }
        if (strlen($request->body) > 1_048_576) {
            return Response::text('Payload too large', 413);
        }
        $payload = json_decode($request->body, true);
        if (!is_array($payload)) {
            return Response::text('Bad request', 400);
        }
        $wabas = [];
        $phones = [];
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            if (isset($entry['id']) && preg_match('/^\d+$/', (string) $entry['id'])) {
                $wabas[] = (string) $entry['id'];
            }
            foreach ((array) ($entry['changes'] ?? []) as $ch) {
                $pn = (string) ($ch['value']['metadata']['phone_number_id'] ?? '');
                if (preg_match('/^\d+$/', $pn)) {
                    $phones[] = $pn;
                }
            }
        }
        $wabas = array_values(array_unique($wabas));
        $phones = array_values(array_unique($phones));
        if ($wabas === [] && $phones === []) {
            return Response::text('OK'); // e.g. account_update events: nothing to route
        }
        $in = static fn (array $v) => implode(',', array_fill(0, max(1, count($v)), '?'));
        $accounts = $this->app->db->all(
            "SELECT a.id FROM whatsapp_accounts a JOIN tenants t ON t.id = a.tenant_id
             WHERE a.provider = 'meta_cloud' AND t.status = 'active' AND (a.phone_number_id IN (" . $in($phones) . ') OR a.business_account_id IN (' . $in($wabas) . '))',
            array_merge($phones ?: [''], $wabas ?: [''])
        );
        foreach ($accounts as $a) {
            // Each account's parser keeps only events for its own phone number / WABA.
            $this->app->queue->push(ProcessWebhookJob::class, ['account_id' => (int) $a['id'], 'payload' => $request->body], 'webhooks');
        }
        return Response::text('OK');
    }
}
