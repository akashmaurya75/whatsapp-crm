<?php

declare(strict_types=1);

namespace App\WhatsApp;

use App\Core\Crypto;
use App\Core\Database;
use App\Core\HttpClient;
use App\Core\Logger;
use App\Domain\Contacts\PhoneNormalizer;
use App\Domain\Templates\TemplateService;
use App\Domain\ValidationException;
use App\Tenancy\TenantContext;
use Throwable;

/**
 * "Continue with Facebook" onboarding (WhatsApp Embedded Signup, Tech Provider flow):
 *   1. exchange the 30-second code for a business integration token
 *   2. find / verify the WABA and phone number the user picked
 *   3. subscribe the platform app to the WABA's webhooks
 *   4. register the phone number for Cloud API (two-step PIN)
 *   5. create or update the WhatsApp account, then import its templates
 * All calls are server-to-server; the token is stored encrypted and never sent to the browser.
 */
final class EmbeddedSignupService
{
    public function __construct(
        private Database $db,
        private TenantContext $tenant,
        private HttpClient $http,
        private ?ProviderFactory $providers = null,
    ) {
    }

    private function url(string $path, array $query = []): string
    {
        $base = rtrim((string) env('META_GRAPH_URL', 'https://graph.facebook.com'), '/');
        return $base . '/' . MetaPlatform::graphVersion() . '/' . ltrim($path, '/') . ($query ? '?' . http_build_query($query) : '');
    }

