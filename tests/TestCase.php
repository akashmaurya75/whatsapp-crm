<?php

declare(strict_types=1);

namespace Tests;

use App\Auth\PasswordPolicy;
use App\Core\App;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Env;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Tenancy\TenantProvisioner;
use PDO;

/** Minimal xUnit-style base class: fresh in-memory SQLite database per test. */
abstract class TestCase
{
    protected Database $db;
    protected FakeHttpClient $http;
    protected Router $router;
    public int $assertions = 0;

    public function setUp(): void
    {
        Env::set('APP_KEY', 'base64:' . base64_encode(str_repeat("\x01", 32)));
        Env::set('APP_DEBUG', 'false');
        Env::set('APP_FORCE_HTTPS', 'false');
        Env::set('DEFAULT_COUNTRY_CODE', '91');
        Env::set('LOG_FILE', sys_get_temp_dir() . '/wcrm-test.log');
        Env::set('MAIL_DRIVER', 'file');
        Env::set('API_RATE_LIMIT_PER_MINUTE', '120');
        foreach (['META_APP_ID', 'META_APP_SECRET', 'META_ES_CONFIG_ID', 'META_WEBHOOK_VERIFY_TOKEN'] as $k) {
            Env::set($k, '');
        }
        \App\WhatsApp\MetaPlatform::resetCache();
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $this->db = new Database($pdo, 'sqlite');
        Database::setConnection($this->db);
        (new Migrator($this->db, base_path('database/migrations')))->migrate();
        TenantProvisioner::syncPermissions($this->db);
        $this->http = new FakeHttpClient();
        $this->router = new Router();
        (require base_path('routes.php'))($this->router);
    }

    // ----- fixtures -----

    /** @return array{tenant_id:int,user_id:int} */
    protected function tenant(string $name = 'Tenant', ?string $email = null): array
    {
        $email ??= strtolower(preg_replace('/\W+/', '', $name)) . '-owner@test.local';
        return (new TenantProvisioner($this->db))->provision($name, $name . ' Owner', $email, 'Correct-horse-42');
    }

