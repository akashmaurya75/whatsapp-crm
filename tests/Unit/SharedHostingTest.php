<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\App;
use App\Core\BackgroundRunner;
use App\Core\Crypto;
use App\Core\Env;
use App\Core\Job;
use App\Core\Request;
use App\Core\Scheduler;
use App\Core\Session;
use App\Install\InstallController;
use App\Install\Installer;
use Tests\TestCase;

final class CountingJob implements Job
{
    public static int $runs = 0;

    public function handle(App $app, array $data): void
    {
        self::$runs++;
    }

    public function maxAttempts(): int
    {
        return 3;
    }

    public function backoff(int $attempt): int
    {
        return 1;
    }
}

final class SharedHostingTest extends TestCase
{
    private string $dir;

    public function setUp(): void
    {
        parent::setUp();
        Crypto::$forceOpenssl = false;
        CountingJob::$runs = 0;
        @unlink(base_path(Scheduler::STAMP));
        @unlink(base_path(Scheduler::HEALTH_STAMP));
        $this->dir = sys_get_temp_dir() . '/wcrm-shared-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/storage', 0755, true);
        mkdir($this->dir . '/database', 0755, true);
        copy(base_path('.env.example'), $this->dir . '/.env.example');
        symlink(base_path('database/migrations'), $this->dir . '/database/migrations');
    }

    public function testOpensslFallbackEncryptsAndStillReadsSodiumData(): void
    {
        $sodium = Crypto::encrypt('token-123');
        $this->assertTrue(str_starts_with($sodium, 'v1:'));

        Crypto::$forceOpenssl = true;
        try {
            $gcm = Crypto::encrypt('token-456');
            $this->assertTrue(str_starts_with($gcm, 'v2:'), 'openssl format used without sodium');
            $this->assertSame('token-456', Crypto::decrypt($gcm));
            $this->assertSame('token-123', Crypto::decrypt($sodium), 'old sodium data still readable');
            $this->assertSame(['a' => 1], Crypto::decryptArray(Crypto::encryptArray(['a' => 1])));
            $raw = base64_decode(substr($gcm, 3));
            $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
            $this->assertThrows(\RuntimeException::class, fn () => Crypto::decrypt('v2:' . base64_encode($raw)));
            Env::set('APP_KEY', 'base64:' . base64_encode(str_repeat("\x02", 32)));
            $this->assertThrows(\RuntimeException::class, fn () => Crypto::decrypt($gcm));
        } finally {
            Crypto::$forceOpenssl = false;
        }
        $this->assertTrue(Crypto::available());
    }

    public function testCronRunnerProcessesJobsAndMaintenanceOnce(): void
    {
        $app = $this->app();
        $app->queue->push(CountingJob::class, [], 'default');
        $app->queue->push(CountingJob::class, [], 'messages');
        $app->queue->push(CountingJob::class, [], 'default', 3600); // not due yet
        $r = BackgroundRunner::run(2, 'web', $this->db);
        $this->assertFalse($r['locked']);
        $this->assertSame([], BackgroundRunner::migrateIfNeeded($this->db), 'schema already current');
        $this->assertSame(2, CountingJob::$runs);
        $this->assertSame(2, $r['jobs']);
        $this->assertTrue($r['scheduled'], 'maintenance ran');
        $this->assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM jobs WHERE payload LIKE ?', ['%CountingJob%']));
        $this->assertFalse(Scheduler::due(), 'maintenance throttled to once a minute');
        $this->assertFalse(BackgroundRunner::hasWork($this->db), 'nothing due');
        $this->assertSame('web', BackgroundRunner::lastRun()['source'] ?? null);
        $this->assertTrue(time() - (int) file_get_contents(base_path('storage/worker.heartbeat')) < 5);
    }

    public function testOnlyOneRunnerAtATime(): void
    {
        $fh = fopen(base_path(BackgroundRunner::LOCK), 'c');
        flock($fh, LOCK_EX);
        try {
            $this->app()->queue->push(CountingJob::class, []);
            // flock is per open file description, so a second fopen in the same process is a real contender.
            $r = BackgroundRunner::run(2, 'cron', $this->db);
            $this->assertTrue($r['locked']);
            $this->assertSame(0, CountingJob::$runs);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
        $this->assertSame(1, BackgroundRunner::run(1, 'web', $this->db)['jobs']);
    }

    public function testWebCronNeedsTheSecretKey(): void
    {
        Env::set('CRON_KEY', '');
        $this->assertStatus(404, $this->web('GET', '/cron?key='));
        Env::set('CRON_KEY', 'k3y-for-cron-0123456789abcdef');
        $this->assertStatus(404, $this->web('GET', '/cron'));
        $this->assertStatus(404, $this->web('GET', '/cron?key=k3y-for-cron-0123456789abcdeX'));
        $this->app()->queue->push(CountingJob::class, []);
        Env::set('WEB_CRON_SECONDS', '5');
        $r = $this->web('GET', '/cron?key=k3y-for-cron-0123456789abcdef');
        $this->assertStatus(200, $r);
        $this->assertContains('"status":"ok"', $r->body);
        $this->assertSame(1, CountingJob::$runs);
        Env::set('CRON_KEY', '');
    }

    public function testSubfolderDetection(): void
    {
        $this->assertSame('', Installer::subfolder('/index.php'));
        $this->assertSame('', Installer::subfolder('/public/index.php'));
        $this->assertSame('/crm', Installer::subfolder('/crm/index.php'));
        $this->assertSame('/crm', Installer::subfolder('/crm/public/index.php'));
        $inst = new Installer($this->dir);
        $checks = $inst->webChecks('/home/u/public_html', '/crm/index.php');
        $this->assertFalse($checks[0]['ok']);
        $this->assertTrue($checks[0]['required']);
        $this->assertContains('subdomain', $checks[0]['help']);
    }

    public function testExposedPrivateFilesBlockTheInstall(): void
    {
        $inst = new Installer($this->dir);
        $seen = [];
        $exposed = function (string $url) use (&$seen) {
            $seen[] = $url;
            return [200, "APP_NAME=x\nAPP_KEY=\n"];
        };
        $checks = $inst->webChecks(dirname($this->dir), '/index.php', $exposed, 'https://crm.example.com');
        $this->assertSame('https://crm.example.com/' . basename($this->dir) . '/.env.example', $seen[0]);
        $this->assertFalse($checks[1]['ok'], 'exposed .env.example detected');
        $this->assertTrue($checks[1]['required']);

        $safe = fn () => [302, ''];
        $this->assertTrue($inst->webChecks(dirname($this->dir), '/index.php', $safe, 'https://x.test')[1]['ok']);
        $unknown = fn () => null;
        $c = $inst->webChecks(dirname($this->dir), '/index.php', $unknown, 'https://x.test')[1];
        $this->assertFalse($c['ok']);
        $this->assertFalse($c['required'], 'an inconclusive test only warns');
        // App outside the web folder: no probe at all.
        $this->assertSame(1, count($inst->webChecks('/srv/www/public_html', '/index.php', $exposed, 'https://x.test')));
    }

    public function testSharedHostingInstallUsesInlineQueueAndCronKey(): void
    {
        $inst = new Installer($this->dir);
        $db = $inst->checkDatabase(['driver' => 'sqlite']);
        [$app, $errors] = $inst->validateApp([
            'app_name' => 'Acme', 'app_url' => 'https://crm.acme-example.com', 'country_code' => '91', 'timezone' => 'UTC',
            'company' => 'Acme', 'owner_name' => 'Asha', 'owner_email' => 'asha@acme.test', 'owner_password' => 'Strong-pass-2026',
            'admin_email' => 'root@acme.test', 'admin_password' => 'Platform-key-2026', 'hosting' => 'shared',
        ]);
        $this->assertSame([], $errors);
        $inst->install($db, $app, false); // https URL but the server didn't report https (e.g. Cloudflare Flexible)
        $env = (string) file_get_contents($this->dir . '/.env');
        $this->assertContains('QUEUE_MODE=inline', $env);
        $this->assertTrue(preg_match('/^CRON_KEY=[A-Za-z0-9_\-]{32}$/m', $env) === 1, 'cron key written');
        $this->assertContains('APP_FORCE_HTTPS=false', $env, 'no redirect loop');
        $this->assertContains('SESSION_SECURE_COOKIE=true', $env);
        $this->assertSame('0640', substr(sprintf('%o', fileperms($this->dir . '/.env')), -4));

        [, $errors] = $inst->validateApp(['hosting' => 'cloud'] + $app);
        $this->assertTrue(isset($errors['hosting']));
    }

    public function testInstallerBlocksSubfolderInstallsBeforeTheDatabaseStep(): void
    {
        $ctl = new InstallController(new Installer($this->dir), $session = Session::memory());
        $server = ['REMOTE_ADDR' => '127.0.0.1', 'SCRIPT_NAME' => '/crm/index.php', 'DOCUMENT_ROOT' => '/nonexistent'];
        $r = $ctl->handle(new Request('GET', '/install', server: $server));
        $this->assertContains('root of a domain', $r->body);
        $this->assertNotContains('href="/install/database"', $r->body);
        $this->assertSame('/install', $ctl->handle(new Request('GET', '/install/database', server: $server))->headers['Location']);
    }

    public function testPhpCliCandidatesAreAbsolutePaths(): void
    {
        foreach (Installer::phpCliCandidates() as $p) {
            $this->assertTrue(str_starts_with($p, '/') && is_file($p), "$p exists");
        }
    }
}
