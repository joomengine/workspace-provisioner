<?php

declare(strict_types=1);

use JoomEngine\Workspace\{BackupStore, Engine, Fault, Files, FileStore, Host, IncusRuntime, IncusTransport, Journal, Json, Request, Vault};

/** An API contract simulator, deliberately not evidence of real VM isolation. */
final class RecoveryTransport implements IncusTransport
{
    public array $resources = [];
    public array $calls = [];
    public array $uploads = [];
    public int $exports = 0;
    public int $imports = 0;
    public bool $loseImportResponse = false;
    private array $exported = [];

    public function request(string $remote, string $project, string $method, string $path, ?array $body = null): array
    {
        $this->calls[] = [$method, $path];
        if ($path === '/1.0') {
            return ['metadata' => ['api_extensions' => Host::REQUIRED_EXTENSIONS,
                'environment' => ['driver' => 'qemu', 'server_version' => 'contract-test', 'server_clustered' => false]]];
        }
        if ($path === '/1.0/storage-pools/default') { return ['metadata' => ['driver' => 'zfs']]; }
        if (str_starts_with($path, '/1.0/images/')) {
            return ['metadata' => ['type' => 'virtual-machine', 'properties' => ['wp.template.version' => '1']]];
        }
        if ($method === 'POST') {
            $resource = $body;
            if ($path === '/1.0/instances') {
                unset($resource['source']);
                $resource += ['architecture' => 'x86_64', 'status' => 'Stopped'];
            }
            $this->resources[$path . '/' . $body['name']] = $resource;
            return ['type' => 'sync', 'metadata' => []];
        }
        if ($method === 'PUT' && str_ends_with($path, '/state')) {
            $this->resources[dirname($path)]['status'] = $body['action'] === 'start' ? 'Running' : 'Stopped';
            return ['type' => 'sync', 'metadata' => []];
        }
        if ($method === 'PUT') {
            if (!isset($this->resources[$path])) { throw new Fault('not_found', 'Unknown simulated resource.'); }
            $this->resources[$path] = [...$this->resources[$path], ...$body];
            return ['type' => 'sync', 'metadata' => []];
        }
        if ($method === 'DELETE') { unset($this->resources[$path]); return ['type' => 'sync', 'metadata' => []]; }
        if (isset($this->resources[$path])) { return ['metadata' => $this->resources[$path]]; }
        if (in_array($path, ['/1.0/projects', '/1.0/networks', '/1.0/network-acls', '/1.0/instances'], true)) {
            return ['metadata' => array_values(array_filter(array_keys($this->resources), static fn ($key) => dirname($key) === $path))];
        }
        throw new Fault('not_found', 'Unknown simulated resource.');
    }

    public function command(string $project, array $arguments, string $stdin = '', int $timeout = 60): array
    {
        $this->calls[] = $arguments;
        $output = '{"ok":true}';
        if ($arguments === ['query', '--help']) { $output = '--data-file'; }
        if ($arguments[0] === 'export') {
            ++$this->exports;
            $name = substr($arguments[1], strpos($arguments[1], ':') + 1);
            $resource = $this->resources['/1.0/instances/' . $name];
            same('Stopped', $resource['status']);
            same('jcb-test-bootstrap', $resource['devices']['eth0']['security.acls']);
            $this->exported = $resource;
            Files::write($arguments[2], 'synthetic-export-with-private-data');
        }
        if ($arguments[0] === 'import') {
            ++$this->imports;
            same('synthetic-export-with-private-data', file_get_contents($arguments[2]));
            same(0600, fileperms($arguments[2]) & 0777);
            $resource = $this->exported;
            $resource['status'] = 'Stopped';
            foreach ($arguments as $value) {
                if (str_starts_with($value, 'user.wp.restore=')) { $resource['config']['user.wp.restore'] = substr($value, 16); }
            }
            $this->resources['/1.0/instances/' . $arguments[3]] = $resource;
            if ($this->loseImportResponse) {
                $this->loseImportResponse = false;
                return ['exit' => 1, 'stdout' => '', 'stderr' => 'Synthetic lost response after import'];
            }
        }
        return ['exit' => 0, 'stdout' => $output, 'stderr' => ''];
    }

