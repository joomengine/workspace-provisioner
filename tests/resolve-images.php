<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
use JoomEngine\Workspace\{ImageResolver, Json, Process};
if ($argc !== 4) { exit(2); }
try {
    echo Json::encode((new ImageResolver())->resolve(['jcb_image' => $argv[1], 'database_image' => $argv[2], 'development_image' => $argv[3]]));
} catch (Throwable $error) {
    // These are public CI fixtures with no auth file. Never use this test with private images or credentials.
    if (getenv('GITHUB_ACTIONS') === 'true') {
        foreach (array_slice($argv, 1) as $reference) {
            ImageResolver::selector($reference);
            $result = (new Process())->run(['/usr/bin/skopeo', 'inspect', '--raw', '--tls-verify=true', 'docker://' . $reference], '', 60);
            if ($result['exit'] !== 0) { fwrite(STDERR, 'Public OCI fixture: ' . substr($result['stderr'], 0, 2048) . "\n"); break; }
        }
    }
    throw $error;
}
