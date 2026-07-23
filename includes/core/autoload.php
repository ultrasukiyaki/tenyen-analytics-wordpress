<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Tenyen\\Analytics\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

$autoloadCandidates = [
    dirname(__DIR__, 2) . '/vendor/autoload.php',
    dirname(__DIR__) . '/vendor/autoload.php',
    __DIR__ . '/vendor/autoload.php',
];

foreach ($autoloadCandidates as $autoload) {
    if (!is_file($autoload)) continue;
    try {
        require_once $autoload;
    } catch (\Throwable $e) {
        error_log('[Tenyen Analytics] Optional Composer autoloader was ignored: ' . $e->getMessage());
    }
    break;
}
