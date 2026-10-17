<?php

declare(strict_types=1);

namespace App\Install;

use App\Auth\PasswordPolicy;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Env;
use App\Core\Migrator;
use App\Tenancy\TenantProvisioner;
use PDO;
use Throwable;

/**
 * Installs the application: checks requirements, tests the database, writes .env,
 * runs migrations, creates the super admin and first company, then locks itself.
 * Used by the web installer (/install) and by `php bin/console setup`.
 */
final class Installer
{
    public const MIN_PHP = '8.3.0';

    public function __construct(private string $basePath)
    {
    }

    private function path(string $rel): string
    {
        return $this->basePath . '/' . $rel;
    }

    public function lockFile(): string
    {
        return $this->path('storage/installed.lock');
    }

    /** Installed = lock file present, or a configured .env (protects existing deployments). */
    public function isInstalled(): bool
    {
        if (is_file($this->lockFile())) {
            return true;
        }
        $env = $this->path('.env');
        return is_file($env) && preg_match('/^APP_KEY=\S+/m', (string) file_get_contents($env)) === 1;
    }

    /** @return list<array{label:string,ok:bool,required:bool,help:string}> */
    public function requirements(): array
    {
        foreach (['storage', 'storage/logs', 'storage/sessions', 'storage/mail'] as $dir) {
            if (!is_dir($this->path($dir))) {
                @mkdir($this->path($dir), 0755, true);
            }
        }
        $panel = 'In cPanel: "Select PHP Version" → Extensions (Hostinger: Advanced → PHP Configuration → PHP extensions; Plesk: PHP Settings). Tick it, save, reload this page.';
        $checks = [];
        $add = static function (string $label, bool $ok, bool $required, string $help) use (&$checks): void {
            $checks[] = compact('label', 'ok', 'required', 'help');
        };
        $add('PHP ' . self::MIN_PHP . ' or newer (you have ' . PHP_VERSION . ')', version_compare(PHP_VERSION, self::MIN_PHP, '>='), true,
            'Choose PHP 8.3 (or newer) for this domain in your hosting panel (cPanel: "Select PHP Version" or "MultiPHP Manager").');
        foreach (['mbstring' => 'Text handling', 'curl' => 'Talking to WhatsApp / Meta', 'openssl' => 'Secure connections and encryption',
            'json' => 'JSON', 'session' => 'Logins', 'pdo' => 'Database access', 'fileinfo' => 'Checking uploaded files'] as $ext => $why) {
            $add("PHP extension: $ext ($why)", extension_loaded($ext), true, "Enable the \"$ext\" extension. $panel");
        }
        $add('Encryption for stored credentials (sodium or OpenSSL AES-GCM)', Crypto::available(), true, "Enable the \"sodium\" extension. $panel");
        $add('PHP extension: pdo_mysql or pdo_sqlite (database driver)', extension_loaded('pdo_mysql') || extension_loaded('pdo_sqlite'), true,
            "Enable the \"pdo_mysql\" extension (or \"nd_pdo_mysql\" on CloudLinux). $panel");
        $add('Folder "storage" is writable', is_writable($this->path('storage')), true,
            'In your File Manager set the "storage" folder (and the folders inside it) to permission 755. If that is not enough, use 775.');
        $envWritable = is_file($this->path('.env')) ? is_writable($this->path('.env')) : is_writable($this->basePath);
        $add('Can create the .env settings file in the app folder', $envWritable, true,
            'Set the application folder to permission 755 in your File Manager (or create an empty file named .env there with permission 644).');
        $add('PHP extension: sodium (faster encryption)', extension_loaded('sodium'), false, 'Optional — OpenSSL is used when it is missing. ' . $panel);
        $add('Argon2id password hashing', defined('PASSWORD_ARGON2ID'), false, 'Optional: falls back to bcrypt, which is also secure.');
        return $checks;
    }

