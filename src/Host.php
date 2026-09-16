<?php

declare(strict_types=1);

namespace JoomEngine\Workspace;

/** Host preparation creates only new owned project/network/ACL resources; never formats storage. */
final readonly class Host
{
    public const REQUIRED_EXTENSIONS = ['virtual-machines', 'network_acl', 'network_bridge_acl',
        'container_nic_ipfilter', 'instance_nic_bridged_port_isolation', 'projects_restrictions',
        'projects_networks_restricted_access'];

    public function __construct(private Config $config, private IncusTransport $transport)
    {
    }

    public function preflight(string $name): array
    {
        $host = $this->config->data['hosts'][$name] ?? throw new Fault('unknown_host', 'Unknown compute host.');
        $api = new IncusApi($this->transport, $host['remote'], 'default');
        $server = $api->get('/1.0');
        if (array_diff(self::REQUIRED_EXTENSIONS, $server['api_extensions'] ?? [])) {
            throw new Fault('unsupported_incus', 'Required Incus VM/network/project capabilities are missing.');
        }
        if (($server['environment']['server_clustered'] ?? false) === true) {
            throw new Fault('unsupported_topology', 'Use independent named compute remotes; clustered placement needs separate qualification.');
        }
        if (!str_contains($server['environment']['driver'] ?? '', 'qemu')) {
            throw new Fault('missing_virtualization', 'Incus does not advertise the QEMU VM driver.');
        }
        $pool = $api->get('/1.0/storage-pools/' . rawurlencode($host['storage']));
        if (!in_array($pool['driver'] ?? '', ['zfs', 'lvm', 'btrfs'], true)) {
            throw new Fault('unsupported_storage', 'A quota-capable ZFS, LVM or Btrfs storage pool is required.');
        }
        $help = $this->transport->command('default', ['query', '--help']);
        if ($help['exit'] !== 0 || !str_contains($help['stdout'], '--data-file')) {
            throw new Fault('unsupported_client', 'The Incus client must support query --data-file.');
        }
        return ['host' => $name, 'server_version' => $server['environment']['server_version'] ?? 'unknown',
            'driver' => $server['environment']['driver'], 'storage_driver' => $pool['driver'], 'capabilities' => self::REQUIRED_EXTENSIONS];
    }

    public function prepare(string $name, string $confirmation): array
    {
        if ($confirmation !== $this->config->data['installation_id']) {
            throw new Fault('confirmation_required', 'Explicit installation-ID confirmation is required for host preparation.');
        }
        $evidence = $this->preflight($name);
        $host = $this->config->data['hosts'][$name];
        $api = new IncusApi($this->transport, $host['remote'], 'default');
        $missing = [];
        // Inspect the entire plan before making any mutation.
        foreach ($this->definitions($host) as [$collection, $resourceName, $definition]) {
            $existing = $api->find($collection, $resourceName);
            if ($existing === null) {
                $missing[] = [$collection, $resourceName, $definition];
            } else {
                IncusApi::owned($existing, $this->config->data['installation_id']);
                $this->matches($existing, $definition);
            }
        }
        foreach ($missing as [$collection, $resourceName, $definition]) {
            $api->mutate('POST', $collection, ['name' => $resourceName, ...$definition]);
        }
        $this->verify($name);
        return $evidence + ['prepared' => true];
    }

    public function verify(string $name): void
    {
        $host = $this->config->data['hosts'][$name] ?? throw new Fault('unknown_host', 'Unknown compute host.');
        $api = new IncusApi($this->transport, $host['remote'], 'default');
        foreach ($this->definitions($host) as [$collection, $resourceName, $definition]) {
            $resource = $api->get($collection . '/' . rawurlencode($resourceName));
            IncusApi::owned($resource, $this->config->data['installation_id']);
            $this->matches($resource, $definition);
        }
        $network = $api->get('/1.0/networks/' . rawurlencode($host['network']));
        if (($network['config']['bridge.external_interfaces'] ?? '') !== '') {
            throw new Fault('unsafe_network', 'Workspace bridge must not attach physical external interfaces.');
        }
    }

    private function definitions(array $host): array
    {
        $owner = $this->config->data['installation_id'];
        $policy = NetworkPolicy::acl($host, $this->config->data['hosts'], $owner);
        $bootstrap = $policy;
        $bootstrap['ingress'] = [];
        return [
            ['/1.0/network-acls', $host['network'] . '-policy', $policy],
            ['/1.0/network-acls', $host['network'] . '-bootstrap', $bootstrap],
            ['/1.0/networks', $host['network'], ['type' => 'bridge', 'description' => 'Isolated workspace bridge',
                'config' => ['user.wp.owner' => $owner, 'ipv4.address' => $host['bridge_address'],
                    'ipv4.nat' => 'true', 'ipv4.firewall' => 'true', 'ipv4.dhcp' => 'false', 'ipv6.address' => 'none',
                    'dns.mode' => 'none', 'raw.dnsmasq' => 'port=0',
                    'security.acls' => $host['network'] . '-bootstrap',
                    'security.acls.default.ingress.action' => 'drop', 'security.acls.default.egress.action' => 'drop']]],
            ['/1.0/projects', $host['project'], ['description' => 'Isolated JCB workspaces',
                'config' => ['user.wp.owner' => $owner, 'features.images' => 'false', 'features.profiles' => 'true',
                    'features.networks' => 'false', 'features.storage.volumes' => 'true', 'restricted' => 'true',
                    'restricted.devices.nic' => 'managed', 'restricted.networks.access' => $host['network'],
                    'restricted.devices.disk' => 'managed', 'restricted.devices.proxy' => 'block',
                    'limits.containers' => '0', 'limits.cpu' => (string) $host['cpu_budget'],
                    'limits.memory' => $host['memory_mib_budget'] . 'MiB',
                    'limits.disk' => $host['disk_gib_budget'] . 'GiB']]],
        ];
    }

    private function matches(array $actual, array $expected): void
    {
        foreach ($expected as $key => $value) {
            if ($key === 'description') { continue; }
            if ($key === 'config') {
                foreach ($value as $option => $required) {
                    if (($actual['config'][$option] ?? null) !== $required) {
                        throw new Fault('policy_drift', 'An owned host resource differs from its approved configuration.');
                    }
                }
            } elseif (in_array($key, ['ingress', 'egress'], true)) {
                $normalize = static function (array $rules): array {
                    return array_map(static fn (array $rule): array => array_filter($rule,
                        static fn ($v): bool => $v !== '' && $v !== null), $rules);
                };
                if (Json::hash($normalize($actual[$key] ?? [])) !== Json::hash($normalize($value))) {
                    throw new Fault('policy_drift', 'An ACL differs from its approved rule set.');
                }
            } elseif (($actual[$key] ?? null) !== $value) {
                throw new Fault('policy_drift', 'An owned host resource has an unexpected type.');
            }
        }
    }
}
