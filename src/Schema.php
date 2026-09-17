<?php

declare(strict_types=1);

namespace JoomEngine\Workspace;

/** Exportable editor/transport contracts; runtime validation also checks authority and topology. */
final class Schema
{
    public const TYPES = ['operator', 'inventory', 'catalog', 'recipe', 'request'];

    private static function object(array $required, array $optional = []): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($required),
            'properties' => [...$required, ...$optional]];
    }

    private static function text(int $max = 4096, ?string $pattern = null): array
    {
        return ['type' => 'string', 'minLength' => 1, 'maxLength' => $max,
            ...($pattern === null ? [] : ['pattern' => $pattern])];
    }

    private static function integer(int $min, int $max): array { return ['type' => 'integer', 'minimum' => $min, 'maximum' => $max]; }
    private static function ref(string $name): array { return ['$ref' => '#/$defs/' . $name]; }
    private static function list(array $items, int $min = 0, int $max = 256): array
    {
        return ['type' => 'array', 'items' => $items, 'minItems' => $min, 'maxItems' => $max];
    }
    private static function map(array $value, int $min = 1): array
    {
        return ['type' => 'object', 'minProperties' => $min, 'propertyNames' => self::ref('name'), 'additionalProperties' => $value];
    }

    public static function document(string $type): array
    {
        if (!in_array($type, self::TYPES, true)) { throw new Fault('invalid_schema', 'Unknown schema type.'); }
        $r = self::ref(...);
        $d = ['name' => self::text(63, '^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$'),
            'uuid' => self::text(36, '^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'),
            'digest' => self::text(64, '^[a-f0-9]{64}$'),
            'path' => self::text(4096, '^/'), 'ip' => ['type' => 'string', 'format' => 'ipv4'],
            'cidr' => self::text(32, '^[0-9.]+/[0-9]{1,2}$'),
            'image' => self::text(300, '^[a-z0-9][a-z0-9._:/-]*(?:@sha256:[a-f0-9]{64}|:[a-zA-Z0-9_][a-zA-Z0-9_.-]*)$'),
            'argv' => self::list(self::text(8192), 1, 128),
            'keys' => self::list(self::text(4096, '^ssh-ed25519 '), 1, 16)];
        $d['profile'] = self::object(['cpu' => self::integer(1, 64), 'memory_mib' => self::integer(1024, 262144),
            'disk_gib' => self::integer(10, 4096), 'network_mbit' => self::integer(1, 10000), 'io_mib' => self::integer(1, 4096)]);
        $d['host'] = self::object(['remote' => $r('name'), 'project' => $r('name'), 'network' => $r('name'),
            'storage' => $r('name'), 'bridge_address' => $r('cidr'), 'addresses' => self::list($r('ip'), 1, 4096),
            'ingress' => self::list($r('cidr'), 1, 64), 'dns' => self::list($r('ip'), 1, 3),
            'denied' => self::list($r('cidr'), 0, 128), 'cpu_budget' => self::integer(1, 4096),
            'memory_mib_budget' => self::integer(1024, 16777216), 'disk_gib_budget' => self::integer(10, 1048576)]);
        $d['composer'] = self::object(['manifest' => $r('path'), 'manifest_sha256' => $r('digest'),
            'lock' => $r('path'), 'lock_sha256' => $r('digest'),
            'directory' => self::text(512, '^(?:\\.|[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*)$')],
            ['auth_secret' => $r('name'), 'timeout' => self::integer(1, 1800), 'dev' => ['type' => 'boolean'], 'replace' => ['type' => 'boolean']]);
        $d['catalogEntry'] = self::object(['vm_image' => $r('digest'), 'jcb_image' => $r('image'),
            'database_image' => $r('image'), 'development_image' => $r('image'),
            'uid' => self::integer(1, 60000), 'gid' => self::integer(1, 60000)],
            ['recipe' => $r('path'), 'composer' => $r('composer'),
                'vm_executables' => ['type' => 'object', 'propertyNames' => $r('path'), 'additionalProperties' => $r('digest')]]);
        $d['catalog'] = self::map($r('catalogEntry'));
        $d['inventory'] = self::object(['hosts' => self::map($r('host')), 'profiles' => self::map($r('profile'))]);
        $d['step'] = self::object(['id' => $r('name'), 'target' => ['enum' => ['vm', 'joomla']], 'argv' => $r('argv'),
            'timeout' => self::integer(1, 3600), 'repeat' => ['enum' => ['safe', 'once']],
            'check' => self::object(['argv' => $r('argv')], ['stdout' => ['type' => 'string', 'maxLength' => 8192]])],
            ['stdin_secret' => $r('name'), 'depends_on' => self::list($r('name'))]);
        $d['recipe'] = self::object(['version' => ['const' => 1], 'id' => $r('name'), 'steps' => self::list($r('step'), 0, 100)]);
        $d['state'] = ['oneOf' => [self::object(['driver' => ['const' => 'file'], 'path' => $r('path')]),
            self::object(['driver' => ['const' => 'pgsql'], 'dsn' => self::text(2048, '^pgsql:'),
                'username' => self::text(255), 'password_file' => $r('path')])]];
        $d['operator'] = self::object(['version' => ['const' => 1], 'installation_id' => $r('uuid'), 'state' => $r('state'),
            'vault' => self::object(['directory' => $r('path'), 'key_file' => $r('path')]),
            'incus' => self::object(['binary' => $r('path'), 'config_directory' => $r('path')]),
            'hosts' => self::map($r('host')), 'profiles' => self::map($r('profile')), 'catalog' => $r('catalog'),
            'callers' => self::map(self::object(['tenants' => self::list(['anyOf' => [$r('name'), ['const' => '*']]], 1, 1024),
                'actions' => self::list(['enum' => [...Request::ACTIONS, 'status', 'credentials', 'retry']], 1, 32)])),
            'recipe_secrets' => $r('path'), 'backup_directory' => $r('path')],
            ['image_resolver' => self::object([], ['binary' => $r('path'), 'auth_file' => $r('path')])]);
        $variants = [];
        foreach (Request::ACTIONS as $action) {
            $fields = ['version' => ['const' => 1], 'request_id' => $r('uuid'), 'workspace_id' => $r('uuid'),
                'tenant' => $r('name'), 'action' => ['const' => $action]];
            if ($action === 'create') { $fields += ['name' => $r('name'), 'catalog' => $r('name'), 'profile' => $r('name'),
                'admin_email' => ['type' => 'string', 'format' => 'email', 'maxLength' => 255], 'ssh_keys' => $r('keys')]; }
            if ($action === 'replace-keys') { $fields['ssh_keys'] = $r('keys'); }
            if ($action === 'restore') { $fields['backup_id'] = $r('uuid'); }
            $variants[] = self::object($fields, $action === 'restore' ? ['replace_existing' => ['type' => 'boolean']] : []);
        }
        $d['request'] = ['oneOf' => $variants];
        return ['$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => 'urn:joomengine:workspace:' . $type . ':1', 'title' => 'Workspace ' . $type . ' v1',
            '$comment' => 'Structural contract. Runtime additionally enforces canonical IPs, paths, key material, authority, digests and cross-field rules.',
            '$ref' => '#/$defs/' . $type, '$defs' => $d];
    }
}
