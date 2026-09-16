<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use JoomEngine\Workspace\{Compose, Files, Json, Validate};

if ($argc !== 6) { fwrite(STDERR, "Usage: render-compose.php PRIVATE_TEST_DIRECTORY JCB_IMAGE DATABASE_IMAGE LOCAL_DEVELOPMENT_IMAGE bootstrap|normal\n"); exit(2); }
$directory = Validate::path($argv[1]);
if (!str_contains(basename($directory), 'wp-container-') || !in_array($argv[5], ['bootstrap', 'normal'], true)) { exit(2); }
$catalog = ['jcb_image' => Validate::image($argv[2]), 'database_image' => Validate::image($argv[3]),
    'development_image' => Validate::text($argv[4]), 'uid' => 33, 'gid' => 33];
$workspace = ['address' => '127.0.0.1', 'admin_email' => 'developer@example.test', 'resources' => ['memory_mib' => 4096]];
$definition = Compose::render($workspace, $catalog, $argv[5] === 'bootstrap');
foreach ($definition['services'] as $name => &$service) {
    foreach ($service['volumes'] as &$mount) {
        $mount['source'] = str_replace(['/opt/jcb-workspace', '/srv/jcb'], [$directory . '/control', $directory . '/data'], $mount['source']);
    }
    unset($mount);
    if ($name === 'web') { $service['ports'] = ['127.0.0.1:18080:80']; }
    if ($name === 'development') { $service['ports'] = ['127.0.0.1:12222:2222']; }
}
unset($service);
foreach ($definition['secrets'] as &$secret) { $secret['file'] = str_replace('/opt/jcb-workspace', $directory . '/control', $secret['file']); }
unset($secret);
if ($argv[5] === 'bootstrap') {
    foreach (['database_password', 'database_root_password', 'admin_password'] as $name) {
        Files::write($directory . '/control/secrets/' . $name, bin2hex(random_bytes(24)), str_starts_with($name, 'database_') ? 0644 : 0600);
    }
    Files::write($directory . '/control/secrets/admin_username', 'workspacebuilder');
}
echo Json::encode($definition);
