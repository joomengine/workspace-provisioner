<?php

declare(strict_types=1);

use JoomEngine\Workspace\{Fault, Host, IncusApi, IncusTransport, Json};

final class MemoryIncus implements IncusTransport
{
    public array $resources = [];
    public array $writes = [];
    public array $extensions = Host::REQUIRED_EXTENSIONS;
    public function request(string $remote, string $project, string $method, string $path, ?array $body = null): array
    {
        if ($path === '/1.0') {
            return ['metadata' => ['api_extensions' => $this->extensions,
                'environment' => ['driver' => 'lxc | qemu', 'server_version' => 'test', 'server_clustered' => false]]];
        }
        if ($path === '/1.0/storage-pools/default') { return ['metadata' => ['driver' => 'zfs']]; }
        if ($method === 'POST') {
            $this->writes[] = [$project, $path, $body];
            $this->resources[$path . '/' . $body['name']] = $body;
            return ['type' => 'sync', 'metadata' => []];
        }
        if (isset($this->resources[$path])) { return ['metadata' => $this->resources[$path]]; }
        if (in_array($path, ['/1.0/projects', '/1.0/networks', '/1.0/network-acls'], true)) {
            return ['metadata' => array_values(array_filter(array_keys($this->resources),
                static fn (string $key): bool => dirname($key) === $path))];
        }
        throw new Fault('not_found', 'Missing test resource.');
    }
    public function command(string $project, array $arguments, string $stdin = '', int $timeout = 60): array
    {
        return ['exit' => 0, 'stdout' => '--data-file', 'stderr' => ''];
    }
    public function upload(string $remote, string $project, string $instance, string $path, string $content, string $mode = '0600'): void
    {
    }
}

test('host preparation is owned, repeatable and refuses unsafe capabilities', function (): void {
    $config = fixtureConfig('/tmp');
    $transport = new MemoryIncus();
    $host = new Host($config, $transport);
    rejects(fn () => $host->prepare('lab', 'wrong'), 'confirmation_required');
    $host->prepare('lab', $config->data['installation_id']);
    same(4, count($transport->writes));
    $host->prepare('lab', $config->data['installation_id']);
    same(4, count($transport->writes));
    $transport->extensions = [];
    rejects(fn () => $host->preflight('lab'), 'unsupported_incus');
});
test('host conflicts are discovered before any mutation', function (): void {
    $transport = new MemoryIncus();
    $transport->resources['/1.0/projects/jcb-test'] = ['config' => []];
    $config = fixtureConfig('/tmp');
    rejects(fn () => (new Host($config, $transport))->prepare('lab', $config->data['installation_id']), 'ownership_conflict');
    same([], $transport->writes);
    rejects(fn () => IncusApi::owned(['config' => ['user.wp.owner' => 'other']], Json::uuid()), 'ownership_conflict');
});
