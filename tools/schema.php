#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use JoomEngine\Workspace\{Fault, Json, Schema};

try {
    if ($argc !== 2) { throw new Fault('usage', 'Usage: php tools/schema.php operator|inventory|catalog|recipe|request'); }
    echo Json::encode(Schema::document($argv[1]));
} catch (Throwable $error) {
    fwrite(STDERR, ($error instanceof Fault ? $error->getMessage() : 'Schema generation failed.') . "\n");
    exit(1);
}
