<?php

declare(strict_types=1);

use App\Core\App;
use App\Core\BackgroundRunner;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Install\InstallController;
use App\Install\Installer;

/*
 * Where the application files live. Normally found automatically:
 *   - the standard layout  app/public/index.php  (web root = public/), or
 *   - the shared-hosting layout  ~/whatsapp-crm/  next to  ~/public_html/  (this file in public_html).
 * If you put the app somewhere else, set the full path here, e.g. '/home/USER/whatsapp-crm'.
 */
$appDir = '';

(static function (string $appDir): void {
    $candidates = $appDir !== '' ? [$appDir] : [dirname(__DIR__)];
    if ($appDir === '') {
        // Shared hosting: public_html/index.php with the app in a sibling folder "whatsapp-crm".
        for ($dir = dirname(__DIR__), $i = 0; $i < 5 && $dir !== dirname($dir); $dir = dirname($dir), $i++) {
            $candidates[] = $dir . '/whatsapp-crm';
        }
    }
    foreach ($candidates as $c) {
        if (@is_file($c . '/bootstrap.php')) {
            require $c . '/bootstrap.php';
            return;
        }
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "WhatsApp CRM: the application folder was not found.\n\n"
        . "Upload the folder \"whatsapp-crm\" next to your web folder (for example /home/USER/whatsapp-crm beside /home/USER/public_html),\n"
        . "or open public_html/index.php and set \$appDir to the full path of the application folder.\n";
    exit;
})($appDir);

$request = Request::fromGlobals();

// First run: hand everything to the web installer until the app is installed.
$installer = new Installer(BASE_PATH);
if (!$installer->isInstalled() || $request->path === '/install/done') {
    try {
        $session = Session::startNative($request->isSecure());
        (new InstallController($installer, $session))->handle($request)->send();
    } catch (Throwable $e) {
        error_log('Installer error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        Response::text("The installer hit an unexpected error:\n" . $e->getMessage(), 500)->send();
    }
    exit;
}

// Force HTTPS in production.
if ((bool) env('APP_FORCE_HTTPS', false) && !$request->isSecure()) {
    $host = (string) parse_url((string) env('APP_URL', ''), PHP_URL_HOST);
    (new Response('', 301, ['Location' => 'https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/')]))->send();
    exit;
}

$router = new Router();
(require BASE_PATH . '/routes.php')($router);

// API, webhooks, health and cron are stateless: no session cookie is created for them.
$stateless = str_starts_with($request->path, '/api/') || str_starts_with($request->path, '/webhooks/')
    || in_array($request->path, ['/healthz', '/cron'], true);
$session = $stateless ? Session::memory() : Session::startNative($request->isSecure() || (bool) env('SESSION_SECURE_COOKIE', false));

try {
    $app = new App(Database::connection(), $session, router: $router);
    $response = $app->handle($request);
} catch (Throwable $e) {
    Logger::error('Bootstrap failure', ['message' => $e->getMessage()]);
    $response = Response::text('Service temporarily unavailable.', 503);
}
$response->send();

// Shared hosting (QUEUE_MODE=inline): process queued messages after the visitor has their page.
if ($request->path !== '/cron') {
    BackgroundRunner::afterResponse();
}
