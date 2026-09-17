<?php

declare(strict_types=1);

namespace JoomEngine\Workspace;

/** Resolve configured OCI tags once; running workspaces retain the resulting immutable references. */
final readonly class ImageResolver
{
    public function __construct(private array $config = [], private ?\Closure $readManifest = null)
    {
        Validate::object($config, [], ['binary', 'auth_file']);
        foreach ($config as $path) { Validate::path($path); }
    }

    public static function selector(mixed $value): string
    {
        $value = Validate::text($value, 300);
        if (!preg_match('~^[a-z0-9][a-z0-9._-]*(?::[0-9]+)?(?:/[a-z0-9][a-z0-9._-]*)*(?::[a-zA-Z0-9_][a-zA-Z0-9_.-]{0,127}|@sha256:[a-f0-9]{64})$~D', $value)) {
            throw new Fault('invalid_image', 'Use an OCI repository with an explicit tag or SHA-256 digest.');
        }
        return $value;
    }

    public function resolve(array $catalog): array
    {
        $images = [];
        foreach (['jcb_image', 'database_image', 'development_image'] as $key) {
            $reference = self::selector($catalog[$key]);
            if (str_contains($reference, '@sha256:')) { $images[$key] = Validate::image($reference); continue; }
            if ($this->readManifest !== null) {
                $raw = ($this->readManifest)($reference);
            } else {
                $binary = $this->config['binary'] ?? '/usr/bin/skopeo';
                Files::protectedPath($binary);
                $temporary = null;
                try {
                    $auth = $this->config['auth_file'] ?? null;
                    if ($auth === null) {
                        $temporary = sys_get_temp_dir() . '/wp-registry-' . bin2hex(random_bytes(16));
                        if (!mkdir($temporary, 0700)) { throw new Fault('registry_auth_failed', 'Cannot create isolated registry authentication context.'); }
                        $auth = $temporary . '/auth.json';
                        Files::write($auth, '{"auths":{}}');
                    } else {
                        Files::readPrivate($auth);
                    }
                    // Never search the host's ambient Docker/Podman credentials implicitly.
                    $raw = (new Process())->requireSuccess([$binary, 'inspect', '--raw', '--tls-verify=true',
                        '--authfile', $auth, 'docker://' . $reference], '', 180);
                } finally {
                    if ($temporary !== null) {
                        if (is_file($temporary . '/auth.json')) { unlink($temporary . '/auth.json'); }
                        rmdir($temporary);
                    }
                }
            }
            $manifest = Json::decode($raw);
            if (($manifest['schemaVersion'] ?? null) !== 2 || (!isset($manifest['manifests']) && !isset($manifest['config'], $manifest['layers']))) {
                throw new Fault('invalid_manifest', 'Registry did not return a version-2 image manifest or index.');
            }
            $repository = substr($reference, 0, (int) strrpos($reference, ':'));
            $images[$key] = Validate::image($repository . '@sha256:' . hash('sha256', $raw));
        }
        return $images;
    }
}
