<?php

declare(strict_types=1);

namespace App\WhatsApp;

use App\Core\BackgroundRunner;
use App\Core\Database;
use App\Core\HttpClient;
use Throwable;

/**
 * "Why aren't incoming messages arriving?" — checks every link in the chain for a Meta Cloud API account:
 * credentials → phone number belongs to the WABA → WABA subscribed to the app (the #1 cause) →
 * app webhook callback URL + "messages" field → deliveries reaching this server → background processing.
 * With $fix = true it subscribes the WABA to the app when that subscription is missing.
 */
final class WebhookDoctor
{
    public const OK = 'ok';
    public const FAIL = 'fail';
    public const WARN = 'warn';

    public function __construct(private Database $db, private HttpClient $http)
    {
    }

    private function url(string $path, array $query = []): string
    {
        $base = rtrim((string) env('META_GRAPH_URL', 'https://graph.facebook.com'), '/');
        return $base . '/' . MetaPlatform::graphVersion() . '/' . ltrim($path, '/') . ($query !== [] ? '?' . http_build_query($query) : '');
    }

    /** @return array{0:int,1:array} */
    private function call(string $method, string $url, string $token): array
    {
        try {
            $r = $this->http->request($method, $url, $token !== '' ? ['Authorization' => 'Bearer ' . $token] : []);
        } catch (Throwable $e) {
            return [0, ['error' => ['message' => 'Could not reach Meta: ' . $e->getMessage()]]];
        }
        return [$r['status'], json_decode($r['body'], true) ?: []];
    }

    private static function err(array $json, int $status): string
    {
        return mb_substr((string) ($json['error']['error_user_msg'] ?? $json['error']['message'] ?? 'HTTP ' . $status), 0, 300);
    }

