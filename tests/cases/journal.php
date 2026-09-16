<?php

declare(strict_types=1);

use JoomEngine\Workspace\{Config, FileStore, Journal, Json, NetworkPolicy, Request};

function fixtureConfig(string $directory): Config
{
    return new Config(['version' => 1, 'installation_id' => 'ec2e1dc4-cf57-4059-a1de-77c121b7de91',
        'state' => ['driver' => 'file', 'path' => $directory . '/state.json'],
        'vault' => ['directory' => $directory, 'key_file' => $directory . '/key'],
        'incus' => ['binary' => '/usr/bin/incus', 'config_directory' => $directory],
        'recipe_secrets' => $directory, 'backup_directory' => $directory,
        'hosts' => ['lab' => ['remote' => 'local', 'project' => 'jcb-test', 'network' => 'jcb-test', 'storage' => 'default',
            'bridge_address' => '10.77.0.1/24', 'addresses' => ['10.77.0.10', '10.77.0.11'],
            'ingress' => ['192.0.2.1/32'], 'dns' => ['1.1.1.1'], 'denied' => [],
            'cpu_budget' => 4, 'memory_mib_budget' => 8192, 'disk_gib_budget' => 80]],
        'profiles' => ['small' => ['cpu' => 2, 'memory_mib' => 2048, 'disk_gib' => 20, 'network_mbit' => 10, 'io_mib' => 20]],
        'catalog' => ['standard' => ['vm_image' => str_repeat('a', 64), 'uid' => 33, 'gid' => 33,
            'jcb_image' => 'test/jcb@sha256:' . str_repeat('a', 64),
            'database_image' => 'test/db@sha256:' . str_repeat('b', 64),
            'development_image' => 'test/dev@sha256:' . str_repeat('c', 64)]],
        'callers' => ['operator' => ['tenants' => ['test'], 'actions' => [...Request::ACTIONS, 'status', 'credentials', 'retry']]]]);
}
test('journal atomically reserves, deduplicates and enforces caller scope', function (): void {
    $dir = sys_get_temp_dir() . '/jcb-journal-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    try {
        $cfg = fixtureConfig($dir);
        $j = new Journal(new FileStore($dir . '/state.json'), $cfg);
        $r = fixtureRequest();
        $op = $j->submit('operator', new Request($r));
        same($op['id'], $j->submit('operator', new Request($r))['id']);
        $r['name'] = 'other';
        rejects(fn () => $j->submit('operator', new Request($r)), 'idempotency_conflict');
        rejects(fn () => $j->submit('unknown', new Request($r)), 'forbidden');
        $r = fixtureRequest(); $r['name'] = 'second';
        $j->submit('operator', new Request($r));
        $r = fixtureRequest(); $r['name'] = 'third';
        rejects(fn () => $j->submit('operator', new Request($r)), 'capacity_exhausted');
        same(2, count($j->pending()));
    } finally {
        foreach (glob($dir . '/*') as $file) { unlink($file); }
        rmdir($dir);
    }
});
test('network policy enforces per-NIC limits and denies IPv6/private destinations', function (): void {
    $cfg = fixtureConfig('/tmp');
    $host = $cfg->data['hosts']['lab'];
    $acl = NetworkPolicy::acl($host, $cfg->data['hosts'], $cfg->data['installation_id']);
    same(true, in_array('::/0', array_column($acl['egress'], 'destination'), true));
    same(true, in_array('169.254.0.0/16', array_column($acl['egress'], 'destination'), true));
    $nic = NetworkPolicy::nic($host, ['id' => Json::uuid(), 'address' => '10.77.0.10', 'resources' => ['network_mbit' => 10]]);
    same('true', $nic['security.port_isolation']);
    same('drop', $nic['security.acls.default.egress.action']);
    same('none', $nic['ipv6.address']);
});
