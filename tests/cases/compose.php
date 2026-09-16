<?php

declare(strict_types=1);

use JoomEngine\Workspace\Compose;

test('workload definitions isolate installation, secrets, files and SSH authority', function (): void {
    $config = fixtureConfig('/tmp');
    $workspace = ['address' => '10.77.0.10', 'admin_email' => 'a@example.test', 'resources' => ['memory_mib' => 4096]];
    $normal = Compose::render($workspace, $config->data['catalog']['standard']);
    same(['apache2-foreground'], $normal['services']['web']['entrypoint']);
    same(false, isset($normal['services']['installer']));
    same(false, isset($normal['secrets']['admin-password']));
    same(false, isset($normal['services']['database']['ports']));
    $dev = $normal['services']['development'];
    same(['ALL'], $dev['cap_drop']);
    same(true, $dev['read_only']);
    same(false, isset($dev['privileged']));
    foreach ($dev['volumes'] as $mount) {
        same(false, str_contains($mount['source'], 'docker.sock'));
        same(false, $mount['source'] === '/');
        same(false, $mount['bind']['create_host_path']);
    }
    $initial = Compose::render($workspace, $config->data['catalog']['standard'], true);
    same('no', $initial['services']['installer']['restart']);
    same(false, isset($initial['services']['installer']['ports']));
    same(true, isset($initial['secrets']['admin-password']));
});
