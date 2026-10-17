<?php

declare(strict_types=1);

/*
 * Zero-dependency test runner.
 *   php tests/run.php            run everything
 *   php tests/run.php Webhook    run test classes/methods matching "Webhook"
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/support.php';
require __DIR__ . '/TestCase.php';

$filter = $argv[1] ?? '';
$files = glob(__DIR__ . '/*/*Test.php') ?: [];
sort($files);
$passed = $failed = $assertions = 0;
$failures = [];
$start = microtime(true);

foreach ($files as $file) {
    require_once $file;
    $class = 'Tests\\' . basename(dirname($file)) . '\\' . basename($file, '.php');
    foreach (get_class_methods($class) as $method) {
        if (!str_starts_with($method, 'test')) {
            continue;
        }
        $name = basename($file, '.php') . '::' . $method;
        if ($filter !== '' && stripos($name, $filter) === false) {
            continue;
        }
        /** @var Tests\TestCase $t */
        $t = new $class();
        try {
            $t->setUp();
            $t->{$method}();
            $passed++;
            echo "  \e[32m✓\e[0m $name\n";
        } catch (Throwable $e) {
            $failed++;
            $failures[] = [$name, $e];
            echo "  \e[31m✗\e[0m $name\n";
        }
        $assertions += $t->assertions;
        App\Core\Database::setConnection(null);
    }
}

foreach ($failures as [$name, $e]) {
    echo "\n\e[31mFAIL\e[0m $name\n  " . $e::class . ': ' . $e->getMessage() . "\n  at " . $e->getFile() . ':' . $e->getLine() . "\n";
    foreach (array_slice($e->getTrace(), 0, 4) as $f) {
        echo '     ' . ($f['file'] ?? '?') . ':' . ($f['line'] ?? '?') . "\n";
    }
}
printf("\n%d passed, %d failed, %d assertions in %.2fs\n", $passed, $failed, $assertions, microtime(true) - $start);
exit($failed > 0 ? 1 : 0);
