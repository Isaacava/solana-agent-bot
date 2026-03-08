<?php
/**
 * Simple PSR-4 autoloader — no Composer required
 */
spl_autoload_register(function (string $class): void {
    $prefix = 'SolanaAgent\\';
    $baseDir = __DIR__ . '/';

    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});