    public function upload(string $remote, string $project, string $instance, string $path, string $content, string $mode = '0600'): void
    {
        $this->uploads[$path] = ['mode' => $mode, 'bytes' => strlen($content)];
    }
}

function cleanRecoveryDirectory(string $directory): void
{
    foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $file) {
        if ($file->isDir() && !$file->isLink()) { cleanRecoveryDirectory($file->getPathname()); }
        else { unlink($file->getPathname()); }
    }
    rmdir($directory);
}

test('Incus runtime adapter encrypts exports, rejects replacement and resumes an uncertain import', function (): void {
    $dir = sys_get_temp_dir() . '/wp-recovery-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    try {
        Files::write($dir . '/key', bin2hex(random_bytes(32)));
        $config = fixtureConfig($dir);
        $journal = new Journal(new FileStore($dir . '/state.json'), $config);
        $request = fixtureRequest();
        $journal->submit('operator', new Request($request));
        $workspace = $journal->workspace($request['workspace_id']);
        $transport = new RecoveryTransport();
        (new Host($config, $transport))->prepare('lab', $config->data['installation_id']);
        $runtime = new IncusRuntime($config, $transport, dirname(__DIR__, 2));
        $runtime->ensure($workspace, static function (): void {});
        $runtime->prepare($workspace, ['admin_username' => 'testuser', 'admin_password' => 'synthetic-password',
            'database_password' => 'synthetic-db', 'database_root_password' => 'synthetic-root']);
        same('0644', $transport->uploads['/opt/jcb-workspace/secrets/database_password']['mode']);
        same('0600', $transport->uploads['/opt/jcb-workspace/secrets/admin_password']['mode']);
        $backup = Json::uuid();
        $metadata = $runtime->backup($workspace, $backup);
        same(true, $metadata['encrypted']);
        same($metadata, $runtime->backup($workspace, $backup));
        same(1, $transport->exports);
        same([], glob($dir . '/.staging-*'));
        $archive = $dir . '/' . $workspace['id'] . '-' . $backup . '/payload.enc';
        same(false, str_contains(file_get_contents($archive), 'synthetic-export-with-private-data'));
        $operation = Json::uuid();
        rejects(fn () => $runtime->restore($workspace, $backup, $operation), 'restore_conflict');
        same(0, $transport->imports);
        $runtime->delete($workspace); // Synthetic disaster recovery; do not delete customer VMs for ordinary restore.
        $transport->loseImportResponse = true;
        rejects(fn () => $runtime->restore($workspace, $backup, $operation), 'restore_failed');
        $runtime->restore($workspace, $backup, $operation);
        same(1, $transport->imports);
        same([], glob($dir . '/.staging-*'));
        $resource = $transport->resources['/1.0/instances/' . $workspace['instance']];
        same('Stopped', $resource['status']);
        same($operation, $resource['config']['user.wp.restore']);
        same('jcb-test-bootstrap', $resource['devices']['eth0']['security.acls']);
        // A same-name resource with different ownership is never restored over.
        $transport->resources['/1.0/instances/' . $workspace['instance']]['config']['user.wp.owner'] = 'other';
        rejects(fn () => $runtime->restore($workspace, $backup, Json::uuid()), 'ownership_conflict');
    } finally { cleanRecoveryDirectory($dir); }
});

