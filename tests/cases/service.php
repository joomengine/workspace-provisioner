<?php

declare(strict_types=1);

use JoomEngine\Workspace\WorkerService;

test('worker service separates code and writable state without root or injected directives', function (): void {
    $cfg = fixtureConfig('/var/lib/workspace');
    $unit = WorkerService::render($cfg, '/var/lib/workspace/operator.json', '/opt/workspace/0.1.0', '/usr/bin/php', 'workspace');
    same(true, str_contains($unit, 'NoNewPrivileges=true'));
    same(true, str_contains($unit, 'ProtectSystem=strict'));
    same(true, str_contains($unit, 'User=workspace'));
    same(true, str_contains($unit, 'worker --config /var/lib/workspace/operator.json'));
    rejects(fn () => WorkerService::render($cfg, '/config', '/opt/code', '/usr/bin/php', 'root'), 'invalid_service_user');
    rejects(fn () => WorkerService::render($cfg, '/config%h', '/opt/code', '/usr/bin/php', 'worker'), 'invalid_service_path');
    rejects(fn () => WorkerService::render($cfg, '/config', '/opt/code', '/usr/bin/php', "worker\nUser=root"), 'invalid_service_user');
});