    /**
     * @return list<array{label:string,state:string,detail:string,fixed?:bool}>
     */
    public function diagnose(array $account, string $expectedUrl, bool $fix = false): array
    {
        $checks = [];
        $add = static function (string $label, string $state, string $detail, bool $fixed = false) use (&$checks): void {
            $checks[] = ['label' => $label, 'state' => $state, 'detail' => $detail, 'fixed' => $fixed];
        };
        $creds = AccountRepository::credentials($account);
        $token = (string) ($creds['access_token'] ?? '');
        $pnid = (string) ($account['phone_number_id'] ?? '');
        $waba = (string) ($account['business_account_id'] ?? '');
        $appId = (string) ($creds['app_id'] ?? '');
        $secret = (string) ($creds['app_secret'] ?? '');

        // 1. This server is reachable over https (Meta refuses http callback URLs).
        $https = str_starts_with(strtolower($expectedUrl), 'https://');
        $add('Webhook address uses https', $https ? self::OK : self::FAIL, $https ? $expectedUrl
            : 'Meta only delivers to https addresses. Install an SSL certificate and set APP_URL to https://… in the .env file. Current: ' . $expectedUrl);

        // 2. Token + phone number ID.
        if ($token === '' || !preg_match('/^\d+$/', $pnid)) {
            $add('Access token and Phone number ID', self::FAIL, 'Both are required on this account.');
            return $this->localChecks($account, $checks);
        }
        [$st, $pn] = $this->call('GET', $this->url($pnid, ['fields' => 'display_phone_number,verified_name,code_verification_status,platform_type,status']), $token);
        if ($st === 0) {
            $add('This server can reach Meta', self::FAIL, self::err($pn, $st)
                . ' — your hosting may block outgoing connections to graph.facebook.com. Ask your host to allow outgoing HTTPS (port 443).');
            return $this->localChecks($account, $checks);
        }
        if ($st !== 200 || !isset($pn['display_phone_number'])) {
            $add('Phone number ID and access token work', self::FAIL, 'Meta says: ' . self::err($pn, $st)
                . ' — check the Phone number ID (not the WhatsApp Business Account ID) and that the token is the permanent System User token.');
            return $this->localChecks($account, $checks);
        }
        $add('Phone number ID and access token work', self::OK, trim(($pn['verified_name'] ?? '') . ' ' . $pn['display_phone_number']));
        $platform = strtoupper((string) ($pn['platform_type'] ?? ''));
        if ($platform !== '' && $platform !== 'CLOUD_API') {
            $add('Number is registered on the Cloud API', self::FAIL, 'Meta reports platform "' . $platform
                . '". Register the number for the Cloud API (WhatsApp Manager → Phone numbers), otherwise it receives nothing.');
        }

        // 3. WABA: number belongs to it, and the app is subscribed to it.
        if (!preg_match('/^\d+$/', $waba)) {
            $add('WhatsApp Business Account ID', self::FAIL, 'Add the WhatsApp Business Account ID to this account (Meta app → WhatsApp → API Setup). It is needed to check and fix the webhook subscription.');
            return $this->localChecks($account, $checks);
        }
        [$st, $nums] = $this->call('GET', $this->url($waba . '/phone_numbers', ['fields' => 'id,display_phone_number', 'limit' => 100]), $token);
        if ($st === 200) {
            $ids = array_map(static fn ($n) => (string) ($n['id'] ?? ''), (array) ($nums['data'] ?? []));
            $add('Phone number belongs to this WhatsApp Business Account', in_array($pnid, $ids, true) ? self::OK : self::FAIL,
                in_array($pnid, $ids, true) ? 'WABA ' . $waba : 'This WABA does not contain phone number ID ' . $pnid . '. Check both IDs on Meta → WhatsApp → API Setup.');
        } else {
            $add('WhatsApp Business Account ID is correct', self::FAIL, 'Meta says: ' . self::err($nums, $st));
            return $this->localChecks($account, $checks);
        }

        [$st, $subs] = $this->call('GET', $this->url($waba . '/subscribed_apps'), $token);
        $apps = [];
        foreach ((array) ($subs['data'] ?? []) as $row) {
            $d = (array) ($row['whatsapp_business_api_data'] ?? $row);
            if (isset($d['id'])) {
                $apps[(string) $d['id']] = (string) ($d['name'] ?? $d['id']);
            }
        }
        $subscribed = $st === 200 && ($appId === '' ? $apps !== [] : isset($apps[$appId]));
        $fixed = false;
        if (!$subscribed && $fix) {
            // Subscribes the app that owns the token (the one whose webhook settings you configured).
            [$pst, $pres] = $this->call('POST', $this->url($waba . '/subscribed_apps'), $token);
            $fixed = $pst === 200 && !empty($pres['success']);
            if (!$fixed) {
                $add('Subscribe your app to the WhatsApp Business Account', self::FAIL, 'Tried to fix it, but Meta says: ' . self::err($pres, $pst));
            }
        }
        if ($subscribed || $fixed) {
            $add('Your Meta app is subscribed to the WhatsApp Business Account', self::OK,
                $fixed ? 'It was NOT subscribed — fixed now. Send a test message from your phone.' : 'Subscribed apps: ' . implode(', ', $apps), $fixed);
        } elseif (!$fix) {
            $add('Your Meta app is subscribed to the WhatsApp Business Account', self::FAIL,
                ($apps === [] ? 'No app is subscribed, so Meta does not send incoming messages anywhere.'
                    : 'Subscribed: ' . implode(', ', $apps) . ' — but not your app' . ($appId !== '' ? ' (' . $appId . ')' : '') . '.')
                . ' This is the most common cause. Press "Check and fix" to subscribe it.');
        }

        // 4. App-level webhook configuration (needs App ID + App Secret).
        if (!preg_match('/^\d+$/', $appId) || $secret === '') {
            $add('Webhook URL and "messages" field in your Meta app', self::WARN,
                'Add the Meta App ID on this account (Meta app → App settings → Basic) so this can be checked automatically. Meanwhile check by hand: WhatsApp → Configuration → Callback URL = ' . $expectedUrl . ', and "messages" is Subscribed.');
        } else {
            [$st, $conf] = $this->call('GET', $this->url($appId . '/subscriptions', ['access_token' => $appId . '|' . $secret]), '');
            if ($st !== 200) {
                $add('App ID and App Secret match', self::FAIL, 'Meta rejected App ID + App Secret: ' . self::err($conf, $st)
                    . '. A wrong App Secret makes every incoming webhook fail the signature check. Copy it again from App settings → Basic.');
            } else {
                $add('App ID and App Secret match', self::OK, 'App ' . $appId);
                $wa = null;
                foreach ((array) ($conf['data'] ?? []) as $row) {
                    if (($row['object'] ?? '') === 'whatsapp_business_account') {
                        $wa = $row;
                    }
                }
                if ($wa === null) {
                    $add('Webhook configured in your Meta app', self::FAIL, 'No WhatsApp webhook is set. Meta app → WhatsApp → Configuration → Webhook → Edit: Callback URL ' . $expectedUrl . ' and your verify token.');
                } else {
                    $cb = (string) ($wa['callback_url'] ?? '');
                    $platformUrl = url('/webhooks/meta');
                    $sameUrl = rtrim($cb, '/') === rtrim($expectedUrl, '/') || rtrim($cb, '/') === rtrim($platformUrl, '/');
                    $add('Callback URL points to this account', $sameUrl ? self::OK : self::FAIL, $sameUrl ? $cb
                        : 'Meta sends to ' . ($cb ?: '(empty)') . ' but this account listens on ' . $expectedUrl . '. Update the Callback URL in the Meta app (WhatsApp → Configuration).');
                    $fields = array_map(static fn ($f) => is_array($f) ? (string) ($f['name'] ?? '') : (string) $f, (array) ($wa['fields'] ?? []));
                    $add('"messages" webhook field subscribed', in_array('messages', $fields, true) ? self::OK : self::FAIL,
                        in_array('messages', $fields, true) ? implode(', ', array_filter($fields))
                            : 'In the Meta app → WhatsApp → Configuration → Webhook fields, click Subscribe next to "messages".');
                    if (array_key_exists('active', $wa) && !$wa['active']) {
                        $add('Webhook is active', self::FAIL, 'Meta marked the webhook inactive (usually after many failed deliveries). Re-verify it in WhatsApp → Configuration.');
                    }
                }
            }
        }
        $add('Tip: app mode', self::WARN,
            'If your Meta app is in Development mode, messages only arrive from numbers added under WhatsApp → API Setup → "To". Switch it to Live (top of the app dashboard) to receive from everyone.');

        return $this->localChecks($account, $checks);
    }

