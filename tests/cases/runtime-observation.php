<?php

declare(strict_types=1);

use JoomEngine\Workspace\{Config, Engine, Fault, Files, FileStore, IncusRuntime, Journal, Json, Request, Vault};

final class ObservationRuntime extends MemoryRuntime
{
    public string $physical = 'running';
    public ?Closure $duringRead = null;
    public bool $settled = true;
    public int $reads = 0;
    public function observe(array $w, ?array $intent = null, ?array $cleanupIntent = null): array
    {
        ++$this->reads;
        if ($this->duringRead !== null) { ($this->duringRead)(); }
        return ['state' => $this->physical, 'error' => null, 'intent_settled' => $this->settled, 'cleanup_settled' => true];
    }
}

function withRuntimeObservation(callable $body): void
{
    $dir = sys_get_temp_dir() . '/wp-observation-' . bin2hex(random_bytes(8)); mkdir($dir, 0700);
    try {
        Files::write($dir . '/key', bin2hex(random_bytes(32)));
        $config = fixtureConfig($dir); $journal = new Journal(new FileStore($dir . '/state.json'), $config);
        $runtime = new ObservationRuntime(); $engine = new Engine($config, $journal, $runtime, new Vault($dir, $dir . '/key'));
        $request = fixtureRequest(); $op = $journal->submit('operator', new Request($request));
        $body($journal, $runtime, $engine, $request, $op, $config, $dir);
    } finally { cleanRecoveryDirectory($dir); }
}

test('status measures current runtime with scoped identity and no lifecycle or state mutation', function (): void {
    withRuntimeObservation(function ($journal, $runtime, $engine, $request, $op, $config, $dir): void {
        same('unknown', $engine->status('operator', $op['id'])['runtime_observation']['state']); same(0, $runtime->reads);
        same('succeeded', $engine->tick()['status']); $runtime->calls = [];
        $before = file_get_contents($dir . '/state.json');
        $runtime->duringRead = fn () => rejects(fn () => $journal->store->exclusive(static fn () => null), 'busy');
        foreach (['running', 'stopped', 'absent'] as $state) {
            $runtime->physical = $state;
            $result = $engine->status('operator', $op['id']); $o = $result['runtime_observation'];
            same($state, $o['state']); same(1, $o['version']); same($config->data['installation_id'], $o['installation_id']);
            same('lab', $o['native_host']); same($request['tenant'], $o['tenant']); same($request['workspace_id'], $o['workspace_id']);
            same($op['id'], $o['operation_id']); same($request['request_id'], $o['request_id']);
            same(1, $o['generation']); same($result['attempt'], $o['attempt']); same(true, abs(time() - strtotime($o['observed_at'])) <= 1);
            same(false, isset($result['stop_observation'])); same([], $runtime->calls); same($before, file_get_contents($dir . '/state.json'));
        }
        same(3, $runtime->reads);
        rejects(fn () => $engine->status('unknown', $op['id']), 'forbidden'); same(3, $runtime->reads);
    });
});

test('runtime evidence stays unknown under executor contention and unsafe producer outcomes', function (): void {
    withRuntimeObservation(function ($journal, $runtime, $engine, $request, $op, $config, $dir): void {
        $engine->tick(); $runtime->calls = [];
        $journal->store->exclusive(function () use ($engine, $op): void {
            same('unknown', $engine->status('operator', $op['id'])['runtime_observation']['state']);
        });
        same(0, $runtime->reads);
        $runtime->physical = 'unclassified'; same('unknown', $engine->status('operator', $op['id'])['runtime_observation']['state']);
        $runtime->physical = 'absent'; $runtime->settled = false;
        same('unknown', $engine->status('operator', $op['id'])['runtime_observation']['state']);
        $runtime->settled = true; $runtime->duringRead = static fn () => throw new Fault('unreachable', 'Private diagnostics');
        same('unknown', $engine->status('operator', $op['id'])['runtime_observation']['state']);
        $runtime->duringRead = null;
        $data = $config->data; $data['hosts']['lab']['remote'] = 'changed';
        $changed = new Engine(new Config($data), $journal, $runtime, new Vault($dir, $dir . '/key'));
        $reads = $runtime->reads; same('unknown', $changed->status('operator', $op['id'])['runtime_observation']['state']); same($reads, $runtime->reads);
        $journal->change($op['id'], static function (array &$o): void { unset($o['runtime_tracking']); });
        same('unknown', $engine->status('operator', $op['id'])['runtime_observation']['state']); same($reads, $runtime->reads);
        same([], $runtime->calls);
    });
});

test('native generation and concurrent submissions invalidate status power evidence', function (): void {
    withRuntimeObservation(function ($journal, $runtime, $engine, $request, $op): void {
        $engine->tick(); $runtime->calls = [];
        $runtime->duringRead = function () use ($journal, $request): void {
            $journal->submit('operator', new Request(['version' => 1, 'request_id' => Json::uuid(),
                'workspace_id' => $request['workspace_id'], 'tenant' => $request['tenant'], 'action' => 'suspend']));
        };
        $r = $engine->status('operator', $op['id']); same(2, $r['workspace_generation']); same('unknown', $r['runtime_observation']['state']);
        $runtime->duringRead = null; $reads = $runtime->reads;
        same('unknown', $engine->status('operator', $op['id'])['runtime_observation']['state']); same($reads, $runtime->reads);
        same([], $runtime->calls);
    });
});

test('fresh status uses only scoped GET requests and unowned absence cannot become down', function (): void {
    withRuntimeObservation(function ($journal, $memory, $engine, $request, $op, $config, $dir): void {
        $engine->tick(); $w = $journal->workspace($request['workspace_id']);
        $transport = new StopTransport($w, $config);
        $readEngine = new Engine($config, $journal, new IncusRuntime($config, $transport, dirname(__DIR__, 2)), new Vault($dir, $dir . '/key'));
        same('running', $readEngine->status('operator', $op['id'])['runtime_observation']['state']);
        $transport->instance['config']['user.wp.owner'] = Json::uuid();
        same('unknown', $readEngine->status('operator', $op['id'])['runtime_observation']['state']);
        $transport->instances = null;
        same('unknown', $readEngine->status('operator', $op['id'])['runtime_observation']['state']);
        $transport->instances = []; $transport->operations = ['running' => [['status_code' => 103, 'resources' => []]]];
        same('unknown', $readEngine->status('operator', $op['id'])['runtime_observation']['state']);
        $transport->operations = [];
        same('absent', $readEngine->status('operator', $op['id'])['runtime_observation']['state']);
        same([], array_values(array_filter($transport->calls, static fn ($c) => $c[0] !== 'GET')));
    });
});