    /**
     * Server-specific problems that block a safe install. $docRoot/$scriptName come from the web request.
     * @return list<array{label:string,ok:bool,required:bool,help:string}>
     */
    public function webChecks(string $docRoot, string $scriptName, ?callable $fetch = null, string $origin = ''): array
    {
        $checks = [];
        $sub = self::subfolder($scriptName);
        $checks[] = ['label' => 'Installed at the root of a domain or subdomain' . ($sub !== '' ? " (it is in \"$sub\")" : ''), 'ok' => $sub === '', 'required' => true,
            'help' => 'This app must be opened at the top of a domain, e.g. https://crm.yourcompany.com — not https://yourcompany.com' . $sub
                . '. Create a subdomain in your hosting panel, point its folder (document root) at the app, and open that address.'];

        $real = realpath($this->basePath) ?: $this->basePath;
        $root = $docRoot !== '' ? (realpath($docRoot) ?: $docRoot) : '';
        $inside = $root !== '' && ($real === $root || str_starts_with($real . '/', rtrim($root, '/') . '/'));
        if ($inside && PHP_SAPI === 'cli-server') {
            $checks[] = ['label' => 'PHP test server was started in the app folder (better: the "public" folder)', 'ok' => false, 'required' => false,
                'help' => 'Fine for trying it on your own computer, but this way the test server can also hand out .env and other private files. '
                    . 'Start it with: php -S localhost:8000 -t public   (or ./start.sh). On shared hosting you never run php -S: open your (sub)domain instead.'];
        } elseif ($inside && $fetch !== null && $origin !== '') {
            // The whole app sits inside the public web folder: make sure the protection rules actually work here.
            $prefix = substr($real, strlen(rtrim($root, '/')));
            $res = $fetch($origin . $prefix . '/.env.example');
            if ($res === null) {
                $checks[] = ['label' => 'Private files are protected (could not test automatically)', 'ok' => false, 'required' => false,
                    'help' => "Open {$origin}{$prefix}/.env.example in your browser: you must NOT see its text. If you do, use the shared-hosting package layout (app folder outside public_html)."];
            } else {
                $exposed = $res[0] === 200 && str_contains($res[1], 'APP_KEY=');
                $checks[] = ['label' => 'Private files (.env, storage, source code) cannot be downloaded', 'ok' => !$exposed, 'required' => true,
                    'help' => 'This server ignores the .htaccess protection (common on Nginx-only hosts). Move the app folder out of the web folder and put only the contents of "public" in it — see docs/SHARED_HOSTING.md.'];
            }
        }
        return $checks;
    }

    /** Path below the domain root that the app is served from, e.g. "/crm" (empty at the root). */
    public static function subfolder(string $scriptName): string
    {
        $dir = str_replace('\\', '/', dirname($scriptName));
        $dir = rtrim($dir, '/.');
        if (str_ends_with($dir, '/public')) {
            $dir = substr($dir, 0, -7);
        }
        return $dir;
    }

