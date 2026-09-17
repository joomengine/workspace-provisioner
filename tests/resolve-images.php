<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
use JoomEngine\Workspace\{ImageResolver, Json};
if ($argc !== 4) { exit(2); }
echo Json::encode((new ImageResolver())->resolve(['jcb_image' => $argv[1], 'database_image' => $argv[2], 'development_image' => $argv[3]]));
