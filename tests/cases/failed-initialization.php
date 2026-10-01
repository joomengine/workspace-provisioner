<?php

declare(strict_types=1);

use JoomEngine\Workspace\{Config, Engine, Fault, Files, FileStore, IncusRuntime, IncusTransport, Journal, Json, Request, Vault};

final class StopRuntime extends MemoryRuntime
{
    public string $physical = 'running';
    public bool $cannotStop = false;
    public bool $unreachable = false;
    public ?Closure $afterObserve = null;
    public function suspend(array $w, ?callable $observe = null): void
    {
        parent::suspend($w);
        if ($this->cannotStop) { throw new Fault('stop_unavailable', 'Synthetic stop failure.'); }
        if ($this->physical !== 'absent') { $this->physical = 'stopped'; }
    }
    public function observe(array $w, ?array $intent = null, ?array $cleanupIntent = null): array
    {
        if ($this->unreachable) { throw new Fault('incus_request_failed', 'Synthetic outage.'); }
        $result = ['state' => $this->physical, 'error' => null, 'intent_settled' => true, 'cleanup_settled' => true];
        if (($intent !== null && $intent['state'] === 'pending') || ($cleanupIntent !== null && $cleanupIntent['state'] === 'pending')) {
            $result = ['state' => 'unknown', 'error' => 'runtime_outcome_unconfirmed', 'intent_settled' => false, 'cleanup_settled' => false];
        }
        if ($this->afterObserve !== null) { ($this->afterObserve)(); }
        return $result;
    }
}

function withFailedInitialization(callable $body): void
{
    $dir = sys_get_temp_dir() . '/wp-stop-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    try {
        Files::write($dir . '/key', bin2hex(random_bytes(32)));
        $config = fixtureConfig($dir);
        $journal = new Journal(new FileStore($dir . '/state.json'), $config);
        $runtime = new StopRuntime();
        $engine = new Engine($config, $journal, $runtime, new Vault($dir, $dir . '/key'));
        $request = fixtureRequest();
        $op = $journal->submit('operator', new Request($request));
        $runtime->failure = 'initialize';
        same('failed', $engine->tick()['status']);
        $body($journal, $runtime, $engine, $request, $op, $config, $dir);
    } finally { cleanRecoveryDirectory($dir); }
}

test('failed initialization has fresh stop evidence without changing retry identity or data', function (): void {
    withFailedInitialization(function ($journal, $runtime, $engine, $request, $op): void {
        $before = $journal->operation($op['id']);
        $w = $journal->workspace($request['workspace_id']);
        $evidence = $engine->status('operator', $op['id'])['stop_observation'];
        same('stopped', $evidence['state']); same(1, $evidence['generation']); same($op['id'], $evidence['operation_id']);
        same($op['id'], $journal->submit('operator', new Request($request))['id']);
        same('complete', $before['checkpoints']['bootstrap']);
        same('running', $before['checkpoints']['installation']);
        same(false, $w['handed_over']);
        $runtime->physical = 'running';
        same('running', $engine->status('operator', $op['id'])['stop_observation']['state']);
        $runtime->unreachable = true;
        same('unknown', $engine->status('operator', $op['id'])['stop_observation']['state']);
        $runtime->unreachable = false;
        $journal->retry('operator', $op['id']);
        same(false, $journal->workspace($w['id'])['access_stop_confirmed']);
        same(false, isset($engine->status('operator', $op['id'])['stop_observation']));
        same('succeeded', $engine->tick()['status']);
        $after = $journal->operation($op['id']);
        foreach (['generation', 'request', 'request_hash', 'id'] as $key) { same($before[$key], $after[$key]); }
        same(1, count(array_filter($runtime->calls, fn ($call) => $call === 'prepare')));
        same([], array_values(array_intersect($runtime->calls, ['delete'])));
        same($w['resolved_images'], $journal->workspace($w['id'])['resolved_images']);
    });
});

