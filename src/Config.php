<?php

declare(strict_types=1);

namespace JoomEngine\Workspace;

final readonly class Config
{
    public array $data;

    public function __construct(array $data)
    {
        Validate::object($data, ['version', 'installation_id', 'state', 'vault', 'incus', 'hosts',
            'profiles', 'catalog', 'callers', 'recipe_secrets', 'backup_directory']);
        Validate::integer($data['version'], 1, 1);
        Validate::uuid($data['installation_id']);
        Validate::object($data['state'], ['driver'], ['path', 'dsn', 'username', 'password_file']);
        if ($data['state']['driver'] === 'file') {
            Validate::object($data['state'], ['driver', 'path']);
            Validate::path($data['state']['path']);
        } elseif ($data['state']['driver'] === 'pgsql') {
            Validate::object($data['state'], ['driver', 'dsn', 'username', 'password_file']);
            if (!str_starts_with(Validate::text($data['state']['dsn'], 2048), 'pgsql:')) {
                throw new Fault('invalid_store', 'A PostgreSQL PDO DSN is required.');
            }
            Validate::text($data['state']['username']);
            Validate::path($data['state']['password_file']);
        } else {
            throw new Fault('invalid_store', 'Unsupported operation store.');
        }
        Validate::object($data['vault'], ['directory', 'key_file']);
        foreach ($data['vault'] as $path) { Validate::path($path); }
        Validate::path($data['recipe_secrets']);
        Validate::path($data['backup_directory']);
        Validate::object($data['incus'], ['binary', 'config_directory']);
        Validate::path($data['incus']['binary']);
        Validate::path($data['incus']['config_directory']);
        foreach (['hosts', 'profiles', 'catalog', 'callers'] as $section) {
            if (!is_array($data[$section]) || $data[$section] === [] || array_is_list($data[$section])) {
                throw new Fault('invalid_config', 'Nonempty inventory maps are required.');
            }
            foreach (array_keys($data[$section]) as $name) { Validate::name($name); }
        }
        $endpoints = [];
        foreach ($data['hosts'] as $host) {
            Validate::object($host, ['remote', 'project', 'network', 'storage', 'bridge_address', 'addresses',
                'ingress', 'dns', 'denied', 'cpu_budget', 'memory_mib_budget', 'disk_gib_budget']);
            foreach (['remote', 'project', 'network', 'storage'] as $key) { Validate::name($host[$key]); }
            if ($host['project'] === 'default' || strlen($host['network']) > 15) {
                throw new Fault('invalid_config', 'Use a dedicated project and a bridge name of at most 15 characters.');
            }
            $endpoint = $host['remote'] . ':' . $host['project'];
            if (isset($endpoints[$endpoint])) {
                throw new Fault('invalid_config', 'Each host entry must identify a distinct project endpoint.');
            }
            $endpoints[$endpoint] = true;
            [$gateway, $bits] = explode('/', Validate::cidr($host['bridge_address']));
            if ((int) $bits < 16 || (int) $bits > 28) {
                throw new Fault('invalid_config', 'Workspace bridges require a /16 through /28 IPv4 subnet.');
            }
            $addresses = Validate::list($host['addresses'], 1, 4096);
            if (count(array_unique($addresses)) !== count($addresses)) {
                throw new Fault('invalid_config', 'Duplicate pool addresses are not permitted.');
            }
            $network = ip2long($gateway) & ((0xffffffff << (32 - (int) $bits)) & 0xffffffff);
            $broadcast = $network | ((1 << (32 - (int) $bits)) - 1);
            foreach ($addresses as $address) {
                Validate::ip($address);
                if (!Validate::contains($host['bridge_address'], $address)
                    || in_array(ip2long($address), [$network, $broadcast, ip2long($gateway)], true)) {
                    throw new Fault('invalid_config', 'Pool addresses must be usable addresses within the configured bridge.');
                }
            }
            foreach (Validate::list($host['ingress'], 1, 64) as $source) {
                Validate::cidr($source);
                foreach ($addresses as $address) {
                    if (Validate::contains($source, $address)) {
                        throw new Fault('invalid_config', 'Trusted ingress must not contain workspace addresses.');
                    }
                }
            }
            foreach (Validate::list($host['denied'], 0, 128) as $cidr) { Validate::cidr($cidr); }
            foreach (Validate::list($host['dns'], 1, 3) as $dns) {
                Validate::ip($dns);
                foreach ([...NetworkPolicy::DENIED, ...$host['denied'], $host['bridge_address']] as $cidr) {
                    if (Validate::contains($cidr, $dns)) {
                        throw new Fault('invalid_config', 'DNS must use explicitly approved public addresses outside denied ranges.');
                    }
                }
            }
            Validate::integer($host['cpu_budget'], 1, 4096);
            Validate::integer($host['memory_mib_budget'], 1024, 16777216);
            Validate::integer($host['disk_gib_budget'], 10, 1048576);
        }
        foreach ($data['profiles'] as $profile) {
            Validate::object($profile, ['cpu', 'memory_mib', 'disk_gib', 'network_mbit', 'io_mib']);
            Validate::integer($profile['cpu'], 1, 64);
            Validate::integer($profile['memory_mib'], 1024, 262144);
            Validate::integer($profile['disk_gib'], 10, 4096);
            Validate::integer($profile['network_mbit'], 1, 10000);
            Validate::integer($profile['io_mib'], 1, 4096);
        }
        foreach ($data['catalog'] as $entry) {
            Validate::object($entry, ['vm_image', 'jcb_image', 'database_image', 'development_image', 'uid', 'gid'], ['recipe', 'vm_executables']);
            Validate::digest($entry['vm_image']);
            foreach (['jcb_image', 'database_image', 'development_image'] as $key) { Validate::image($entry[$key]); }
            Validate::integer($entry['uid'], 1, 60000);
            Validate::integer($entry['gid'], 1, 60000);
            if (isset($entry['recipe'])) { Validate::path($entry['recipe']); }
            foreach ($entry['vm_executables'] ?? [] as $path => $digest) {
                Validate::path($path);
                Validate::digest($digest);
                if (preg_match('~/(?:ba|da|a|z|fi|c|k)?sh$|/(?:docker|incus|sudo|su|env|python[0-9.]*|php[0-9.]*)$~', $path)) {
                    throw new Fault('invalid_config', 'General interpreters and privilege/runtime managers are not VM recipe executables.');
                }
            }
        }
        foreach ($data['callers'] as $caller) {
            Validate::object($caller, ['tenants', 'actions']);
            foreach (Validate::list($caller['tenants'], 1, 1024) as $tenant) {
                if ($tenant !== '*') { Validate::name($tenant); }
            }
            foreach (Validate::list($caller['actions'], 1, 32) as $action) {
                if (!in_array($action, [...Request::ACTIONS, 'status', 'credentials', 'retry'], true)) {
                    throw new Fault('invalid_config', 'Unknown caller capability.');
                }
            }
        }
        $this->data = $data;
    }

    public static function load(string $path): self
    {
        return new self(Json::decode(Files::readPrivate($path)));
    }

    public function authorize(string $caller, string $tenant, string $action): void
    {
        $policy = $this->data['callers'][$caller] ?? null;
        if ($policy === null || !in_array($action, $policy['actions'], true)
            || (!in_array('*', $policy['tenants'], true) && !in_array($tenant, $policy['tenants'], true))) {
            throw new Fault('forbidden', 'Caller is not authorized for this tenant and operation.');
        }
    }

    public function recipe(string $catalog): Recipe
    {
        $entry = $this->data['catalog'][$catalog] ?? throw new Fault('unknown_catalog', 'Unknown image/recipe catalog entry.');
        return isset($entry['recipe'])
            ? new Recipe(Json::decode(Files::readPrivate($entry['recipe'])), array_keys($entry['vm_executables'] ?? []))
            : Recipe::standard();
    }
}