    /** @return array{0:int,1:array} */
    private function call(string $method, string $url, ?string $token, ?array $json = null): array
    {
        $headers = $token !== null ? ['Authorization' => 'Bearer ' . $token] : [];
        if ($json !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        try {
            $r = $this->http->request($method, $url, $headers, $json !== null ? (string) json_encode($json) : null);
        } catch (Throwable $e) {
            throw new ValidationException(['meta' => 'Could not reach Meta. Check the server\'s internet connection and try again.']);
        }
        return [$r['status'], json_decode($r['body'], true) ?: []];
    }

    private static function err(array $json, string $fallback): string
    {
        $e = $json['error'] ?? [];
        return mb_substr((string) ($e['error_user_msg'] ?? $e['message'] ?? $fallback), 0, 300);
    }

    /** Step 1: code → business token. */
    public function exchangeCode(string $code): string
    {
        $cfg = MetaPlatform::config($this->db);
        if (!MetaPlatform::signupReady($this->db)) {
            throw new ValidationException(['meta' => 'Facebook sign-in is not configured yet. Ask the platform admin to add the Meta app (Admin → Meta app).']);
        }
        if ($code === '' || strlen($code) > 2048 || preg_match('/\s/', $code)) {
            throw new ValidationException(['meta' => 'Facebook did not return a valid authorisation code. Please try again.']);
        }
        [$status, $json] = $this->call('GET', $this->url('oauth/access_token', [
            'client_id' => $cfg['app_id'], 'client_secret' => $cfg['app_secret'], 'code' => $code,
        ]), null);
        $token = (string) ($json['access_token'] ?? '');
        if ($status !== 200 || $token === '') {
            throw new ValidationException(['meta' => 'Facebook sign-in expired or was rejected (' . self::err($json, 'HTTP ' . $status) . '). The code is only valid for 30 seconds; please click "Continue with Facebook" again.']);
        }
        return $token;
    }

    /** WABA ids the token was granted (used when the popup didn't report them). @return list<string> */
    public function grantedWabas(string $token): array
    {
        $cfg = MetaPlatform::config($this->db);
        [$status, $json] = $this->call('GET', $this->url('debug_token', [
            'input_token' => $token, 'access_token' => $cfg['app_id'] . '|' . $cfg['app_secret'],
        ]), null);
        if ($status !== 200) {
            return [];
        }
        $ids = [];
        foreach ((array) ($json['data']['granular_scopes'] ?? []) as $s) {
            if (in_array($s['scope'] ?? '', ['whatsapp_business_management', 'whatsapp_business_messaging'], true)) {
                foreach ((array) ($s['target_ids'] ?? []) as $id) {
                    if (preg_match('/^\d+$/', (string) $id)) {
                        $ids[] = (string) $id;
                    }
                }
            }
        }
        return array_values(array_unique($ids));
    }

    /** @return list<array{id:string,display_phone_number:string,verified_name:string,quality_rating:?string,waba_id:string}> */
    public function phoneNumbers(string $wabaId, string $token): array
    {
        [$status, $json] = $this->call('GET', $this->url($wabaId . '/phone_numbers', [
            'fields' => 'id,display_phone_number,verified_name,quality_rating,code_verification_status',
        ]), $token);
        if ($status !== 200) {
            return [];
        }
        $out = [];
        foreach ((array) ($json['data'] ?? []) as $p) {
            if (preg_match('/^\d+$/', (string) ($p['id'] ?? ''))) {
                $out[] = ['id' => (string) $p['id'], 'display_phone_number' => (string) ($p['display_phone_number'] ?? ''),
                    'verified_name' => (string) ($p['verified_name'] ?? ''), 'quality_rating' => isset($p['quality_rating']) ? (string) $p['quality_rating'] : null,
                    'waba_id' => $wabaId];
            }
        }
        return $out;
    }

    /**
     * Steps 1–2. Returns either a connected account or a list of numbers to choose from.
     * @return array{status:'connected',account_id:int,warnings:list<string>}|array{status:'choose',token:string,options:list<array>}
     */
    public function start(string $code, string $wabaId = '', string $phoneId = '', string $pin = '', ?int $userId = null): array
    {
        $wabaId = preg_match('/^\d+$/', $wabaId) ? $wabaId : '';
        $phoneId = preg_match('/^\d+$/', $phoneId) ? $phoneId : '';
        $token = $this->exchangeCode($code);
        $wabas = $wabaId !== '' ? [$wabaId] : $this->grantedWabas($token);
        if ($wabas === []) {
            throw new ValidationException(['meta' => 'No WhatsApp Business Account was shared. Run the Facebook sign-in again and select or create a WhatsApp Business account.']);
        }
        $options = [];
        foreach ($wabas as $w) {
            foreach ($this->phoneNumbers($w, $token) as $p) {
                $options[] = $p;
            }
        }
        if ($phoneId !== '') {
            foreach ($options as $o) {
                if ($o['id'] === $phoneId) {
                    return $this->finish($token, $o, $pin, $userId);
                }
            }
            throw new ValidationException(['meta' => 'That phone number does not belong to the WhatsApp Business account you shared.']);
        }
        if ($options === []) {
            throw new ValidationException(['meta' => 'Your WhatsApp Business account has no phone number yet. Add one in the Facebook sign-in (or in WhatsApp Manager) and connect again.']);
        }
        if (count($options) === 1) {
            return $this->finish($token, $options[0], $pin, $userId);
        }
        return ['status' => 'choose', 'token' => $token, 'options' => $options];
    }

    /** Steps 3–5 for one verified phone number. */
    public function finish(string $token, array $phone, string $pin = '', ?int $userId = null): array
    {
        $tid = $this->tenant->id();
        $phoneId = (string) $phone['id'];
        $wabaId = (string) $phone['waba_id'];
        $warnings = [];

        // A number can belong to only one company on this platform.
        $owner = $this->db->first("SELECT id, tenant_id FROM whatsapp_accounts WHERE phone_number_id = ? AND provider = 'meta_cloud'", [$phoneId]);
        if ($owner !== null && (int) $owner['tenant_id'] !== $tid) {
            throw new ValidationException(['meta' => 'This WhatsApp number is already connected to another company on this platform.']);
        }
        if ($owner === null) {
            $plan = $this->db->first('SELECT p.max_whatsapp_accounts FROM tenants t JOIN subscription_plans p ON p.id = t.plan_id WHERE t.id = ?', [$tid]);
            if ($plan !== null && $plan['max_whatsapp_accounts'] !== null
                && (int) $this->db->scalar('SELECT COUNT(*) FROM whatsapp_accounts WHERE tenant_id = ?', [$tid]) >= (int) $plan['max_whatsapp_accounts']) {
                throw new ValidationException(['meta' => 'Your plan allows ' . (int) $plan['max_whatsapp_accounts'] . ' WhatsApp number(s). Upgrade the plan to connect another.']);
            }
        }

        // Step 3: receive this WABA's webhooks through the platform app.
        [$s, $j] = $this->call('POST', $this->url($wabaId . '/subscribed_apps'), $token);
        if ($s !== 200 || empty($j['success'])) {
            throw new ValidationException(['meta' => 'Could not subscribe to the account\'s messages: ' . self::err($j, 'HTTP ' . $s)]);
        }

        // Step 4: register the number for Cloud API with a two-step verification PIN.
        $pin = preg_match('/^\d{6}$/', $pin) ? $pin : str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        [$s, $j] = $this->call('POST', $this->url($phoneId . '/register'), $token, ['messaging_product' => 'whatsapp', 'pin' => $pin]);
        $registered = $s === 200 && !empty($j['success']);
        if (!$registered) {
            $warnings[] = 'Meta did not confirm the number registration (' . self::err($j, 'HTTP ' . $s) . '). If it already had a two-step PIN, reconnect and enter that PIN.';
        }

        // Step 5: create or update the account.
        $e164 = PhoneNormalizer::normalize('+' . preg_replace('/\D+/', '', (string) $phone['display_phone_number']), '');
        $name = trim((string) $phone['verified_name']) ?: ('WhatsApp ' . $phone['display_phone_number']);
        $existingCreds = $owner !== null ? AccountRepository::credentials((array) $this->db->first('SELECT * FROM whatsapp_accounts WHERE id = ?', [$owner['id']])) : [];
        $creds = [
            'managed' => true, 'access_token' => $token,
            'verify_token' => (string) ($existingCreds['verify_token'] ?? random_token(24)),
            'pin' => $registered ? $pin : (string) ($existingCreds['pin'] ?? ''),
        ];
        $provider = $this->providers?->get('meta_cloud') ?? new MetaCloudProvider($this->http);
        $row = [
            'name' => mb_substr($name, 0, 120), 'phone_e164' => $e164, 'phone_number_id' => $phoneId, 'business_account_id' => $wabaId,
            'credentials_enc' => Crypto::encryptArray($creds),
            'capabilities' => json_encode(array_map(static fn (Capability $c) => $c->value, $provider->capabilities())),
            'status' => 'connected', 'token_status' => null, 'token_expires_at' => null, 'token_checked_at' => null, 'reconnect_notified_at' => null,
            'last_synced_at' => now(), 'last_error' => $warnings[0] ?? null,
            'last_error_at' => $warnings ? now() : null, 'updated_at' => now(),
        ];
        $repo = new AccountRepository($this->db, $this->tenant);
        if ($owner !== null) {
            $accountId = (int) $owner['id'];
            $repo->update($accountId, $row);
        } else {
            $accountId = $repo->create($row + ['provider' => 'meta_cloud', 'webhook_token' => random_token(32), 'created_at' => now()]);
        }

        // Import existing templates (best effort).
        try {
            (new TemplateService($this->db, $this->tenant, $this->providers ?? new ProviderFactory($this->http)))->sync($accountId);
        } catch (Throwable $e) {
            $warnings[] = 'Templates could not be imported yet; use "Sync from Meta" on the Templates page.';
            Logger::warning('Template sync after signup failed', ['account_id' => $accountId, 'error' => $e->getMessage()]);
        }

        // Record the token's real lifetime (best effort; the daily check fills it in otherwise).
        try {
            (new TokenMonitor($this->db, $this->http))->check((array) $this->db->first('SELECT * FROM whatsapp_accounts WHERE id = ?', [$accountId]));
            $expires = $this->db->scalar('SELECT token_expires_at FROM whatsapp_accounts WHERE id = ?', [$accountId]);
            if ($expires !== null) {
                $warnings[] = 'Facebook access for this number expires on ' . substr((string) $expires, 0, 10) . ' UTC; you will need to reconnect before then. '
                    . 'The platform admin can avoid this by setting the token expiration to "Never" in the Meta Embedded Signup configuration.';
            }
        } catch (Throwable $e) {
            Logger::warning('Token check after signup failed', ['account_id' => $accountId, 'error' => $e->getMessage()]);
        }
        return ['status' => 'connected', 'account_id' => $accountId, 'warnings' => $warnings, 'created' => $owner === null];
    }
}
