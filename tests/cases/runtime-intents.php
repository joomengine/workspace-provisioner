<?php

declare(strict_types=1);

use JoomEngine\Workspace\{Fault, Host, IncusRuntime, Journal, FileStore, Json, Request};

final class IntentTransport extends RecoveryTransport
{
    public ?string $loseReply = null;
    public ?Closure $beforeMutation = null;
    public bool $async = false;
    public string $operationId;
    public function __construct() { $this->operationId = '/1.0/operations/' . Json::uuid(); }
    public function request(string $remote, string $project, string $method, string $path, ?array $body = null): array
    {
        if ($method === 'GET' && $path === $this->operationId) { return ['metadata' => ['status_code' => 200]]; }
        $kind = $method === 'POST' && $path === '/1.0/instances' ? 'create'
            : ($method === 'PUT' && str_ends_with($path, '/state') && $body['action'] === 'start' ? 'start' : null);
        if ($kind !== null && $this->beforeMutation !== null) { ($this->beforeMutation)($kind); }
        $result = parent::request($remote, $project, $method, $path, $body);
        if ($kind === $this->loseReply && $kind !== null) { throw new Fault('incus_request_failed', 'Synthetic lost physical mutation reply.'); }
        return $kind !== null && $this->async ? ['type' => 'async', 'operation' => $this->operationId] : $result;
    }
}

test('create and power intent precede transport and retain exact acknowledged operation identity', function (): void {
    withFailedInitialization(function ($journal, $memory, $engine, $request, $op, $config): void {
        $w = $journal->workspace($request['workspace_id']);
        $transport = new IntentTransport();
        (new Host($config, $transport))->prepare('lab', $config->data['installation_id']);
        $runtime = new IncusRuntime($config, $transport, dirname(__DIR__, 2));
        $events = [];
        $transport->async = true;
        $transport->beforeMutation = function ($kind) use (&$events): void { same(['kind' => $kind, 'state' => 'pending', 'operation' => null], end($events)); };
        $runtime->ensure($w, function (array $event) use (&$events): void { $events[] = $event; });
        same(['pending', 'accepted', 'settled', 'pending', 'accepted', 'settled'], array_column($events, 'state'));
        same(['create', 'create', 'create', 'start', 'start', 'start'], array_column($events, 'kind'));
        same($transport->operationId, $events[1]['operation']); same($transport->operationId, $events[4]['operation']);
        foreach (['create', 'start'] as $kind) {
            $transport = new IntentTransport();
            (new Host($config, $transport))->prepare('lab', $config->data['installation_id']);
            $runtime = new IncusRuntime($config, $transport, dirname(__DIR__, 2));
            $transport->loseReply = $kind; $events = [];
            rejects(function () use ($runtime, $w, &$events): void {
                $runtime->ensure($w, function (array $event) use (&$events): void { $events[] = $event; });
            }, 'incus_request_failed');
            same(['kind' => $kind, 'state' => 'pending', 'operation' => null], end($events));
            same('unknown', $runtime->observe($w, end($events))['state']);
        }
    });
});

test('failure to persist mutation intent prevents the physical write', function (): void {
    withFailedInitialization(function ($journal, $memory, $engine, $request, $op, $config): void {
        $w = $journal->workspace($request['workspace_id']);
        $transport = new IntentTransport();
        (new Host($config, $transport))->prepare('lab', $config->data['installation_id']);
        $runtime = new IncusRuntime($config, $transport, dirname(__DIR__, 2));
        $transport->calls = [];
        rejects(fn () => $runtime->ensure($w, static function (): void { throw new Fault('state_unavailable', 'Synthetic persistence failure.'); }), 'state_unavailable');
        same([], array_values(array_filter($transport->calls, fn ($call) => $call[0] === 'POST')));
    });
});