    /** Best-effort HTTP GET used by webChecks(): [status, body] or null when the request could not be made. */
    public static function httpGet(string $url): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_RANGE => '0-4095']);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ($body === false || $status === 0) ? null : [$status === 206 ? 200 : $status, (string) $body];
    }

    /**
     * Likely command-line PHP binaries on common hosting panels, best match first (for the cron line).
     * Cron jobs often run an older default PHP than the website, so the full path matters.
     */
    public static function phpCliCandidates(): array
    {
        $v = PHP_MAJOR_VERSION . PHP_MINOR_VERSION;
        $dotted = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $paths = [
            "/opt/cpanel/ea-php$v/root/usr/bin/php",   // cPanel EasyApache
            "/opt/alt/php$v/usr/bin/php",              // CloudLinux PHP Selector (Hostinger, Namecheap, A2…)
            "/opt/plesk/php/$dotted/bin/php",          // Plesk
            "/usr/local/php$v/bin/php",                // DirectAdmin
            "/usr/bin/php$dotted",                     // Debian/Ubuntu
        ];
        $found = [];
        foreach ($paths as $p) {
            if (@is_file($p)) {
                $found[] = $p;
            }
        }
        if (PHP_BINARY !== '' && str_contains(basename(PHP_BINARY), 'php') && !str_contains(PHP_BINARY, 'fpm') && !str_contains(PHP_BINARY, 'lsphp') && !str_contains(PHP_BINARY, 'cgi')) {
            $found[] = PHP_BINARY;
        }
        return array_values(array_unique($found));
    }

    public function requirementsMet(): bool
    {
        foreach ($this->requirements() as $c) {
            if ($c['required'] && !$c['ok']) {
                return false;
            }
        }
        return true;
    }

    /** @return list<string> drivers that can be selected */
    public function availableDrivers(): array
    {
        return array_values(array_filter(['mysql', 'sqlite'], static fn ($d) => in_array($d, PDO::getAvailableDrivers(), true)));
    }

    /**
     * Normalises and tests a database configuration. Creates the MySQL database if it does not
     * exist and the user is allowed to. Returns the cleaned config; throws InstallException with a
     * human-readable message on failure.
     * @param array<string,string> $in
     * @return array<string,string>
     */
    public function checkDatabase(array $in): array
    {
        $driver = $in['driver'] ?? 'mysql';
        if (!in_array($driver, $this->availableDrivers(), true)) {
            throw new InstallException('This database type is not available in your PHP installation.');
        }
        if ($driver === 'sqlite') {
            $file = trim($in['sqlite_path'] ?? '');
            $default = $file === '' || $file === 'storage/database.sqlite';
            $abs = $default ? $this->path('storage/database.sqlite') : $file;
            if (!str_starts_with($abs, '/') && !preg_match('#^[A-Za-z]:[\\\\/]#', $abs)) {
                $abs = $this->path($abs);
            }
            if (!is_writable(dirname($abs))) {
                throw new InstallException('The folder ' . dirname($abs) . ' is not writable.');
            }
            $pdo = $this->sqlite($abs);
            $this->assertEmpty($pdo);
            return ['driver' => 'sqlite', 'sqlite_path' => $default ? '' : $abs];
        }

        $cfg = [
            'driver' => 'mysql',
            'host' => trim($in['host'] ?? '') ?: 'localhost',
            'port' => trim($in['port'] ?? '') ?: '3306',
            'database' => trim($in['database'] ?? ''),
            'username' => trim($in['username'] ?? ''),
            'password' => (string) ($in['password'] ?? ''),
        ];
        if (!preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $cfg['database'])) {
            throw new InstallException('Database name may contain only letters, numbers, - and _.');
        }
        if ($cfg['username'] === '') {
            throw new InstallException('Database username is required.');
        }
        if (!ctype_digit($cfg['port'])) {
            throw new InstallException('Port must be a number.');
        }
        foreach ($cfg as $v) {
            if (str_contains($v, "\n") || str_contains($v, "\r")) {
                throw new InstallException('Database settings cannot contain line breaks.');
            }
        }
        try {
            $server = new PDO("mysql:host={$cfg['host']};port={$cfg['port']};charset=utf8mb4", $cfg['username'], $cfg['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        } catch (Throwable $e) {
            $msg = $this->cleanError($e->getMessage(), $cfg['password']);
            if (stripos($msg, 'denied') !== false) {
                $msg .= ' — On shared hosting the database name and username usually start with your account prefix (e.g. cpuser_crm), '
                    . 'and the user must be added to the database with ALL PRIVILEGES (cPanel → MySQL Databases → Add User To Database). Host is usually "localhost".';
            }
            throw new InstallException('Could not connect to MySQL: ' . $msg);
        }
        $version = (string) $server->query('SELECT VERSION()')->fetchColumn();
        $isMaria = stripos($version, 'mariadb') !== false;
        if (!$isMaria && version_compare($version, '5.7.8', '<')) {
            throw new InstallException("MySQL 5.7.8 or newer is required (server reports $version). MySQL 8 is recommended.");
        }
        if ($isMaria && version_compare($version, '10.3.0', '<')) {
            throw new InstallException("MariaDB 10.3 or newer is required (server reports $version).");
        }
        try {
            $server->exec('CREATE DATABASE IF NOT EXISTS `' . $cfg['database'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (Throwable) {
            // No CREATE privilege: fine if the database already exists (checked next).
        }
        try {
            $pdo = new PDO("mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['database']};charset=utf8mb4", $cfg['username'], $cfg['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (Throwable $e) {
            throw new InstallException("Connected, but cannot open database \"{$cfg['database']}\". Create it in your hosting panel first. ("
                . $this->cleanError($e->getMessage(), $cfg['password']) . ')');
        }
        $this->assertEmpty($pdo);
        return $cfg;
    }

    private function sqlite(string $file): PDO
    {
        try {
            return new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (Throwable $e) {
            throw new InstallException('Could not open SQLite database: ' . $e->getMessage());
        }
    }

    /** Refuse to install over an existing installation's data. */
    private function assertEmpty(PDO $pdo): void
    {
        try {
            $users = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        } catch (Throwable) {
            return; // no users table → empty
        }
        if ((int) $users > 0) {
            throw new InstallException('This database already contains WhatsApp CRM users. Use a new, empty database (the installer never overwrites existing data).');
        }
    }

    private function cleanError(string $msg, string $password): string
    {
        $msg = $password !== '' ? str_replace($password, '***', $msg) : $msg;
        return mb_substr(preg_replace('/^SQLSTATE\[[^\]]+\]\s*(\[\d+\]\s*)?/', '', $msg) ?? $msg, 0, 300);
    }

    /**
     * Validates the application/admin form.
     * @param array<string,mixed> $in
     * @return array{0:array<string,string>,1:array<string,string>} [clean, errors]
     */
    public function validateApp(array $in): array
    {
        $s = static fn (string $k) => trim((string) ($in[$k] ?? ''));
        $c = [
            'app_name' => $s('app_name') ?: 'WhatsApp CRM',
            'app_url' => rtrim($s('app_url'), '/'),
            'country_code' => ltrim($s('country_code'), '+'),
            'timezone' => $s('timezone') ?: 'UTC',
            'company' => $s('company'),
            'owner_name' => $s('owner_name'),
            'owner_email' => strtolower($s('owner_email')),
            'owner_password' => (string) ($in['owner_password'] ?? ''),
            'admin_email' => strtolower($s('admin_email')),
            'admin_password' => (string) ($in['admin_password'] ?? ''),
            'allow_registration' => !empty($in['allow_registration']) ? 'true' : 'false',
            'hosting' => $s('hosting'),
        ];
        $e = [];
        if ($c['hosting'] === '') {
            $c['hosting'] = self::isLocalUrl($c['app_url']) ? 'local' : 'shared';
        }
        if (!in_array($c['hosting'], ['shared', 'vps', 'local'], true)) {
            $e['hosting'] = 'Choose where you are installing.';
        }
        if (mb_strlen($c['app_name']) > 60 || preg_match('/["\r\n]/', $c['app_name'])) {
            $e['app_name'] = 'Use up to 60 characters, without quotes.';
        }
        if (!filter_var($c['app_url'], FILTER_VALIDATE_URL) || !preg_match('#^https?://[^/\s]+(/[^\s]*)?$#i', $c['app_url'])) {
            $e['app_url'] = 'Enter the full address, e.g. https://crm.yourcompany.com or http://localhost:8000';
        }
        if (!preg_match('/^\d{1,4}$/', $c['country_code'])) {
            $e['country_code'] = 'Enter a calling code such as 91, 1 or 44.';
        }
        if (!in_array($c['timezone'], \DateTimeZone::listIdentifiers(), true)) {
            $e['timezone'] = 'Unknown time zone.';
        }
        if ($c['company'] === '' || mb_strlen($c['company']) > 160) {
            $e['company'] = 'Company name is required.';
        }
        if ($c['owner_name'] === '' || mb_strlen($c['owner_name']) > 160) {
            $e['owner_name'] = 'Your name is required.';
        }
        foreach (['owner_email', 'admin_email'] as $k) {
            if (!filter_var($c[$k], FILTER_VALIDATE_EMAIL)) {
                $e[$k] = 'Enter a valid email address.';
            }
        }
        if (!isset($e['owner_email']) && $c['owner_email'] === $c['admin_email']) {
            $e['admin_email'] = 'Use a different email from the company owner (the platform admin is a separate account).';
        }
        if (($err = PasswordPolicy::check($c['owner_password'], $c['owner_email'])) !== null) {
            $e['owner_password'] = $err;
        } elseif ($c['owner_password'] !== (string) ($in['owner_password_confirmation'] ?? $c['owner_password'])) {
            $e['owner_password'] = 'Passwords do not match.';
        }
        if (($err = PasswordPolicy::check($c['admin_password'], $c['admin_email'])) !== null) {
            $e['admin_password'] = $err;
        } elseif ($c['admin_password'] !== (string) ($in['admin_password_confirmation'] ?? $c['admin_password'])) {
            $e['admin_password'] = 'Passwords do not match.';
        }
        return [$c, $e];
    }

    public static function isLocalUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        return in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true) || str_ends_with($host, '.test') || str_ends_with($host, '.local');
    }

    /**
     * Performs the installation. Order: config in memory → migrate → users (transaction)
     * → write .env → lock. Nothing is written to .env unless the database work succeeded.
     * @param array<string,string> $db  output of checkDatabase()
     * @param array<string,string> $app output of validateApp()
     */
    public function install(array $db, array $app, ?bool $requestSecure = null): void
    {
        if ($this->isInstalled()) {
            throw new InstallException('The application is already installed.');
        }
        $local = self::isLocalUrl($app['app_url']);
        $https = str_starts_with(strtolower($app['app_url']), 'https://');
        $hosting = $app['hosting'] ?? ($local ? 'local' : 'shared');
        // Only force https when this server reports https itself; otherwise (e.g. Cloudflare "Flexible" SSL)
        // forcing it would cause an endless redirect.
        $force = $https && $requestSecure !== false;
        $values = [
            'APP_NAME' => $app['app_name'],
            'APP_URL' => $app['app_url'],
            'APP_KEY' => Crypto::generateKey(),
            'APP_DEBUG' => $local ? 'true' : 'false',
            'APP_FORCE_HTTPS' => $force ? 'true' : 'false',
            'APP_TIMEZONE' => $app['timezone'],
            'ALLOW_REGISTRATION' => $app['allow_registration'],
            'DB_CONNECTION' => $db['driver'],
            'DB_HOST' => $db['host'] ?? '',
            'DB_PORT' => $db['port'] ?? '',
            'DB_DATABASE' => $db['driver'] === 'sqlite' ? ($db['sqlite_path'] ?? '') : ($db['database'] ?? ''),
            'DB_USERNAME' => $db['username'] ?? '',
            'DB_PASSWORD' => $db['password'] ?? '',
            'SESSION_SECURE_COOKIE' => $https ? 'true' : 'false',
            'MAIL_DRIVER' => $local ? 'file' : 'mail',
            'MAIL_FROM' => 'no-reply@' . ((string) parse_url($app['app_url'], PHP_URL_HOST) ?: 'localhost'),
            'DEFAULT_COUNTRY_CODE' => $app['country_code'],
            // shared hosting: no permanent worker — jobs run from cron and right after web requests.
            'QUEUE_MODE' => $hosting === 'shared' ? 'inline' : 'worker',
            'CRON_KEY' => random_token(24),
        ];
        foreach ($values as $k => $v) {
            if (str_contains($v, "\n") || str_contains($v, "\r")) {
                throw new InstallException("Invalid value for $k.");
            }
            Env::set($k, $v);
        }
        if ($db['driver'] === 'sqlite' && ($db['sqlite_path'] ?? '') === '') {
            Env::set('DB_DATABASE', $this->path('storage/database.sqlite'));
        }

        try {
            $conn = Database::fromEnv();
            Database::setConnection($conn);
            (new Migrator($conn, $this->path('database/migrations')))->migrate();
            TenantProvisioner::syncPermissions($conn);
            self::seedPlans($conn);
            $conn->transaction(function () use ($conn, $app): void {
                if ((int) $conn->scalar('SELECT COUNT(*) FROM users') > 0) {
                    throw new InstallException('This database already contains users.');
                }
                $conn->insert('users', [
                    'tenant_id' => null, 'role_id' => null, 'name' => 'Platform Admin', 'email' => $app['admin_email'],
                    'password_hash' => PasswordPolicy::hash($app['admin_password']), 'is_super_admin' => 1, 'status' => 'active',
                    'email_verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                $planId = $conn->scalar("SELECT id FROM subscription_plans WHERE code = 'growth'");
                (new TenantProvisioner($conn))->provision($app['company'], $app['owner_name'], $app['owner_email'], $app['owner_password'],
                    $planId !== null ? (int) $planId : null);
                $conn->insert('audit_logs', ['tenant_id' => null, 'user_id' => null, 'action' => 'platform.installed',
                    'meta' => json_encode(['version' => '1.0']), 'created_at' => now()]);
            });
        } catch (InstallException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new InstallException('Database setup failed: ' . $this->cleanError($e->getMessage(), $values['DB_PASSWORD']));
        }

        $this->writeEnv($values);
        file_put_contents($this->lockFile(), 'Installed ' . gmdate('c') . PHP_EOL);
        @unlink($this->path('storage/install-key.txt'));
    }

    /** @param array<string,string> $values */
    public function writeEnv(array $values): void
    {
        $template = is_file($this->path('.env.example')) ? (string) file_get_contents($this->path('.env.example')) : '';
        $lines = $template === '' ? [] : explode("\n", str_replace("\r\n", "\n", $template));
        $done = [];
        foreach ($lines as $i => $line) {
            if (preg_match('/^([A-Z_]+)=/', $line, $m) && array_key_exists($m[1], $values)) {
                $lines[$i] = $m[1] . '=' . self::envValue($values[$m[1]]);
                $done[$m[1]] = true;
            }
        }
        foreach ($values as $k => $v) {
            if (!isset($done[$k])) {
                $lines[] = $k . '=' . self::envValue($v);
            }
        }
        $file = $this->path('.env');
        if (file_put_contents($file, rtrim(implode("\n", $lines)) . "\n", LOCK_EX) === false) {
            throw new InstallException('Could not write the .env file.');
        }
        @chmod($file, 0640);
    }

    public static function envValue(string $v): string
    {
        return ($v === '' || preg_match('/^[A-Za-z0-9_.:\/@+=\-]+$/', $v)) ? $v : '"' . $v . '"';
    }

    public static function seedPlans(Database $db): void
    {
        // Prices are left at 0: set real prices in the super admin panel.
        foreach ([['starter', 'Starter', 3, 1, 5000], ['growth', 'Growth', 15, 3, 50000], ['scale', 'Scale', null, null, null]] as [$code, $name, $agents, $accounts, $contacts]) {
            if ($db->scalar('SELECT 1 FROM subscription_plans WHERE code = ?', [$code]) === null) {
                $db->insert('subscription_plans', ['code' => $code, 'name' => $name, 'price_cents' => 0, 'currency' => 'USD',
                    'billing_interval' => 'month', 'max_agents' => $agents, 'max_whatsapp_accounts' => $accounts,
                    'max_contacts' => $contacts, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    /**
     * One-time key that a remote (non-localhost) visitor must enter to use the installer,
     * proving they control the server's files. Stored in storage/install-key.txt.
     */
    public function installKey(): string
    {
        $file = $this->path('storage/install-key.txt');
        if (!is_file($file)) {
            file_put_contents($file, strtoupper(bin2hex(random_bytes(6))) . PHP_EOL, LOCK_EX);
            @chmod($file, 0600);
        }
        return trim((string) file_get_contents($file));
    }
}