test('cleanup survives lost worker state and retries stops without restarting initialization', function (): void {
    withFailedInitialization(function ($journal, $runtime, $engine, $request, $op): void {
        // Simulate durable failure followed by process death before cleanup.
        $journal->change($op['id'], static function (array &$o, array &$w): void {
            $o['cleanup_next_at'] = 0; unset($o['stop_observation']); $w['access_stop_confirmed'] = false;
        });
        $runtime->physical = 'running'; $runtime->cannotStop = true; $runtime->calls = [];
        same('failed', $engine->tick()['status']);
        same(['stop'], $runtime->calls);
        same('running', $engine->status('operator', $op['id'])['stop_observation']['state']);
        same(null, $engine->tick()); // paced, not a hot retry loop
        $journal->change($op['id'], static function (array &$o, array &$w): void { $o['cleanup_next_at'] = 0; });
        $runtime->cannotStop = false;
        same('failed', $engine->tick()['status']);
        same('stopped', $engine->status('operator', $op['id'])['stop_observation']['state']);
        same(['stop', 'stop'], $runtime->calls);
    });
});

test('fresh evidence is invalidated by concurrent retry or a newer native generation', function (): void {
    withFailedInitialization(function ($journal, $runtime, $engine, $request, $op): void {
        $runtime->afterObserve = fn () => $journal->retry('operator', $op['id']);
        $r = $engine->status('operator', $op['id']);
        same('queued', $r['status']); same('unknown', $r['stop_observation']['state']);
        $runtime->afterObserve = null;
        $runtime->failure = 'initialize'; same('failed', $engine->tick()['status']);
        $runtime->afterObserve = function () use ($journal, $request): void {
            $journal->submit('operator', new Request(['version' => 1, 'request_id' => Json::uuid(),
                'workspace_id' => $request['workspace_id'], 'tenant' => 'test', 'action' => 'suspend']));
        };
        $r = $engine->status('operator', $op['id']);
        same(2, $r['workspace_generation']); same('unknown', $r['stop_observation']['state']);
    });
});

test('unacknowledged effects and legacy failures remain unknown and cannot auto resume', function (): void {
    withFailedInitialization(function ($journal, $runtime, $engine, $request, $op): void {
        foreach (['create', 'start'] as $kind) {
            $journal->change($op['id'], static function (array &$o, array &$w) use ($kind): void {
                $o['runtime_intent'] = ['kind' => $kind, 'state' => 'pending', 'operation' => null]; $o['cleanup_next_at'] = 0;
            });
            $runtime->physical = 'absent';
            same('failed', $engine->tick()['status']);
            same('unknown', $engine->status('operator', $op['id'])['stop_observation']['state']);
            rejects(fn () => $journal->retry('operator', $op['id']), 'runtime_outcome_unconfirmed');
            foreach (['suspend', 'resume', 'delete'] as $action) {
                rejects(fn () => $journal->submit('operator', new Request(['version' => 1, 'request_id' => Json::uuid(),
                    'workspace_id' => $request['workspace_id'], 'tenant' => 'test', 'action' => $action])), 'runtime_outcome_unconfirmed');
            }
        }
        $journal->change($op['id'], static function (array &$o, array &$w): void { unset($o['runtime_tracking'], $o['runtime_intent']); });
        same('unknown', $engine->status('operator', $op['id'])['stop_observation']['state']);
        rejects(fn () => $journal->retry('operator', $op['id']), 'runtime_outcome_unconfirmed');
    });
});

test('cleanup never targets changed inventory and status is read only', function (): void {
    withFailedInitialization(function ($journal, $runtime, $engine, $request, $op, $config, $dir): void {
        $data = $config->data; $data['hosts']['lab']['remote'] = 'another';
        $changed = new Engine(new Config($data), $journal, $runtime, new Vault($dir, $dir . '/key'));
        $journal->change($op['id'], static function (array &$o, array &$w): void { $o['cleanup_next_at'] = 0; });
        $runtime->calls = [];
        $changed->tick();
        same([], $runtime->calls);
        $result = $changed->status('operator', $op['id']);
        same('unknown', $result['stop_observation']['state']); same('inventory_changed', $result['stop_observation']['error']);
        same([], $runtime->calls);
        rejects(fn () => $engine->status('unknown', $op['id']), 'forbidden');
    });
});

