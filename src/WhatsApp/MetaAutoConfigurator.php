<?php

declare(strict_types=1);

namespace App\WhatsApp;

use App\Core\Database;
use App\Core\HttpClient;
use Throwable;

/**
 * "Save & auto-configure" for the platform Meta app. With only the App ID + App Secret it:
 *   1. checks the pair with Meta (app access token)
 *   2. registers this server's webhook (callback URL + verify token) through the app subscriptions API,
 *      so nobody has to copy the verify token into Meta by hand — Meta calls /webhooks/meta to verify it
 *   3. subscribes the WhatsApp fields the CRM needs and confirms Meta stored them
 *   4. reports what only a person can do in the Meta dashboard (domains, Embedded Signup configuration)
 * Meta offers no API for the App Secret or the configuration ID, so those stay manual.
 */
final class MetaAutoConfigurator
{
    public const OK = 'ok';
    public const FAIL = 'fail';
    public const WARN = 'warn';
    public const FIELDS = ['messages', 'message_template_status_update', 'account_update'];

    public function __construct(private Database $db, private HttpClient $http)
    {
    }

    private function url(string $path, array $query = []): string
    {
        $base = rtrim((string) env('META_GRAPH_URL', 'https://graph.facebook.com'), '/');
        return $base . '/' . MetaPlatform::graphVersion() . '/' . ltrim($path, '/') . ($query !== [] ? '?' . http_build_query($query) : '');
    }

    /** @return array{0:int,1:array} */
    private function call(string $method, string $url, ?string $form = null): array
    {
        try {
            $r = $this->http->request($method, $url, $form !== null ? ['Content-Type' => 'application/x-www-form-urlencoded'] : [], $form, 30);
        } catch (Throwable $e) {
            return [0, ['error' => ['message' => 'Could not reach Meta: ' . $e->getMessage()]]];
        }
        return [$r['status'], json_decode($r['body'], true) ?: []];
    }

    private static function err(array $json, int $status): string
    {
        return mb_substr((string) ($json['error']['error_user_msg'] ?? $json['error']['message'] ?? 'HTTP ' . $status), 0, 300);
    }

    /** @return list<array{label:string,state:string,detail:string,link?:string}> */
    public function run(string $callbackUrl): array
    {
        $checks = [];
        $add = static function (string $label, string $state, string $detail, ?string $link = null) use (&$checks): void {
            $checks[] = ['label' => $label, 'state' => $state, 'detail' => $detail] + ($link !== null ? ['link' => $link] : []);
        };
        $cfg = MetaPlatform::config($this->db);
        if ($cfg['app_id'] === '' || $cfg['app_secret'] === '') {
            $add('App ID and App Secret', self::FAIL, 'Enter both (App settings → Basic) and press Save & auto-configure again.');
            return $checks;
        }
        $appId = $cfg['app_id'];
        $appToken = $appId . '|' . $cfg['app_secret'];
        $dashboard = 'https://developers.facebook.com/apps/' . rawurlencode($appId);

        // 1. Credentials.
        [$s, $app] = $this->call('GET', $this->url($appId, ['fields' => 'name,app_domains', 'access_token' => $appToken]));
        if ($s !== 200 || !isset($app['name'])) {
            $add('App ID and App Secret', self::FAIL, 'Meta rejected them: ' . self::err($app, $s) . '. Copy both again from App settings → Basic.', $dashboard . '/settings/basic/');
            return $checks;
        }
        $add('App ID and App Secret', self::OK, 'Meta accepted them for app "' . $app['name'] . '".');

        // 2. Meta only delivers to (and verifies) a public https address.
        $host = (string) parse_url($callbackUrl, PHP_URL_HOST);
        if (!str_starts_with($callbackUrl, 'https://') || in_array($host, ['', 'localhost', '127.0.0.1'], true)) {
            $add('Public https address', self::FAIL, 'APP_URL in .env must be this site\'s public https:// address (it is "' . $callbackUrl . '").');
            return $checks;
        }

        // 3. Register the webhook. Meta immediately calls GET /webhooks/meta with our verify token.
        $verify = MetaPlatform::ensureVerifyToken($this->db);
        [$s, $j] = $this->call('POST', $this->url($appId . '/subscriptions'), http_build_query([
            'object' => 'whatsapp_business_account', 'callback_url' => $callbackUrl, 'verify_token' => $verify,
            'fields' => implode(',', self::FIELDS), 'include_values' => 'true', 'access_token' => $appToken,
        ]));
        if ($s !== 200 || empty($j['success'])) {
            $add('Webhook registered with Meta', self::FAIL, 'Meta could not verify ' . $callbackUrl . ': ' . self::err($j, $s)
                . '. Check that the address opens from the internet (no maintenance page, password protection or firewall blocking Meta).', $dashboard . '/');
            return $checks;
        }
        $add('Webhook registered with Meta', self::OK, 'Meta verified ' . $callbackUrl . ' with this server\'s verify token. Nothing to copy by hand.');

        // 4. Confirm what Meta stored.
        [$s, $j] = $this->call('GET', $this->url($appId . '/subscriptions', ['access_token' => $appToken]));
        $sub = null;
        foreach ((array) ($j['data'] ?? []) as $row) {
            if (($row['object'] ?? '') === 'whatsapp_business_account') {
                $sub = $row;
            }
        }
        if ($sub === null) {
            $add('Webhook fields', self::WARN, 'Registered, but Meta did not list the subscription back (' . self::err($j, $s) . '). Press the button again in a minute.');
        } else {
            $have = array_map(static fn ($f) => is_array($f) ? (string) ($f['name'] ?? '') : (string) $f, (array) ($sub['fields'] ?? []));
            $missing = array_values(array_diff(self::FIELDS, $have));
            $add('Webhook fields', $missing === [] && !empty($sub['active']) ? self::OK : self::WARN, $missing === []
                ? 'Subscribed to ' . implode(', ', self::FIELDS) . '.'
                : 'Not subscribed yet: ' . implode(', ', $missing) . '. Tick them under WhatsApp → Configuration → Webhook fields.', $missing === [] ? null : $dashboard . '/');
        }

        // 5. Things Meta only lets a person change in the dashboard: check what we can, link the rest.
        $domains = $app['app_domains'] ?? null;
        if (is_array($domains)) {
            $listed = in_array(strtolower($host), array_map('strtolower', array_map('strval', $domains)), true);
            $add('App domain', $listed ? self::OK : self::WARN, $listed ? $host . ' is listed under App domains.'
                : 'Add ' . $host . ' under App settings → Basic → App domains, then save.', $listed ? null : $dashboard . '/settings/basic/');
        } else {
            $add('App domain', self::WARN, 'Meta did not report the app domains. Make sure ' . $host . ' is listed under App settings → Basic → App domains.', $dashboard . '/settings/basic/');
        }
        $add('JavaScript SDK domain', self::WARN, 'Meta has no API for this one: under Facebook Login for Business → Settings, turn on "Login with the JavaScript SDK" and add https://' . $host . ' to Allowed domains.', $dashboard . '/');
        $add('Embedded Signup configuration', preg_match('/^\d+$/', $cfg['config_id']) ? self::OK : self::WARN, preg_match('/^\d+$/', $cfg['config_id'])
            ? 'Configuration ' . $cfg['config_id'] . ' saved. Its access token expiration should be "Never".'
            : 'Create one under Facebook Login for Business → Configurations (WhatsApp Embedded Signup, token expiration "Never") and paste its ID here.', $dashboard . '/');
        return $checks;
    }
}
