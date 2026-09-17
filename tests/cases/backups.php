<?php

declare(strict_types=1);

use JoomEngine\Workspace\{BackupStore, Files, Json};

test('backups are encrypted, bound to workspace policy and safe to retry', function (): void {
    $dir = sys_get_temp_dir() . '/jcb-backup-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    try {
        Files::write($dir . '/key', bin2hex(random_bytes(32)));
        $store = new BackupStore($dir, $dir . '/key', Json::uuid());
        $workspace = ['id' => Json::uuid(), 'instance' => 'test', 'host_hash' => str_repeat('a', 64),
            'catalog_hash' => str_repeat('b', 64), 'recipe_hash' => str_repeat('c', 64)];
        $backup = Json::uuid();
        $payload = str_repeat('private-workspace-data', 100);
        $metadata = $store->save($workspace, $backup, static fn (string $path) => Files::write($path, $payload));
        same(true, $metadata['encrypted']);
        same($metadata, $store->save($workspace, $backup, static fn () => throw new RuntimeException('Must not export twice')));
        same($payload, $store->withPlaintext($workspace, $backup, file_get_contents(...)));
        $wrong = $workspace; $wrong['recipe_hash'] = str_repeat('d', 64);
        rejects(fn () => $store->metadata($wrong, $backup), 'backup_mismatch');
        $path = $dir . '/' . $workspace['id'] . '-' . $backup;
        same(false, str_contains(file_get_contents($path . '/payload.enc'), $payload));
        file_put_contents($path . '/payload.enc', 'corrupt', FILE_APPEND);
        rejects(fn () => $store->withPlaintext($workspace, $backup, static fn () => null), 'invalid_backup');
        same([], glob($dir . '/.staging-*'));
    } finally {
        foreach (glob($dir . '/*') as $path) {
            if (is_dir($path)) { foreach (glob($path . '/*') as $file) { unlink($file); } rmdir($path); }
            else { unlink($path); }
        }
        rmdir($dir);
    }
});

test('failed backup export removes its partial plaintext', function (): void {
    $dir = sys_get_temp_dir() . '/jcb-backup-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    try {
        Files::write($dir . '/key', bin2hex(random_bytes(32)));
        $store = new BackupStore($dir, $dir . '/key', Json::uuid());
        try {
            $store->save(['id' => Json::uuid()], Json::uuid(), static function (string $path): void {
                Files::write($path, 'partial sensitive export');
                throw new RuntimeException('injected');
            });
            throw new LogicException('Expected injected failure');
        } catch (RuntimeException $error) { same('injected', $error->getMessage()); }
        same([], glob($dir . '/.staging-*'));
    } finally { unlink($dir . '/key'); rmdir($dir); }
});