test('a worker lost after an acknowledged mutation performs cleanup before original retry', function (): void {
    withFailedInitialization(function ($journal, $runtime, $engine, $request, $op): void {
        $journal->change($op['id'], static function (array &$o, array &$w): void {
            $o['status'] = 'running';
            $o['runtime_intent'] = ['kind' => 'start', 'state' => 'accepted', 'operation' => '/1.0/operations/' . Json::uuid()];
        });
        $runtime->calls = []; $runtime->physical = 'running';
        same('failed', $engine->tick()['status']);
        same(['stop'], $runtime->calls);
        same('settled', $journal->operation($op['id'])['runtime_intent']['state']);
        same('stopped', $engine->status('operator', $op['id'])['stop_observation']['state']);
        $journal->retry('operator', $op['id']);
        same('succeeded', $engine->tick()['status']);
        same(1, $journal->operation($op['id'])['generation']);
    });
});

/** Target-scoped synthetic transport; no real host, Incus executable or guest is used. */
final class StopTransport implements IncusTransport
{
    public array $calls = [];
    public array $instance;
    public mixed $instances;
    public mixed $operations = [];
    public array $operation = ['status_code' => 200];
    public bool $loseStop = false;
    public bool $unreachable = false;
    public function __construct(array $workspace, Config $config)
    {
        $path = '/1.0/instances/' . $workspace['instance'];
        $this->instances = [$path];
        $this->instance = ['status' => 'Running', 'config' => ['user.wp.owner' => $config->data['installation_id'], 'user.wp.workspace' => $workspace['id']]];
    }
    public function request(string $remote, string $project, string $method, string $path, ?array $body = null): array
    {
        same('local', $remote); same('jcb-test', $project);
        $this->calls[] = [$method, $path, $body];
        if ($this->unreachable) { throw new Fault('incus_request_failed', 'Synthetic outage.'); }
        if ($method === 'GET') {
            if ($path === '/1.0/instances') { return ['metadata' => $this->instances]; }
            if ($path === '/1.0/operations?recursion=1') { return ['metadata' => $this->operations]; }
            if (str_starts_with($path, '/1.0/operations/')) { return ['metadata' => $this->operation]; }
            if (str_ends_with($path, '/state')) { return ['metadata' => ['status_code' => $this->instance['status'] === 'Stopped' ? 102 : 103]]; }
            return ['metadata' => $this->instance];
        }
        same('PUT', $method); same('stop', $body['action']);
        $this->instance['status'] = 'Stopped';
        if ($this->loseStop) { throw new Fault('incus_request_failed', 'Synthetic lost stop reply.'); }
        return ['type' => 'sync'];
    }
    public function command(string $project, array $arguments, string $stdin = '', int $timeout = 60): array { throw new RuntimeException('No guest commands permitted.'); }
    public function upload(string $remote, string $project, string $instance, string $path, string $content, string $mode = '0600'): void { throw new RuntimeException('No uploads permitted.'); }
}