test('backup failures are retryable and restored keys are applied without republishing access', function (): void {
    $dir = sys_get_temp_dir() . '/wp-recovery-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    try {
        Files::write($dir . '/key', bin2hex(random_bytes(32)));
        $cfg = fixtureConfig($dir);
        $journal = new Journal(new FileStore($dir . '/state.json'), $cfg);
        $runtime = new MemoryRuntime();
        $engine = new Engine($cfg, $journal, $runtime, new Vault($dir, $dir . '/key'));
        $request = fixtureRequest();
        $journal->submit('operator', new Request($request));
        same('succeeded', $engine->tick()['status']);
        $base = ['version' => 1, 'workspace_id' => $request['workspace_id'], 'tenant' => 'test'];
        $backup = $journal->submit('operator', new Request($base + ['request_id' => Json::uuid(), 'action' => 'backup']));
        $runtime->failure = 'backup';
        same('failed', $engine->tick()['status']);
        $journal->retry('operator', $backup['id']);
        same('succeeded', $engine->tick()['status']);
        same(true, $journal->status('operator', $backup['id'])['last_backup']['encrypted']);
        $restoreRequest = $base + ['request_id' => Json::uuid(), 'action' => 'restore', 'backup_id' => $backup['id']];
        rejects(fn () => $journal->submit('operator', new Request($restoreRequest)), 'invalid_restore');
        $journal->submit('operator', new Request($base + ['request_id' => Json::uuid(), 'action' => 'suspend']));
        same('succeeded', $engine->tick()['status']);
        $runtime->calls = [];
        $restore = $journal->submit('operator', new Request($restoreRequest));
        same('succeeded', $engine->tick()['status']);
        same(['backup-verify', 'restore', 'close', 'start', 'handover', 'keys', 'verify', 'stop'], $runtime->calls);
        same('suspended', $journal->workspace($request['workspace_id'])['status']);
        same(null, $journal->status('operator', $restore['id'])['connection']);
        $wrong = $restoreRequest; $wrong['request_id'] = Json::uuid(); $wrong['backup_id'] = Json::uuid();
        rejects(fn () => $journal->submit('operator', new Request($wrong)), 'invalid_restore');
        $wrong['backup_id'] = '../../other';
        rejects(fn () => new Request($wrong), 'invalid_id');
    } finally { cleanRecoveryDirectory($dir); }
});

test('explicit restore replacement preserves a rollback backup and retries retirement once', function (): void {
    $dir = sys_get_temp_dir() . '/wp-recovery-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    try {
        Files::write($dir . '/key', bin2hex(random_bytes(32)));
        $cfg = fixtureConfig($dir);
        $journal = new Journal(new FileStore($dir . '/state.json'), $cfg);
        $runtime = new MemoryRuntime();
        $engine = new Engine($cfg, $journal, $runtime, new Vault($dir, $dir . '/key'));
        $request = fixtureRequest();
        $journal->submit('operator', new Request($request));
        same('succeeded', $engine->tick()['status']);
        $base = ['version' => 1, 'workspace_id' => $request['workspace_id'], 'tenant' => 'test'];
        $backup = $journal->submit('operator', new Request($base + ['request_id' => Json::uuid(), 'action' => 'backup']));
        same('succeeded', $engine->tick()['status']);
        $journal->submit('operator', new Request($base + ['request_id' => Json::uuid(), 'action' => 'suspend']));
        same('succeeded', $engine->tick()['status']);
        same(true, $journal->workspace($request['workspace_id'])['access_stop_confirmed']);
        $restoreRequest = $base + ['request_id' => Json::uuid(), 'action' => 'restore', 'backup_id' => $backup['id'], 'replace_existing' => true];
        $bad = $restoreRequest; $bad['replace_existing'] = 'yes';
        rejects(fn () => new Request($bad), 'invalid_restore');
        $runtime->calls = [];
        $restore = $journal->submit('operator', new Request($restoreRequest));
        $runtime->failure = 'restore';
        same('failed', $engine->tick()['status']);
        same(true, $journal->workspace($request['workspace_id'])['backups'][$restore['id']]['encrypted']);
        $journal->retry('operator', $restore['id']);
        same('succeeded', $engine->tick()['status']);
        same(1, count(array_filter($runtime->calls, static fn ($call) => $call === 'backup')));
        same(1, count(array_filter($runtime->calls, static fn ($call) => $call === 'delete')));
        same('suspended', $journal->workspace($request['workspace_id'])['status']);
    } finally { cleanRecoveryDirectory($dir); }
});