    protected function user(int $tenantId, string $roleSlug, string $email): int
    {
        $roleId = (int) $this->db->scalar('SELECT id FROM roles WHERE tenant_id = ? AND slug = ?', [$tenantId, $roleSlug]);
        return $this->db->insert('users', [
            'tenant_id' => $tenantId, 'role_id' => $roleId, 'name' => ucfirst($roleSlug), 'email' => $email,
            'password_hash' => PasswordPolicy::hash('Correct-horse-42'), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function account(int $tenantId, string $provider = 'meta_cloud', array $creds = []): array
    {
        $creds += $provider === 'meta_cloud'
            ? ['access_token' => 'EAAtest', 'app_secret' => 'app-secret-123', 'verify_token' => 'verify-me']
            : ['api_key' => 'gw-key', 'signing_secret' => ''];
        $id = $this->db->insert('whatsapp_accounts', [
            'tenant_id' => $tenantId, 'name' => 'Main ' . $provider, 'provider' => $provider,
            'phone_e164' => '+911234567890', 'phone_number_id' => $provider === 'meta_cloud' ? '1000' . $tenantId : null,
            'base_url' => $provider === 'openwa' ? 'https://gw.test' : null,
            'credentials_enc' => Crypto::encryptArray($creds), 'webhook_token' => random_token(32),
            'capabilities' => json_encode($provider === 'meta_cloud'
                ? ['text', 'media', 'templates', 'interactive', 'reactions', 'replies', 'read_receipts']
                : ['text', 'media', 'replies', 'reactions', 'groups', 'typing', 'qr_session', 'read_receipts']),
            'status' => 'connected', 'created_at' => now(), 'updated_at' => now(),
        ]);
        return (array) $this->db->first('SELECT * FROM whatsapp_accounts WHERE id = ?', [$id]);
    }

    protected function template(int $tenantId, int $accountId, array $over = []): array
    {
        $id = $this->db->insert('message_templates', $over + [
            'tenant_id' => $tenantId, 'whatsapp_account_id' => $accountId, 'name' => 'order_update', 'language' => 'en',
            'category' => 'utility', 'status' => 'approved', 'provider_template_id' => '777' . $accountId, 'header_type' => 'none',
            'body' => 'Hi {{1}}, your order {{2}} has shipped. Thank you!', 'body_samples' => '["Asha","#1042"]',
            'footer' => 'Reply STOP to opt out', 'buttons' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]);
        return (array) $this->db->first('SELECT * FROM message_templates WHERE id = ?', [$id]);
    }

    protected function contact(int $tenantId, string $phone, array $over = []): int
    {
        return $this->db->insert('contacts', $over + [
            'tenant_id' => $tenantId, 'phone_e164' => $phone, 'name' => 'Contact ' . substr($phone, -3), 'lead_status' => 'new',
            'consent_status' => 'granted', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ----- HTTP helpers -----

    protected function app(?Session $session = null): App
    {
        return new App($this->db, $session ?? Session::memory(), $this->http, $this->router);
    }

    protected function sessionFor(int $userId): Session
    {
        $session = Session::memory();
        $user = (array) $this->db->first('SELECT * FROM users WHERE id = ?', [$userId]);
        (new App($this->db, $session, $this->http, $this->router))->auth->login($user, '127.0.0.1', 'test');
        return $session;
    }

    /** Web request; POSTs automatically carry a valid CSRF token unless $csrf=false. */
    protected function web(string $method, string $path, ?Session $session = null, array $post = [], bool $csrf = true, array $headers = []): Response
    {
        $session ??= Session::memory();
        $app = $this->app($session);
        if ($method !== 'GET' && $csrf) {
            $post['_token'] = $app->csrfToken();
        }
        [$p, $q] = array_pad(explode('?', $path, 2), 2, '');
        parse_str($q, $query);
        $request = new Request($method, $p, $query, $post, $headers, '', ['REMOTE_ADDR' => '127.0.0.1']);
        return $app->handle($request);
    }

    protected function api(string $method, string $path, ?string $token, ?array $json = null, array $headers = []): Response
    {
        $h = ['accept' => 'application/json', 'content-type' => 'application/json'] + $headers;
        if ($token !== null) {
            $h['authorization'] = 'Bearer ' . $token;
        }
        [$p, $q] = array_pad(explode('?', $path, 2), 2, '');
        parse_str($q, $query);
        $body = $json !== null ? (string) json_encode($json) : '';
        return $this->app()->handle(new Request($method, $p, $query, [], $h, $body, ['REMOTE_ADDR' => '127.0.0.1']));
    }

    protected function raw(string $method, string $path, string $body, array $headers = []): Response
    {
        [$p, $q] = array_pad(explode('?', $path, 2), 2, '');
        parse_str($q, $query);
        return $this->app()->handle(new Request($method, $p, $query, [], $headers, $body, ['REMOTE_ADDR' => '127.0.0.1']));
    }

    /** Drain all queues (repeatedly, so jobs that enqueue other jobs are followed). */
    protected function runQueue(int $max = 200): int
    {
        $n = 0;
        do {
            $did = false;
            foreach (['webhooks', 'messages', 'default'] as $q) {
                while ($n < $max) {
                    $app = $this->app();
                    // Ignore retry backoff in tests, but keep campaign ticks on their real schedule.
                    $this->db->run("UPDATE jobs SET available_at = 0 WHERE reserved_at IS NULL AND payload NOT LIKE '%CampaignTickJob%'");
                    if (!$app->queue->work($app, $q, 'test')) {
                        break;
                    }
                    $did = true;
                    $n++;
                }
            }
        } while ($did && $n < $max);
        return $n;
    }

    // ----- assertions -----

    protected function assertTrue(bool $cond, string $msg = 'Expected true'): void
    {
        $this->assertions++;
        if (!$cond) {
            throw new AssertionFailed($msg);
        }
    }

    protected function assertFalse(bool $cond, string $msg = 'Expected false'): void
    {
        $this->assertTrue(!$cond, $msg);
    }

    protected function assertSame(mixed $expected, mixed $actual, string $msg = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            throw new AssertionFailed(($msg !== '' ? $msg . ': ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    protected function assertStatus(int $expected, Response $r, string $msg = ''): void
    {
        $this->assertSame($expected, $r->status, trim($msg . ' HTTP status (body: ' . mb_substr(strip_tags($r->body), 0, 200) . ')'));
    }

    protected function assertContains(string $needle, string $haystack, string $msg = ''): void
    {
        $this->assertTrue(str_contains($haystack, $needle), $msg ?: "Expected to find '$needle'");
    }

    protected function assertNotContains(string $needle, string $haystack, string $msg = ''): void
    {
        $this->assertTrue(!str_contains($haystack, $needle), $msg ?: "Did not expect to find '$needle'");
    }

    protected function assertThrows(string $class, callable $fn): void
    {
        $this->assertions++;
        try {
            $fn();
        } catch (\Throwable $e) {
            if ($e instanceof $class) {
                return;
            }
            throw new AssertionFailed("Expected $class, got " . $e::class . ': ' . $e->getMessage());
        }
        throw new AssertionFailed("Expected $class to be thrown");
    }
}
