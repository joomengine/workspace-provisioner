<?php

declare(strict_types=1);

if (PHP_VERSION_ID < 80300) {
    throw new RuntimeException('PHP 8.3 or later is required.');
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'JoomEngine\\Workspace\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    if (!preg_match('/^[A-Za-z0-9_\\\\]+$/D', $relative)) {
        return;
    }
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