test('physical observation distinguishes running stopped absent unreachable and unresolved work', function (): void {
    withFailedInitialization(function ($journal, $memory, $engine, $request, $op, $config): void {
        $w = $journal->workspace($request['workspace_id']);
        $transport = new StopTransport($w, $config);
        $runtime = new IncusRuntime($config, $transport, dirname(__DIR__, 2));
        same('running', $runtime->observe($w)['state']);
        $transport->loseStop = true;
        rejects(fn () => $runtime->suspend($w), 'incus_request_failed');
        same('stopped', $runtime->observe($w)['state']); // loss of a stop reply is reobserved
        $transport->instances = [];
        same('absent', $runtime->observe($w)['state']);
        $pending = ['kind' => 'create', 'state' => 'pending', 'operation' => null];
        same('unknown', $runtime->observe($w, $pending)['state']);
        $accepted = ['kind' => 'start', 'state' => 'accepted', 'operation' => '/1.0/operations/' . Json::uuid()];
        $transport->operation['status_code'] = 103;
        same('unknown', $runtime->observe($w, $accepted)['state']);
        $transport->operation['status_code'] = 400;
        same('absent', $runtime->observe($w, $accepted)['state']);
        $transport->operations = ['running' => [['status_code' => 103, 'resources' => ['instances' => ['/1.0/instances/' . $w['instance']]]]]];
        rejects(fn () => $runtime->observe($w), 'runtime_operation_pending');
        $transport->operations = ['running' => [['status_code' => 103, 'resources' => ['instances' => ['/1.0/instances/unrelated']]]]];
        same('absent', $runtime->observe($w)['state']);
        $transport->operations = ['running' => [['status_code' => 103, 'resources' => []]]];
        rejects(fn () => $runtime->observe($w), 'runtime_operation_unclassified');
        $transport->operations = []; $transport->instances = null;
        rejects(fn () => $runtime->observe($w), 'invalid_incus_response');
        $transport->instances = ['/1.0/instances/' . $w['instance']];
        $transport->instance['config']['user.wp.workspace'] = Json::uuid();
        rejects(fn () => $runtime->observe($w), 'ownership_conflict');
        $before = count($transport->calls);
        rejects(fn () => $runtime->suspend($w), 'ownership_conflict');
        same([], array_filter(array_slice($transport->calls, $before), fn ($c) => $c[0] !== 'GET'));
        $transport->unreachable = true;
        rejects(fn () => $runtime->observe($w), 'incus_request_failed');
        // The sole write was a targeted stop, never delete, bulk state, or infrastructure changes.
        $writes = array_values(array_filter($transport->calls, fn ($c) => $c[0] !== 'GET'));
        same(1, count($writes));
        same('/1.0/instances/' . $w['instance'] . '/state', $writes[0][1]);
    });
});

test('delayed cleanup stops cannot race an initialization retry or superseding lifecycle', function (): void {
    withFailedInitialization(function ($journal, $runtime, $engine, $request, $op): void {
        $original = $journal->operation($op['id']);
        $journal->change($op['id'], static function (array &$o, array &$w): void {
            $o['cleanup_intent'] = ['kind' => 'stop', 'state' => 'pending', 'operation' => null]; $o['cleanup_next_at'] = 0;
        });
        $runtime->physical = 'stopped'; $runtime->calls = [];
        same('failed', $engine->tick()['status']);
        same([], $runtime->calls); // do not overwrite the unresolved stop by submitting another
        same('unknown', $engine->status('operator', $op['id'])['stop_observation']['state']);
        rejects(fn () => $journal->retry('operator', $op['id']), 'runtime_outcome_unconfirmed');
        foreach (['suspend', 'resume', 'delete'] as $action) {
            rejects(fn () => $journal->submit('operator', new Request(['version' => 1, 'request_id' => Json::uuid(),
                'workspace_id' => $request['workspace_id'], 'tenant' => 'test', 'action' => $action])), 'runtime_outcome_unconfirmed');
        }
        same($original['generation'], $journal->workspace($request['workspace_id'])['generation']);
        // A recorded exact acknowledged stop can settle, after which retry is safe again.
        $journal->change($op['id'], static function (array &$o, array &$w): void {
            $o['cleanup_intent'] = ['kind' => 'stop', 'state' => 'accepted', 'operation' => '/1.0/operations/' . Json::uuid()]; $o['cleanup_next_at'] = 0;
        });
        $engine->tick();
        same('settled', $journal->operation($op['id'])['cleanup_intent']['state']);
        $journal->retry('operator', $op['id']);
        same('succeeded', $engine->tick()['status']);
    });
});

