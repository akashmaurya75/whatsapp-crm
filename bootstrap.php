<?php

declare(strict_types=1);

/*
 * Shared bootstrap for web, CLI and tests.
 * PSR-4 autoloader for the App\ namespace (no Composer dependency).
 */

define('BASE_PATH', __DIR__);

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = BASE_PATH . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require BASE_PATH . '/src/helpers.php';

if (PHP_VERSION_ID < 80300) {
    $msg = 'WhatsApp CRM needs PHP 8.3 or newer. This server runs PHP ' . PHP_VERSION . '.';
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $msg . "\n");
    } else {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo $msg, "\nPlease switch the PHP version in your hosting panel (or install PHP 8.3) and reload.";
    }
    exit(1);
}

App\Core\Env::load(BASE_PATH . '/.env');

date_default_timezone_set((string) App\Core\Env::get('APP_TIMEZONE', 'UTC'));
