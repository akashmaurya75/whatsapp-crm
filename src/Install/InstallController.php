<?php

declare(strict_types=1);

namespace App\Install;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

/**
 * Browser installer. Runs only while the app is NOT installed (no DB or .env needed).
 * Security: CSRF token per session; visitors not on the server itself must enter the
 * one-time key from storage/install-key.txt; it disables itself after installing.
 */
final class InstallController
{
    private View $view;

    public function __construct(private Installer $installer, private Session $session)
    {
        $this->view = new View(base_path('views'));
    }

    private Request $request;

    public function handle(Request $request): Response
    {
        $this->request = $request;
        $path = $request->path;
        $method = $request->method;

        if ($this->installer->isInstalled()) {
            if ($path === '/install/done' && $this->session->get('install.done')) {
                return $this->done();
            }
            return Response::redirect('/login');
        }
        if (!str_starts_with($path, '/install')) {
            return Response::redirect('/install');
        }
        if ($method === 'POST') {
            $sent = (string) ($request->post['_token'] ?? '');
            if ($sent === '' || !hash_equals($this->csrf(), $sent)) {
                return $this->page('install/welcome', ['error' => 'Your session expired. Please try again.'] + $this->welcomeData($request), 419);
            }
        }
        if (!$this->unlocked($request)) {
            if ($path === '/install/unlock' && $method === 'POST') {
                return $this->unlock($request);
            }
            $this->installer->installKey(); // make sure storage/install-key.txt exists
            return $this->page('install/unlock', ['error' => null]);
        }
        return match ([$method, $path]) {
            ['GET', '/install'] => $this->page('install/welcome', $this->welcomeData($request)),
            ['GET', '/install/database'] => $this->databaseForm(),
            ['POST', '/install/database'] => $this->saveDatabase($request),
            ['GET', '/install/app'] => $this->appForm($request),
            ['POST', '/install/app'] => $this->runInstall($request),
            default => Response::redirect('/install'),
        };
    }

    private function csrf(): string
    {
        $t = $this->session->get('install.csrf');
        if (!is_string($t) || $t === '') {
            $t = random_token(32);
            $this->session->put('install.csrf', $t);
        }
        return $t;
    }

    private function page(string $view, array $data = [], int $status = 200): Response
    {
        $html = $this->view->render($view, $data + ['csrf' => $this->csrf(), 'step' => $data['step'] ?? 1]);
        return (new Response($html, $status, ['Content-Type' => 'text/html; charset=utf-8']))
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
    }

    // ----- access gate for remote installs -----

