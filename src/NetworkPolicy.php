<?php

declare(strict_types=1);

namespace JoomEngine\Workspace;

final class NetworkPolicy
{
    public const DENIED = ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8',
        '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.168.0.0/16',
        '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'];

    public static function acl(array $host, array $allHosts, string $owner): array
    {
        $denied = self::DENIED;
        foreach ($allHosts as $other) {
            $denied = [...$denied, $other['bridge_address'], ...$other['denied']];
        }
        $egress = [];
        foreach (array_unique($denied) as $cidr) {
            $egress[] = ['action' => 'drop', 'state' => 'enabled', 'destination' => $cidr];
        }
        $egress[] = ['action' => 'drop', 'state' => 'enabled', 'destination' => '::/0'];
        $egress[] = ['action' => 'allow', 'state' => 'enabled', 'destination' => '0.0.0.0/0',
            'protocol' => 'tcp', 'destination_port' => '22,80,443'];
        foreach (['tcp', 'udp'] as $protocol) {
            $egress[] = ['action' => 'allow', 'state' => 'enabled', 'destination' => implode(',', $host['dns']),
                'protocol' => $protocol, 'destination_port' => '53'];
        }
        $ingress = [['action' => 'drop', 'state' => 'enabled', 'source' => '::/0']];
        foreach ($host['ingress'] as $source) {
            $ingress[] = ['action' => 'allow', 'state' => 'enabled', 'source' => $source,
                'protocol' => 'tcp', 'destination_port' => '80,2222'];
        }
        return ['description' => 'Workspace isolation policy', 'config' => ['user.wp.owner' => $owner],
            'ingress' => $ingress, 'egress' => $egress];
    }

    public static function nic(array $host, array $workspace): array
    {
        $hex = str_replace('-', '', $workspace['id']);
        return ['type' => 'nic', 'network' => $host['network'], 'name' => 'eth0',
            'hwaddr' => '02:' . implode(':', str_split(substr(hash('sha256', $hex), 0, 10), 2)),
            'ipv4.address' => $workspace['address'], 'ipv6.address' => 'none',
            'security.mac_filtering' => 'true', 'security.ipv4_filtering' => 'true',
            'security.ipv6_filtering' => 'true', 'security.port_isolation' => 'true',
            'security.acls' => $host['network'] . '-policy',
            'security.acls.default.ingress.action' => 'drop', 'security.acls.default.egress.action' => 'drop',
            'limits.max' => $workspace['resources']['network_mbit'] . 'Mbit'];
    }
}
