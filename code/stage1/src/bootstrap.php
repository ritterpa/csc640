<?php
/**
 * Loads classes from src/ (namespace InfraLogging\) and turns PHP warnings
 * into exceptions, so problems surface as 500 errors instead of bad output.
 */
declare(strict_types=1);

date_default_timezone_set('UTC');

spl_autoload_register(function (string $class): void {
    $prefix = 'InfraLogging\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
