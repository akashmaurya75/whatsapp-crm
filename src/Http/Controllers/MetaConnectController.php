<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Crypto;
use App\Core\Request;
use App\Core\Response;
use App\Domain\ValidationException;
use App\WhatsApp\EmbeddedSignupService;
use App\WhatsApp\MetaPlatform;

/** "Continue with Facebook": connect a WhatsApp number in one click (Embedded Signup). */
final class MetaConnectController extends Controller
{
    private const CHOICE_TTL = 600;

    private function svc(): EmbeddedSignupService
    {
        return new EmbeddedSignupService($this->app->db, $this->app->tenant, $this->app->http, $this->app->providers);
    }

    /** Only this page may load Meta's SDK and open its popup. */
    private function withMetaCsp(Response $r): Response
    {
        return $r->withHeader('Content-Security-Policy',
            "default-src 'self'; script-src 'self' https://connect.facebook.net; "
            . "frame-src https://www.facebook.com https://web.facebook.com https://*.facebook.com; "
            . "connect-src 'self' https://*.facebook.com https://graph.facebook.com; img-src 'self' data: https:; "
            . "style-src 'self' 'unsafe-inline'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'")
            // The Facebook login popup must be able to talk back to this window.
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin-allow-popups');
    }

    public function show(Request $request): Response
    {
        $cfg = MetaPlatform::config($this->app->db);
        $choice = $this->pendingChoice();
        $https = $request->isSecure() || in_array(parse_url((string) env('APP_URL', ''), PHP_URL_HOST), ['localhost', '127.0.0.1'], true);
        return $this->withMetaCsp($this->app->render('whatsapp/connect', [
            'ready' => MetaPlatform::signupReady($this->app->db), 'appId' => $cfg['app_id'], 'configId' => $cfg['config_id'],
            'version' => MetaPlatform::graphVersion(), 'https' => $https, 'choice' => $choice,
            'isSuperAdmin' => false,
        ]));
    }

    /** JSON endpoint called by the page right after the Facebook popup closes. */
    public function start(Request $request): Response
    {
        $in = $request->json() ?: $request->post;
        try {
            $r = $this->svc()->start(
                (string) ($in['code'] ?? ''), (string) ($in['waba_id'] ?? ''), (string) ($in['phone_number_id'] ?? ''),
                (string) ($in['pin'] ?? ''), $this->app->userId()
            );
        } catch (ValidationException $e) {
            $this->app->auditLog('whatsapp_account.signup_failed', null, null, ['reason' => $e->getMessage()]);
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
        if ($r['status'] === 'choose') {
            $this->app->session->put('meta_choice', Crypto::encryptArray([
                'token' => $r['token'], 'options' => $r['options'], 'pin' => (string) ($in['pin'] ?? ''), 'at' => time(),
            ]));
            return Response::json(['ok' => true, 'status' => 'choose', 'redirect' => '/whatsapp/connect']);
        }
        return Response::json(['ok' => true, 'status' => 'connected', 'redirect' => $this->connected($r)]);
    }

    /** Form post: the user picked one of several numbers. */
    public function choose(Request $request): Response
    {
        $choice = $this->pendingChoice(true);
        if ($choice === null) {
            $this->flash('error', 'That selection expired. Please click "Continue with Facebook" again.');
            return Response::redirect('/whatsapp/connect');
        }
        $picked = null;
        foreach ($choice['options'] as $o) {
            if ($o['id'] === $request->str('phone_number_id')) {
                $picked = $o;
            }
        }
        if ($picked === null) {
            return $this->backWithErrors($request, ['phone_number_id' => 'Choose one of the listed numbers.'], '/whatsapp/connect');
        }
        $this->app->session->forget('meta_choice');
        try {
            $r = $this->svc()->finish((string) $choice['token'], $picked, (string) $choice['pin'], $this->app->userId());
        } catch (ValidationException $e) {
            $this->flash('error', $e->getMessage());
            return Response::redirect('/whatsapp/connect');
        }
        return Response::redirect($this->connected($r));
    }

    public function cancelChoice(Request $request): Response
    {
        $this->app->session->forget('meta_choice');
        return Response::redirect('/whatsapp/connect');
    }

    private function connected(array $r): string
    {
        $this->app->auditLog($r['created'] ? 'whatsapp_account.connected_facebook' : 'whatsapp_account.reconnected_facebook', 'whatsapp_account', $r['account_id']);
        $this->flash($r['warnings'] ? 'error' : 'success', 'WhatsApp number connected.' . ($r['warnings'] ? ' Note: ' . implode(' ', $r['warnings']) : ' Messages and template approvals now flow in automatically.'));
        return '/whatsapp/' . $r['account_id'];
    }

    private function pendingChoice(bool $withToken = false): ?array
    {
        $enc = $this->app->session->get('meta_choice');
        if (!is_string($enc)) {
            return null;
        }
        try {
            $c = Crypto::decryptArray($enc);
        } catch (\Throwable) {
            return null;
        }
        if (time() - (int) ($c['at'] ?? 0) > self::CHOICE_TTL) {
            $this->app->session->forget('meta_choice');
            return null;
        }
        if (!$withToken) {
            unset($c['token']);
        }
        return $c;
    }
}
