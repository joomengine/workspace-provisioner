<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use JoomEngine\Workspace\{Json, WorkspaceComposer};

$root = dirname(__DIR__);
echo Json::encode(['directory' => 'libraries/workspace-test', 'manifest' => file_get_contents($root . '/composer.json'),
    'lock' => file_get_contents($root . '/composer.lock'), 'dev' => false, 'replace' => false, 'timeout' => 120, 'auth' => []]);