    private static function isLocalRequest(Request $request): bool
    {
        return in_array((string) ($request->server['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true);
    }

    private function unlocked(Request $request): bool
    {
        return self::isLocalRequest($request) || $this->session->get('install.unlocked') === true;
    }

    private function unlock(Request $request): Response
    {
        $key = $this->installer->installKey();
        $given = strtoupper(preg_replace('/\s+/', '', (string) ($request->post['install_key'] ?? '')) ?? '');
        if ($given !== '' && hash_equals($key, $given)) {
            $this->session->regenerate();
            $this->session->put('install.unlocked', true);
            return Response::redirect('/install');
        }
        sleep(1); // slow down guessing
        return $this->page('install/unlock', ['error' => 'That key is not correct.'], 403);
    }

    // ----- step 1 -----

    private function welcomeData(Request $request): array
    {
        $checks = array_merge($this->installer->requirements(), $this->webChecks($request, true));
        $ok = true;
        foreach ($checks as $c) {
            $ok = $ok && (!$c['required'] || $c['ok']);
        }
        $this->session->put('install.web_ok', $ok);
        return ['step' => 1, 'checks' => $checks, 'ok' => $ok, 'server' => self::serverInfo($request)];
    }

    /** @return list<array{label:string,ok:bool,required:bool,help:string}> */
    private function webChecks(Request $request, bool $probe): array
    {
        $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $request->header('host')) ?: 'localhost';
        $origin = ($request->isSecure() ? 'https' : 'http') . '://' . $host;
        return $this->installer->webChecks(
            (string) ($request->server['DOCUMENT_ROOT'] ?? ''),
            (string) ($request->server['SCRIPT_NAME'] ?? '/index.php'),
            $probe && !self::isLocalRequest($request) ? [Installer::class, 'httpGet'] : null,
            $origin
        );
    }

    /** Requirements met and the step-1 web checks passed (re-checks the cheap ones). */
    private function ready(Request $request): bool
    {
        if (!$this->installer->requirementsMet() || $this->session->get('install.web_ok') !== true) {
            return false;
        }
        foreach ($this->webChecks($request, false) as $c) {
            if ($c['required'] && !$c['ok']) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string,string> */
    private static function serverInfo(Request $request): array
    {
        $sw = (string) ($request->server['SERVER_SOFTWARE'] ?? '');
        return [
            'PHP' => PHP_VERSION . ' (' . PHP_SAPI . ')',
            'Web server' => $sw !== '' ? mb_substr(preg_replace('/\s.*$/', '', $sw) ?? $sw, 0, 40) : 'unknown',
            'App folder' => base_path(),
        ];
    }

    // ----- step 2 -----

    private function databaseForm(array $old = [], ?string $error = null, int $status = 200): Response
    {
        if (!$this->ready($this->request)) {
            return Response::redirect('/install');
        }
        $saved = (array) $this->session->get('install.db', []);
        unset($saved['password']);
        $drivers = $this->installer->availableDrivers();
        return $this->page('install/database', [
            'step' => 2, 'drivers' => $drivers, 'error' => $error,
            'old' => $old + $saved + ['driver' => in_array('mysql', $drivers, true) ? 'mysql' : 'sqlite', 'host' => 'localhost', 'port' => '3306', 'database' => 'whatsapp_crm'],
        ], $status);
    }

    private function saveDatabase(Request $request): Response
    {
        if (!$this->ready($request)) {
            return Response::redirect('/install');
        }
        $in = array_map(static fn ($v) => is_string($v) ? $v : '', array_intersect_key($request->post, array_flip(['driver', 'host', 'port', 'database', 'username', 'password', 'sqlite_path'])));
        try {
            $cfg = $this->installer->checkDatabase($in);
        } catch (InstallException $e) {
            unset($in['password']);
            return $this->databaseForm($in, $e->getMessage(), 422);
        }
        $this->session->put('install.db', $cfg);
        return Response::redirect('/install/app');
    }

    // ----- step 3 -----

    private function appForm(Request $request, array $old = [], array $errors = [], ?string $error = null, int $status = 200): Response
    {
        if (!$this->session->get('install.db')) {
            return Response::redirect('/install/database');
        }
        $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $request->header('host')) ?: 'localhost';
        $guessUrl = ($request->isSecure() ? 'https' : 'http') . '://' . $host;
        foreach (array_keys($old) as $k) {
            if (str_contains((string) $k, 'password')) {
                unset($old[$k]);
            }
        }
        return $this->page('install/app', [
            'step' => 3, 'errors' => $errors, 'error' => $error,
            'old' => $old + ['hosting' => self::isLocalRequest($request) || Installer::isLocalUrl($guessUrl) ? 'local' : 'shared',
                'app_name' => 'WhatsApp CRM', 'app_url' => $guessUrl, 'country_code' => (string) env('DEFAULT_COUNTRY_CODE', '91'), 'timezone' => 'UTC'],
            'timezones' => \DateTimeZone::listIdentifiers(),
            'minPassword' => max(8, (int) env('PASSWORD_MIN_LENGTH', 12)),
        ], $status);
    }

    private function runInstall(Request $request): Response
    {
        $db = $this->session->get('install.db');
        if (!is_array($db)) {
            return Response::redirect('/install/database');
        }
        [$app, $errors] = $this->installer->validateApp($request->post);
        if ($errors !== []) {
            return $this->appForm($request, $request->post, $errors, null, 422);
        }
        try {
            // Re-check: the database could have changed since step 2.
            $db = $this->installer->checkDatabase($db);
            $this->installer->install($db, $app, $request->isSecure());
        } catch (InstallException $e) {
            return $this->appForm($request, $request->post, [], $e->getMessage(), 422);
        }
        $this->session->forget('install.db');
        $this->session->put('install.done', [
            'url' => $app['app_url'], 'owner' => $app['owner_email'], 'admin' => $app['admin_email'],
            'local' => Installer::isLocalUrl($app['app_url']), 'path' => base_path(), 'hosting' => $app['hosting'],
            'cron_key' => (string) env('CRON_KEY', ''), 'php' => Installer::phpCliCandidates(),
            'forced_https_off' => str_starts_with($app['app_url'], 'https://') && !$request->isSecure(),
        ]);
        return Response::redirect('/install/done');
    }

    private function done(): Response
    {
        return $this->page('install/done', ['step' => 4, 'info' => (array) $this->session->get('install.done')]);
    }
}
