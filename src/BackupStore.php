<?php

declare(strict_types=1);

namespace JoomEngine\Workspace;

/** Private, authenticated exports. Plaintext is never unpacked on the controller. */
final readonly class BackupStore
{
    private Archive $archive;
    private string $manifestKey;

    public function __construct(private string $directory, string $keyFile, private string $installation)
    {
        Files::protectedPath($directory, true);
        if ((fileperms($directory) & 0077) !== 0) {
            throw new Fault('unsafe_path', 'Backup storage must be owner-only.');
        }
        $this->archive = new Archive($keyFile, $installation);
        $key = sodium_hex2bin(Validate::digest(trim(Files::readPrivate($keyFile, 128))));
        $this->manifestKey = hash_hkdf('sha256', $key, 32, 'workspace-backup-manifest-v1', $installation);
    }

    public function exists(array $workspace, string $backup): bool
    {
        $path = $this->path($workspace, $backup);
        if (file_exists($path) && !is_dir($path)) {
            throw new Fault('invalid_backup', 'Backup destination is not a directory.');
        }
        return is_dir($path);
    }

    public function save(array $workspace, string $backup, callable $export): array
    {
        $destination = $this->path($workspace, $backup);
        if (is_dir($destination)) { return $this->metadata($workspace, $backup); }
        $stage = $this->stage();
        try {
            $plain = $stage . '/export.tar.gz';
            $export($plain);
            if (!is_file($plain) || is_link($plain) || filesize($plain) === 0 || !chmod($plain, 0600)) {
                throw new Fault('backup_failed', 'Export did not produce a private regular archive.');
            }
            $metadata = ['version' => 1, 'id' => $backup, 'installation_id' => $this->installation,
                'workspace_id' => $workspace['id'], 'instance' => $workspace['instance'],
                'host_hash' => $workspace['host_hash'], 'catalog_hash' => $workspace['catalog_hash'],
                'recipe_hash' => $workspace['recipe_hash'], 'images_hash' => Json::hash($workspace['resolved_images'] ?? []), 'created_at' => gmdate(DATE_ATOM),
                'plain_sha256' => hash_file('sha256', $plain), 'plain_bytes' => filesize($plain)];
            $this->archive->seal($plain, $stage . '/payload.enc', Json::encode($metadata));
            unlink($plain);
            $record = ['metadata' => $metadata, 'cipher_sha256' => hash_file('sha256', $stage . '/payload.enc')];
            $record['mac'] = hash_hmac('sha256', Json::encode($record), $this->manifestKey);
            Files::write($stage . '/manifest.json', Json::encode($record));
            if (file_exists($destination) || !rename($stage, $destination)) {
                throw new Fault('backup_exists', 'Backup publication conflicted with an existing archive.');
            }
            return $metadata + ['encrypted' => true, 'format' => 'incus-secretstream-v1'];
        } finally { $this->clean($stage); }
    }

    public function metadata(array $workspace, string $backup): array
    {
        $path = $this->path($workspace, $backup);
        $record = Json::decode(Files::readPrivate($path . '/manifest.json', 16384));
        Validate::object($record, ['metadata', 'cipher_sha256', 'mac']);
        $mac = Validate::digest($record['mac']);
        unset($record['mac']);
        if (!hash_equals(hash_hmac('sha256', Json::encode($record), $this->manifestKey), $mac)) {
            throw new Fault('invalid_backup', 'Backup manifest authentication failed.');
        }
        $m = $record['metadata'];
        foreach (['installation_id' => $this->installation, 'workspace_id' => $workspace['id'], 'id' => $backup,
            'instance' => $workspace['instance'], 'host_hash' => $workspace['host_hash'],
            'catalog_hash' => $workspace['catalog_hash'], 'recipe_hash' => $workspace['recipe_hash'],
            'images_hash' => Json::hash($workspace['resolved_images'] ?? [])] as $key => $value) {
            if (($m[$key] ?? null) !== $value) { throw new Fault('backup_mismatch', 'Backup does not match this workspace and its pinned configuration.'); }
        }
        Files::protectedPath($path . '/payload.enc');
        $stat = @lstat($path . '/payload.enc');
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0077) !== 0
            || !in_array($stat['uid'], [0, posix_geteuid()], true)
            || !hash_equals(Validate::digest($record['cipher_sha256']), hash_file('sha256', $path . '/payload.enc'))) {
            throw new Fault('invalid_backup', 'Encrypted backup is missing, unsafe or corrupt.');
        }
        return $m + ['encrypted' => true, 'format' => 'incus-secretstream-v1'];
    }

    public function withPlaintext(array $workspace, string $backup, callable $import): mixed
    {
        $metadata = $this->metadata($workspace, $backup);
        unset($metadata['encrypted'], $metadata['format']);
        $stage = $this->stage();
        try {
            $plain = $stage . '/export.tar.gz';
            $this->archive->open($this->path($workspace, $backup) . '/payload.enc', $plain, Json::encode($metadata));
            if (filesize($plain) !== $metadata['plain_bytes']
                || !hash_equals($metadata['plain_sha256'], hash_file('sha256', $plain))) {
                throw new Fault('invalid_backup', 'Decrypted export does not match its manifest.');
            }
            return $import($plain);
        } finally { $this->clean($stage); }
    }

    private function path(array $workspace, string $backup): string
    {
        $path = $this->directory . '/' . Validate::uuid($workspace['id']) . '-' . Validate::uuid($backup);
        Files::protectedPath($path);
        return $path;
    }

    private function stage(): string
    {
        $path = $this->directory . '/.staging-' . bin2hex(random_bytes(16));
        if (!mkdir($path, 0700)) { throw new Fault('backup_failed', 'Unable to create private archive staging.'); }
        return $path;
    }

    private function clean(string $path): void
    {
        if (!is_dir($path)) { return; }
        foreach (['export.tar.gz', 'payload.enc', 'manifest.json'] as $file) {
            if (file_exists($path . '/' . $file) || is_link($path . '/' . $file)) { unlink($path . '/' . $file); }
        }
        rmdir($path);
    }
}
