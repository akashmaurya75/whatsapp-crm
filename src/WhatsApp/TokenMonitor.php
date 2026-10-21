<?php

declare(strict_types=1);

namespace App\WhatsApp;

use App\Audit\AuditLogger;
use App\Core\Database;
use App\Core\HttpClient;
use App\Core\Logger;
use App\Core\Mailer;
use Throwable;

/**
 * Keeps "Continue with Facebook" accounts connected:
 *   - reads the real token lifetime from Meta (debug_token via the platform app) at signup and once a day
 *   - warns the company's owners/admins a week before a token expires
 *   - when Meta rejects the token (error 190), marks the account "needs_reconnect", pauses its campaigns
 *     and emails owners/admins a one-click reconnect link (once per incident)
 * A token can't be renewed without someone signing in, so the goal is to never let it die unnoticed.
 */
final class TokenMonitor
{
    public const EXPIRY_WARNING_DAYS = 7;
    /** Graph error codes meaning the access token itself is invalid, expired or revoked. */
    private const AUTH_CODES = ['190', '102'];

    public function __construct(private Database $db, private HttpClient $http, private Mailer $mailer = new Mailer())
    {
    }

    public static function isAuthError(?string $code): bool
    {
        return in_array((string) $code, self::AUTH_CODES, true);
    }

    /**
     * What Meta says about a token. reachable=false means we couldn't ask (network, platform app not configured):
     * never treat that as an invalid token.
     * @return array{reachable:bool,valid:bool,expires_at:?string,error:string}
     */
    public function inspect(string $token): array
    {
        $cfg = MetaPlatform::config($this->db);
        if ($token === '' || $cfg['app_id'] === '' || $cfg['app_secret'] === '') {
            return ['reachable' => false, 'valid' => false, 'expires_at' => null, 'error' => 'Meta app is not configured.'];
        }
        $base = rtrim((string) env('META_GRAPH_URL', 'https://graph.facebook.com'), '/');
        try {
            $r = $this->http->request('GET', $base . '/' . MetaPlatform::graphVersion() . '/debug_token?' . http_build_query([
                'input_token' => $token, 'access_token' => $cfg['app_id'] . '|' . $cfg['app_secret'],
            ]));
        } catch (Throwable $e) {
            return ['reachable' => false, 'valid' => false, 'expires_at' => null, 'error' => 'Could not reach Meta: ' . $e->getMessage()];
        }
        $json = json_decode($r['body'], true) ?: [];
        if ($r['status'] !== 200 || !isset($json['data'])) {
            return ['reachable' => false, 'valid' => false, 'expires_at' => null, 'error' => (string) ($json['error']['message'] ?? 'HTTP ' . $r['status'])];
        }
        $d = (array) $json['data'];
        // 0 means "never". Use whichever of the two lifetimes ends first.
        $ends = array_filter([(int) ($d['expires_at'] ?? 0), (int) ($d['data_access_expires_at'] ?? 0)], static fn (int $t) => $t > 0);
        return [
            'reachable' => true, 'valid' => !empty($d['is_valid']),
            'expires_at' => $ends ? gmdate('Y-m-d H:i:s', min($ends)) : null,
            'error' => mb_substr((string) ($d['error']['message'] ?? ''), 0, 300),
        ];
    }

    /** Check one account's token. Returns the new token_status, or null when skipped (manual account, Meta unreachable). */
    public function check(array $account): ?string
    {
        if (($account['provider'] ?? '') !== 'meta_cloud' || !AccountRepository::isManaged($account)) {
            return null;
        }
        $info = $this->inspect((string) (AccountRepository::credentials($account)['access_token'] ?? ''));
        if (!$info['reachable']) {
            return null;
        }
        $id = (int) $account['id'];
        if (!$info['valid']) {
            $this->markNeedsReconnect($id, 'Meta access was removed or expired' . ($info['error'] !== '' ? ': ' . $info['error'] : '.'));
            return 'invalid';
        }
        $expiring = $info['expires_at'] !== null && strtotime($info['expires_at'] . ' UTC') - time() < self::EXPIRY_WARNING_DAYS * 86400;
        $row = ['token_status' => $expiring ? 'expiring' : 'valid', 'token_expires_at' => $info['expires_at'], 'token_checked_at' => now()];
        if (!$expiring) {
            $row['reconnect_notified_at'] = null;
            if ($account['status'] === 'needs_reconnect') {
                $row += ['status' => 'connected', 'last_error' => null, 'last_error_at' => null];
            }
        } elseif ($account['reconnect_notified_at'] === null) {
            $this->notify($account, false);
            $row['reconnect_notified_at'] = now();
        }
        $this->db->update('whatsapp_accounts', $row, ['id' => $id]);
        return $row['token_status'];
    }

