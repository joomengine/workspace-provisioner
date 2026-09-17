<?php

declare(strict_types=1);

namespace JoomEngine\Workspace;

/** Operator-owned Composer inputs. No dependency resolution occurs on the controller. */
final readonly class WorkspaceComposer
{
    public function __construct(public array $config)
    {
        Validate::object($config, ['manifest', 'manifest_sha256', 'lock', 'lock_sha256', 'directory'],
            ['auth_secret', 'timeout', 'dev', 'replace']);
        foreach (['manifest', 'lock'] as $name) {
            Validate::path($config[$name]);
            Validate::digest($config[$name . '_sha256']);
        }
        self::directory($config['directory']);
        if (isset($config['auth_secret'])) { Validate::name($config['auth_secret']); }
        Validate::integer($config['timeout'] ?? 600, 1, 1800);
        foreach (['dev', 'replace'] as $flag) {
            if (isset($config[$flag]) && !is_bool($config[$flag])) { throw new Fault('invalid_composer', 'Composer flags must be boolean.'); }
        }
    }

    public static function directory(mixed $directory): string
    {
        $directory = Validate::text($directory, 512);
        if ($directory !== '.' && !preg_match('~^[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*$~D', $directory)) {
            throw new Fault('invalid_composer', 'Composer directory must be a relative path beneath the shared website.');
        }
        return $directory;
    }

    public function payload(string $secretDirectory): array
    {
        $result = ['directory' => $this->config['directory'], 'dev' => $this->config['dev'] ?? false,
            'replace' => $this->config['replace'] ?? false, 'timeout' => $this->config['timeout'] ?? 600];
        foreach (['manifest' => 262144, 'lock' => 524288] as $name => $limit) {
            $data = Files::readPrivate($this->config[$name], $limit);
            if (!hash_equals($this->config[$name . '_sha256'], hash('sha256', $data))) {
                throw new Fault('composer_changed', 'A Composer input differs from its approved content digest.');
            }
            $json = Json::decode($data);
            if ($name === 'lock' && (!isset($json['content-hash'], $json['packages']) || !is_array($json['packages']))) {
                throw new Fault('invalid_composer', 'A complete Composer lockfile is required.');
            }
            $result[$name] = $data;
        }
        $result['auth'] = isset($this->config['auth_secret'])
            ? Json::decode(Files::readPrivate($secretDirectory . '/' . $this->config['auth_secret'], 65536)) : [];
        return $result;
    }
}
