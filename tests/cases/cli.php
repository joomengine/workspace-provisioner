<?php

declare(strict_types=1);

use JoomEngine\Workspace\{Json, Process};

test('CLI is discoverable and rejects unsupported options without infrastructure access', function (): void {
    $process = new Process();
    $binary = dirname(__DIR__, 2) . '/bin/workspace';
    $help = $process->run([PHP_BINARY, $binary, 'help']);
    same(0, $help['exit']);
    same(true, str_contains($help['stdout'], 'worker --config'));
    $bad = $process->run([PHP_BINARY, $binary, 'submit', '--root', 'yes']);
    same(1, $bad['exit']);
    same('invalid_option', Json::decode($bad['stderr'])['error']);
});
