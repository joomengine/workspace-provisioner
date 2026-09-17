<?php

declare(strict_types=1);

use JoomEngine\Workspace\{Engine, Fault, Files, FileStore, Journal, Json, Runtime, Request, Vault};

final class MemoryRuntime implements Runtime
{
    public array $calls = [];
    public ?string $failure = null;
    private function call(string $name): void {
        $this->calls[] = $name;
        if ($this->failure === $name) { $this->failure = null; throw new Fault('injected_failure', 'Synthetic fault.'); }
    }
    public function ensure(array $w, callable $observe): void { $this->call('ensure'); }
    public function prepare(array $w, array $c): void { $this->call('prepare'); }
    public function initialize(array $w): void { $this->call('initialize'); }
    public function start(array $w): void { $this->call('start'); }
    public function verify(array $w): array { $this->call('verify'); return ['exposure' => 'private']; }
    public function handover(array $w): void { $this->call('handover'); }
    public function access(array $w, bool $enabled): void { $this->call($enabled ? 'open' : 'close'); }
    public function suspend(array $w): void { $this->call('stop'); }
    public function delete(array $w): void { $this->call('delete'); }
    public function replaceKeys(array $w, array $keys): void { $this->call('keys'); }
    public function recipeCheck(array $w, array $s): bool { return false; }
    public function recipeRun(array $w, array $s, string $stdin): void { $this->call('recipe'); }
    public function backup(array $w, string $o): array { $this->call('backup'); return ['archive' => 'test']; }
}

test('lifecycle preserves handover, resumes failures and terminates access', function (): void {
    $dir = sys_get_temp_dir() . '/jcb-engine-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    try {
        Files::write($dir . '/key', bin2hex(random_bytes(32)));
        $cfg = fixtureConfig($dir);
        $journal = new Journal(new FileStore($dir . '/state.json'), $cfg);
        $runtime = new MemoryRuntime();
        $engine = new Engine($cfg, $journal, $runtime, new Vault($dir, $dir . '/key'));
        $request = fixtureRequest();
        $op = $journal->submit('operator', new Request($request));
        $runtime->failure = 'handover';
        same('failed', $engine->tick()['status']);
        same(true, $journal->workspace($request['workspace_id'])['handed_over']);
        same('stop', end($runtime->calls));
        $journal->retry('operator', $op['id']);
        same('succeeded', $engine->tick()['status']);
        same(1, count(array_filter($runtime->calls, fn ($s) => $s === 'prepare')));
        same(1, count(array_filter($runtime->calls, fn ($s) => $s === 'initialize')));
        foreach (['suspend', 'resume', 'replace-keys', 'verify', 'delete'] as $action) {
            $r = ['version' => 1, 'request_id' => Json::uuid(), 'workspace_id' => $request['workspace_id'], 'tenant' => 'test', 'action' => $action];
            if ($action === 'replace-keys') { $r['ssh_keys'] = [fixtureKey()]; }
            $journal->submit('operator', new Request($r));
            same('succeeded', $engine->tick()['status']);
        }
        same('deleted', $journal->workspace($request['workspace_id'])['status']);
        rejects(fn () => $journal->retry('operator', $op['id']), 'stale_operation');
        same(null, $engine->tick());
    } finally {
        foreach (glob($dir . '/*') as $file) { unlink($file); }
        @unlink($dir . '/.lock');
        rmdir($dir);
    }
});

test('resolved latest images remain pinned across an interrupted installation', function (): void {
    $dir = sys_get_temp_dir() . '/jcb-images-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    try {
        Files::write($dir . '/key', bin2hex(random_bytes(32)));
        $data = fixtureConfig($dir)->data;
        foreach (['jcb_image', 'database_image', 'development_image'] as $key) { $data['catalog']['standard'][$key] = 'example/' . str_replace('_', '-', $key) . ':latest'; }
        $cfg = new JoomEngine\Workspace\Config($data);
        $journal = new Journal(new FileStore($dir . '/state.json'), $cfg);
        $reads = 0;
        $resolver = new JoomEngine\Workspace\ImageResolver([], static function () use (&$reads): string {
            return Json::encode(['schemaVersion' => 2, 'manifests' => [], 'generation' => ++$reads]);
        });
        $runtime = new MemoryRuntime();
        $engine = new Engine($cfg, $journal, $runtime, new Vault($dir, $dir . '/key'), $resolver);
        $request = fixtureRequest();
        $op = $journal->submit('operator', new Request($request));
        $runtime->failure = 'initialize';
        same('failed', $engine->tick()['status']);
        $images = $journal->workspace($request['workspace_id'])['resolved_images'];
        same(3, $reads);
        $journal->retry('operator', $op['id']);
        same('succeeded', $engine->tick()['status']);
        same(3, $reads);
        same($images, $journal->workspace($request['workspace_id'])['resolved_images']);
    } finally {
        foreach (glob($dir . '/*') as $file) { unlink($file); }
        @unlink($dir . '/.lock'); rmdir($dir);
    }
});
