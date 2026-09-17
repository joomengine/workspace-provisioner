#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use JoomEngine\Workspace\{Config, Fault, Files, WorkerService};

try {
    if ($argc !== 4) { throw new Fault('usage', 'Usage: php tools/worker-service.php PRIVATE_CONFIG SYSTEM_USER NEW_UNIT_FILE'); }
    if (file_exists($argv[3]) || is_link($argv[3])) { throw new Fault('already_exists', 'Use a new unit output file.'); }
    $config = Config::load($argv[1]);
    Files::write($argv[3], WorkerService::render($config, $argv[1], dirname(__DIR__), PHP_BINARY, $argv[2]));
    echo "Service definition written; no host service was installed or started.\n";
} catch (Throwable $error) {
    fwrite(STDERR, ($error instanceof Fault ? $error->getMessage() : 'Service generation failed.') . "\n");
    exit(1);
}
