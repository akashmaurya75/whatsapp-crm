<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Database;
use App\Core\Env;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Session;
use App\Install\InstallController;
use App\Install\InstallException;
use App\Install\Installer;
use Tests\TestCase;

final class InstallerTest extends TestCase
{
    private string $dir;

    public function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/wcrm-install-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/storage', 0775, true);
        mkdir($this->dir . '/database', 0775, true);
        copy(base_path('.env.example'), $this->dir . '/.env.example');
        symlink(base_path('database/migrations'), $this->dir . '/database/migrations');
    }

    private function appInput(array $over = []): array
    {
        return $over + [
            'app_name' => 'Acme CRM', 'app_url' => 'https://crm.acme-example.com', 'country_code' => '91', 'timezone' => 'Asia/Kolkata',
            'company' => 'Acme Traders', 'owner_name' => 'Asha', 'owner_email' => 'asha@acme.test',
            'owner_password' => 'Strong-pass-2026', 'owner_password_confirmation' => 'Strong-pass-2026',
            'admin_email' => 'root@acme.test', 'admin_password' => 'Platform-key-2026', 'admin_password_confirmation' => 'Platform-key-2026',
        ];
    }

    public function testFullSqliteInstallWritesEnvLockAndAccounts(): void
    {
        $inst = new Installer($this->dir);
        $this->assertFalse($inst->isInstalled());
        $db = $inst->checkDatabase(['driver' => 'sqlite', 'sqlite_path' => '']);
        [$app, $errors] = $inst->validateApp($this->appInput());
        $this->assertSame([], $errors);
        $inst->install($db, $app);

        $this->assertTrue($inst->isInstalled());
        $this->assertTrue(is_file($this->dir . '/storage/installed.lock'));
        $env = (string) file_get_contents($this->dir . '/.env');
        $this->assertTrue(preg_match('/^APP_KEY=base64:[A-Za-z0-9+\/=]{44}$/m', $env) === 1, 'APP_KEY written');
        $this->assertContains('APP_URL=https://crm.acme-example.com', $env);
        $this->assertContains('APP_NAME="Acme CRM"', $env);
        $this->assertContains('APP_FORCE_HTTPS=true', $env);
        $this->assertContains('APP_DEBUG=false', $env);
        $this->assertContains('DB_CONNECTION=sqlite', $env);
        $this->assertNotContains('Strong-pass-2026', $env, 'passwords never stored in .env');

        $pdo = new \PDO('sqlite:' . $this->dir . '/storage/database.sqlite');
        $this->assertSame('1', (string) $pdo->query('SELECT is_super_admin FROM users WHERE email = "root@acme.test"')->fetchColumn());
        $this->assertSame('Acme Traders', $pdo->query('SELECT name FROM tenants')->fetchColumn());
        $this->assertSame('8', (string) $pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn());
        $hash = (string) $pdo->query('SELECT password_hash FROM users WHERE email = "asha@acme.test"')->fetchColumn();
        $this->assertTrue(password_verify('Strong-pass-2026', $hash));

        // The written .env loads back correctly.
        Env::load($this->dir . '/.env');
        $this->assertSame('Acme CRM', Env::get('APP_NAME'));
        $this->assertThrows(InstallException::class, fn () => $inst->install($db, $app));
    }

    public function testInstallRefusesDatabaseWithExistingUsers(): void
    {
        $inst = new Installer($this->dir);
        $inst->install($inst->checkDatabase(['driver' => 'sqlite']), $inst->validateApp($this->appInput())[0]);
        unlink($this->dir . '/storage/installed.lock');
        unlink($this->dir . '/.env');
        $this->assertThrows(InstallException::class, fn () => $inst->checkDatabase(['driver' => 'sqlite']));
    }

    public function testValidationErrors(): void
    {
        $inst = new Installer($this->dir);
        [, $e] = $inst->validateApp($this->appInput([
            'app_url' => 'not a url', 'owner_password' => 'short', 'admin_email' => 'asha@acme.test', 'country_code' => '+abc', 'timezone' => 'Mars/Base',
        ]));
        foreach (['app_url', 'owner_password', 'admin_email', 'country_code', 'timezone'] as $f) {
            $this->assertTrue(isset($e[$f]), "expected error on $f");
        }
        [, $e2] = $inst->validateApp($this->appInput(['owner_password_confirmation' => 'Different-pass-2026']));
        $this->assertSame('Passwords do not match.', $e2['owner_password'] ?? null);
    }

    public function testMysqlConfigValidationAndConnectionErrorsAreFriendly(): void
    {
        $inst = new Installer($this->dir);
        $this->assertThrows(InstallException::class, fn () => $inst->checkDatabase(['driver' => 'mysql', 'database' => 'bad name;drop', 'username' => 'u']));
        $this->assertThrows(InstallException::class, fn () => $inst->checkDatabase(['driver' => 'mysql', 'database' => 'ok', 'username' => '']));
        try {
            $inst->checkDatabase(['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '1', 'database' => 'crm', 'username' => 'u', 'password' => 'Secr3tPw']);
            $this->assertTrue(false, 'should not connect');
        } catch (InstallException $e) {
            $this->assertContains('Could not connect to MySQL', $e->getMessage());
            $this->assertNotContains('Secr3tPw', $e->getMessage());
        }
    }

    public function testExistingConfiguredEnvCountsAsInstalled(): void
    {
        file_put_contents($this->dir . '/.env', "APP_KEY=base64:abc\n");
        $this->assertTrue((new Installer($this->dir))->isInstalled());
        file_put_contents($this->dir . '/.env', "APP_KEY=\n");
        $this->assertFalse((new Installer($this->dir))->isInstalled());
    }

    public function testEnvValueQuoting(): void
    {
        $this->assertSame('simple-value_1', Installer::envValue('simple-value_1'));
        $this->assertSame('"has space"', Installer::envValue('has space'));
        $this->assertSame('"p#ss=word"', Installer::envValue('p#ss=word'));
        $this->assertSame('', Installer::envValue(''));
    }

    public function testRemoteVisitorsNeedInstallKeyAndCsrf(): void
    {
        $inst = new Installer($this->dir);
        $session = Session::memory();
        $ctl = new InstallController($inst, $session);
        $remote = ['REMOTE_ADDR' => '203.0.113.9'];
        $r = $ctl->handle(new Request('GET', '/install', server: $remote));
        $this->assertContains('installer key', strtolower($r->body));
        $key = trim((string) file_get_contents($this->dir . '/storage/install-key.txt'));
        $this->assertSame(12, strlen($key));
        // CSRF required
        $this->assertSame(419, $ctl->handle(new Request('POST', '/install/unlock', post: ['install_key' => $key], server: $remote))->status);
        $token = (string) $session->get('install.csrf');
        $this->assertSame(403, $ctl->handle(new Request('POST', '/install/unlock', post: ['_token' => $token, 'install_key' => 'WRONGKEY'], server: $remote))->status);
        $ok = $ctl->handle(new Request('POST', '/install/unlock', post: ['_token' => (string) $session->get('install.csrf'), 'install_key' => strtolower($key)], server: $remote));
        $this->assertSame('/install', $ok->headers['Location']);
        $this->assertContains('Check', $ctl->handle(new Request('GET', '/install', server: $remote))->body);
    }

    public function testLocalVisitorGoesStraightToRequirementsAndInstallerLocksAfterwards(): void
    {
        $inst = new Installer($this->dir);
        $session = Session::memory();
        $ctl = new InstallController($inst, $session);
        $local = ['REMOTE_ADDR' => '127.0.0.1'];
        $this->assertContains('install WhatsApp CRM',$ctl->handle(new Request('GET', '/install', server: $local))->body);
        $this->assertSame('/install', $ctl->handle(new Request('GET', '/contacts', server: $local))->headers['Location']);
        $csrf = (string) $session->get('install.csrf');
        $r = $ctl->handle(new Request('POST', '/install/database', post: ['_token' => $csrf, 'driver' => 'sqlite'], server: $local));
        $this->assertSame('/install/app', $r->headers['Location']);
        $r = $ctl->handle(new Request('POST', '/install/app', post: ['_token' => $csrf] + $this->appInput(), headers: ['host' => 'localhost:8000'], server: $local));
        $this->assertSame('/install/done', $r->headers['Location']);
        $this->assertContains('is installed', $ctl->handle(new Request('GET', '/install/done', server: $local))->body);
        // Locked: every installer URL now goes to the login page.
        $this->assertSame('/login', $ctl->handle(new Request('GET', '/install', server: $local))->headers['Location']);
        $this->assertSame('/login', $ctl->handle(new Request('POST', '/install/app', post: ['_token' => $csrf] + $this->appInput(), server: $local))->headers['Location']);
    }

    public function testMysqlForeignKeysAreHoisted(): void
    {
        $sql = "CREATE TABLE x (\n        id {pk},\n        tenant_id BIGINT UNSIGNED NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,\n        owner BIGINT UNSIGNED NULL REFERENCES users(id) ON DELETE SET NULL,\n        name VARCHAR(10) NOT NULL\n    ){engine}";
        $out = Migrator::translateFor('mysql', $sql);
        $this->assertContains('tenant_id BIGINT UNSIGNED NOT NULL,', $out);
        $this->assertContains('FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE', $out);
        $this->assertContains('FOREIGN KEY (owner) REFERENCES users(id) ON DELETE SET NULL', $out);
        $this->assertContains('ENGINE=InnoDB', $out);
        $this->assertSame(2, substr_count($out, 'REFERENCES'));
        $this->assertContains('INTEGER PRIMARY KEY AUTOINCREMENT', Migrator::translateFor('sqlite', $sql));
    }
}