test('lost stop replies retain their own intent even when a physical snapshot is stopped', function (): void {
    withFailedInitialization(function ($journal, $memory, $engine, $request, $op, $config): void {
        $w = $journal->workspace($request['workspace_id']);
        $transport = new StopTransport($w, $config); $transport->loseStop = true;
        $runtime = new IncusRuntime($config, $transport, dirname(__DIR__, 2));
        $events = [];
        rejects(function () use ($runtime, $w, &$events): void {
            $runtime->suspend($w, function ($event) use (&$events): void { $events[] = $event; });
        }, 'incus_request_failed');
        same(['kind' => 'stop', 'state' => 'pending', 'operation' => null], end($events));
        same('Stopped', $transport->instance['status']);
        same('unknown', $runtime->observe($w, null, end($events))['state']);
        $accepted = ['kind' => 'stop', 'state' => 'accepted', 'operation' => '/1.0/operations/' . Json::uuid()];
        $transport->operation['status_code'] = 103;
        same('unknown', $runtime->observe($w, null, $accepted)['state']);
        $transport->operation['status_code'] = 200;
        same('stopped', $runtime->observe($w, null, $accepted)['state']);
        // An unresolved start cannot hide that the independent stop operation did settle.
        $start = ['kind' => 'start', 'state' => 'pending', 'operation' => null];
        $r = $runtime->observe($w, $start, $accepted);
        same('unknown', $r['state']); same(false, $r['intent_settled']); same(true, $r['cleanup_settled']);
    });
});

test('durable work selection fairly alternates queued work and permanently due cleanups', function (): void {
    withFailedInitialization(function ($journal, $runtime, $engine, $request, $op): void {
        $otherFailed = Json::uuid(); $normal = Json::uuid();
        $journal->store->transaction(static function (array &$s) use ($op, $otherFailed, $normal): void {
            $failed = $s['operations'][$op['id']]; $failed['cleanup_next_at'] = 0;
            $s['operations'][$op['id']] = $failed;
            $workspace = $s['workspaces'][$failed['workspace_id']];
            foreach ([$otherFailed, $normal] as $id) {
                $wid = Json::uuid(); $s['workspaces'][$wid] = [...$workspace, 'id' => $wid];
                $s['operations'][$id] = [...$failed, 'id' => $id, 'workspace_id' => $wid];
            }
            $s['operations'][$normal]['action'] = 'suspend'; $s['operations'][$normal]['status'] = 'queued';
            $s['last_execution'] = 'operation';
        });
        $runtime->cannotStop = true;
        $first = $engine->tick(); same('create', $first['action']);
        // Simulate cleanup calls so slow they are due again by the next worker restart.
        $journal->store->transaction(static function (array &$s): void {
            foreach ($s['operations'] as &$candidate) { if ($candidate['action'] === 'create') { $candidate['cleanup_next_at'] = 0; } }
        });
        same($normal, $engine->tick()['id']); // even with multiple still-due failed creates
        same('create', $engine->tick()['action']);
        $s = $journal->store->transaction(static fn (array &$s): array => $s);
        same('cleanup', $s['last_execution']);
    });
});

final class InterleaveStopRuntime extends MemoryRuntime
{
    public Closure $beforeIntent;
    public int $physicalWrites = 0;
    public function suspend(array $w, ?callable $observe = null): void
    {
        ($this->beforeIntent)();
        $observe(['kind' => 'stop', 'state' => 'pending', 'operation' => null]);
        ++$this->physicalWrites;
        $observe(['kind' => 'stop', 'state' => 'settled', 'operation' => null]);
    }
}

test('cleanup atomically fences retry and new generations before its physical stop intent', function (): void {
    foreach (['retry', 'suspend'] as $action) {
        withFailedInitialization(function ($journal, $runtime, $engine, $request, $op, $config, $dir) use ($action): void {
            $racing = new InterleaveStopRuntime();
            $racing->beforeIntent = static function () use ($journal, $request, $op, $action): void {
                if ($action === 'retry') { $journal->retry('operator', $op['id']); }
                else { $journal->submit('operator', new Request(['version' => 1, 'request_id' => Json::uuid(),
                    'workspace_id' => $request['workspace_id'], 'tenant' => 'test', 'action' => 'suspend'])); }
            };
            $journal->change($op['id'], static function (array &$o, array &$w): void { $o['cleanup_next_at'] = 0; });
            (new Engine($config, $journal, $racing, new Vault($dir, $dir . '/key')))->tick();
            same(0, $racing->physicalWrites);
            same(false, isset($journal->operation($op['id'])['cleanup_intent']));
            if ($action === 'retry') { same('queued', $journal->operation($op['id'])['status']); }
            else { same(2, $journal->workspace($request['workspace_id'])['generation']); }
        });
    }
});