    /** Checks on this server: deliveries arriving, rejected signatures, background processing. */
    private function localChecks(array $account, array $checks): array
    {
        $at = $account['last_webhook_at'] ?? null;
        $result = (string) ($account['last_webhook_result'] ?? '');
        if ($at === null) {
            $checks[] = ['label' => 'Meta has delivered to this server', 'state' => self::FAIL, 'fixed' => false,
                'detail' => 'No webhook has ever reached this account. Fix the items above, then send "Hi" from your phone to the business number.'];
        } else {
            $state = str_starts_with($result, 'received') ? self::OK : self::FAIL;
            $checks[] = ['label' => 'Last delivery from Meta', 'state' => $state, 'fixed' => false,
                'detail' => time_ago((string) $at) . ': ' . $result];
        }
        $pending = (int) $this->db->scalar("SELECT COUNT(*) FROM jobs WHERE queue = 'webhooks' AND available_at <= ?", [time() - 120]);
        $last = BackgroundRunner::lastRun();
        $hb = is_file(base_path('storage/worker.heartbeat')) ? (int) file_get_contents(base_path('storage/worker.heartbeat')) : 0;
        $running = time() - max($hb, $last['at'] ?? 0) < 180;
        $checks[] = ['label' => 'Background processing (cron / worker)', 'state' => $running && $pending === 0 ? self::OK : self::FAIL, 'fixed' => false,
            'detail' => $running ? ($pending > 0 ? "$pending received webhook(s) waiting more than 2 minutes." : 'Running.')
                : 'Not running: received messages wait in the queue. Add the cron job (php …/bin/console cron, every minute) — see Admin → System health.'];
        return $checks;
    }
}
