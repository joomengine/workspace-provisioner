<?php

declare(strict_types=1);

use JoomEngine\Workspace\{Archive, Files, Json};

test('backup encryption streams and rejects corruption, context mismatch and truncation', function (): void {
    $dir = sys_get_temp_dir() . '/jcb-archive-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    try {
        Files::write($dir . '/key', bin2hex(random_bytes(32)));
        Files::write($dir . '/source', random_bytes(2200000));
        $archive = new Archive($dir . '/key', Json::uuid());
        $archive->seal($dir . '/source', $dir . '/cipher', 'workspace:backup');
        $archive->open($dir . '/cipher', $dir . '/restored', 'workspace:backup');
        same(hash_file('sha256', $dir . '/source'), hash_file('sha256', $dir . '/restored'));
        rejects(fn () => $archive->open($dir . '/cipher', $dir . '/wrong', 'other:backup'), 'invalid_archive');
        same(false, file_exists($dir . '/wrong'));
        $raw = file_get_contents($dir . '/cipher');
        Files::write($dir . '/truncated', substr($raw, 0, -1));
        rejects(fn () => $archive->open($dir . '/truncated', $dir . '/wrong', 'workspace:backup'), 'invalid_archive');
        $raw[80] = chr(ord($raw[80]) ^ 1);
        Files::write($dir . '/tampered', $raw);
        rejects(fn () => $archive->open($dir . '/tampered', $dir . '/wrong', 'workspace:backup'), 'invalid_archive');
        Files::write($dir . '/empty', '');
        $archive->seal($dir . '/empty', $dir . '/empty-cipher', 'empty');
        $archive->open($dir . '/empty-cipher', $dir . '/empty-out', 'empty');
        same('', file_get_contents($dir . '/empty-out'));
    } finally {
        foreach (glob($dir . '/*') as $file) { unlink($file); }
        rmdir($dir);
    }
});
