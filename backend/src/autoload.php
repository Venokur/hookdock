<?php

declare(strict_types=1);

/**
 * Minimal PSR-4 style autoloader for the App\ namespace (no Composer needed).
 *
 * Kept separate from bootstrap.php so CLI daemons (bin/ws-server.php) can load
 * classes without installing the HTTP exception handler that calls exit().
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