    /** Daily sweep of every Facebook-connected account of active companies. @return int accounts checked */
    public function checkAll(): int
    {
        $n = 0;
        foreach ($this->db->all("SELECT a.* FROM whatsapp_accounts a JOIN tenants t ON t.id = a.tenant_id
            WHERE a.provider = 'meta_cloud' AND t.status = 'active'") as $acc) {
            try {
                if ($this->check($acc) !== null) {
                    $n++;
                }
            } catch (Throwable $e) {
                Logger::warning('Token check failed', ['account_id' => $acc['id'], 'error' => $e->getMessage()]);
            }
        }
        return $n;
    }

    /** Meta rejected the token: stop using it and ask the company to reconnect (notifies once per incident). */
    public function markNeedsReconnect(int $accountId, string $reason): void
    {
        $acc = $this->db->first('SELECT * FROM whatsapp_accounts WHERE id = ?', [$accountId]);
        if ($acc === null) {
            return;
        }
        $firstNotice = $acc['token_status'] !== 'invalid' || $acc['reconnect_notified_at'] === null;
        $this->db->update('whatsapp_accounts', [
            'status' => 'needs_reconnect', 'token_status' => 'invalid', 'token_checked_at' => now(),
            'last_error' => mb_substr($reason, 0, 500), 'last_error_at' => now(),
        ] + ($firstNotice ? ['reconnect_notified_at' => now()] : []), ['id' => $accountId]);
        // Campaigns would only burn through their audience with failures; pause them so they can be resumed after reconnecting.
        $paused = $this->db->run("UPDATE campaigns SET status = 'paused', last_error = ?, updated_at = ?
            WHERE whatsapp_account_id = ? AND status IN ('running', 'scheduled')",
            ['Paused automatically: the WhatsApp account must be reconnected with Facebook.', now(), $accountId])->rowCount();
        if (!$firstNotice) {
            return;
        }
        Logger::warning('WhatsApp account needs reconnect', ['account_id' => $accountId, 'campaigns_paused' => $paused]);
        (new AuditLogger($this->db))->record('whatsapp_account.needs_reconnect', (int) $acc['tenant_id'], null, 'whatsapp_account', $accountId,
            ['reason' => mb_substr($reason, 0, 200), 'campaigns_paused' => $paused]);
        $this->notify($acc, true);
    }

    /** Email the company's owners and admins. */
    private function notify(array $acc, bool $broken): void
    {
        $emails = $this->db->all("SELECT u.email FROM users u JOIN roles r ON r.id = u.role_id
            WHERE u.tenant_id = ? AND u.status = 'active' AND r.slug IN ('owner', 'admin')", [$acc['tenant_id']]);
        $label = trim($acc['name'] . ' ' . ($acc['phone_e164'] ?? ''));
        $link = url('/whatsapp/connect?account=' . (int) $acc['id']);
        $subject = $broken ? "Action needed: reconnect WhatsApp $label" : "WhatsApp $label: Facebook access expires soon";
        $body = $broken
            ? "Meta no longer accepts the access for your WhatsApp number $label, so messages can't be sent.\n"
                . "Running campaigns on this number were paused.\n\nReconnect in one click (sign in with Facebook):\n$link\n\n"
                . "After reconnecting, resume any paused campaigns from the Campaigns page."
            : "Facebook access for your WhatsApp number $label expires within " . self::EXPIRY_WARNING_DAYS . " days.\n\n"
                . "Reconnect now to avoid interruption (sign in with Facebook):\n$link";
        foreach ($emails as $u) {
            try {
                $this->mailer->send((string) $u['email'], $subject, $body);
            } catch (Throwable $e) {
                Logger::warning('Reconnect email failed', ['account_id' => $acc['id'], 'error' => $e->getMessage()]);
            }
        }
    }
}
