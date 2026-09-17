<?php

declare(strict_types=1);

use JoomEngine\Workspace\{Compose, Files, Json, WorkspaceComposer};

test('private Composer inputs require exact digests, a lock and confined paths', function (): void {
    $dir = sys_get_temp_dir() . '/workspace-composer-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    try {
        $manifest = '{"name":"example/test","require":{}}';
        $lock = '{"content-hash":"example","packages":[]}';
        Files::write($dir . '/composer.json', $manifest);
        Files::write($dir . '/composer.lock', $lock);
        $config = ['manifest' => $dir . '/composer.json', 'manifest_sha256' => hash('sha256', $manifest),
            'lock' => $dir . '/composer.lock', 'lock_sha256' => hash('sha256', $lock), 'directory' => 'libraries/example'];
        $payload = (new WorkspaceComposer($config))->payload($dir);
        same($manifest, $payload['manifest']);
        same(false, $payload['dev']);
        same([], $payload['auth']);
        foreach (['../escape', '/etc', 'a/../b', 'a//b'] as $path) {
            $bad = $config; $bad['directory'] = $path;
            rejects(fn () => new WorkspaceComposer($bad), 'invalid_composer');
        }
        Files::write($dir . '/composer.lock', '{}');
        rejects(fn () => (new WorkspaceComposer($config))->payload($dir), 'composer_changed');
    } finally { unlink($dir . '/composer.json'); unlink($dir . '/composer.lock'); rmdir($dir); }
});

test('Composer bootstrap has no SSH, elevated user, secrets mount or live service', function (): void {
    $workspace = ['resources' => ['memory_mib' => 4096], 'address' => '192.0.2.10', 'admin_email' => 'test@example.test'];
    $catalog = ['jcb_image' => 'example/web', 'development_image' => 'example/dev', 'database_image' => 'example/db',
        'uid' => 33, 'gid' => 33, 'composer' => []];
    $service = Compose::render($workspace, $catalog, true)['services']['composer'];
    same('33:33', $service['user']);
    same(['ALL'], $service['cap_drop']);
    same(false, isset($service['ports']));
    same(false, isset($service['secrets']));
    same(false, isset(Compose::render($workspace, $catalog)['services']['composer']));
    same(['bootstrap'], $service['profiles']);
});
