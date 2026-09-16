<?php

declare(strict_types=1);

namespace JoomEngine\Workspace;

final readonly class Journal
{
    public function __construct(public Store $store, private Config $config)
    {
        $store->transaction(function (array &$state): void {
            if (($state['version'] ?? null) !== 1) {
                throw new Fault('invalid_state', 'Unsupported operation store version.');
            }
            if ($state['installation_id'] !== null && $state['installation_id'] !== $this->config->data['installation_id']) {
                throw new Fault('ownership_conflict', 'Operation store belongs to another installation.');
            }
            $state['installation_id'] = $this->config->data['installation_id'];
        });
    }

    public function submit(string $caller, Request $request): array
    {
        $r = $request->data;
        $this->config->authorize($caller, $r['tenant'], $r['action']);
        // Validate private recipe before allocating anything, without storing its contents.
        $recipe = $r['action'] === 'create' ? $this->config->recipe($r['catalog']) : null;
        return $this->store->transaction(function (array &$state) use ($caller, $request, $r, $recipe): array {
            $key = hash('sha256', $caller . ':' . $r['request_id']);
            if (isset($state['requests'][$key])) {
                $operation = $state['operations'][$state['requests'][$key]];
                if (!hash_equals($operation['request_hash'], $request->hash)) {
                    throw new Fault('idempotency_conflict', 'Request ID already exists with different content.');
                }
                return $this->publicOperation($operation);
            }
            $id = $r['workspace_id'];
            if ($r['action'] === 'create') {
                if (isset($state['workspaces'][$id])) {
                    throw new Fault('workspace_exists', 'Workspace ID has already been reserved.');
                }
                foreach ($state['workspaces'] as $workspace) {
                    if ($workspace['name'] === $r['name']) {
                        throw new Fault('name_reserved', 'Workspace name is already reserved, including retired names.');
                    }
                }
                $profile = $this->config->data['profiles'][$r['profile']] ?? throw new Fault('unknown_profile', 'Unknown resource profile.');
                [$host, $address] = $this->allocate($state, $profile);
                $entry = $this->config->data['catalog'][$r['catalog']];
                $state['workspaces'][$id] = ['id' => $id, 'name' => $r['name'], 'tenant' => $r['tenant'],
                    'instance' => 'ws-' . str_replace('-', '', $id), 'host' => $host, 'address' => $address,
                    'host_hash' => Json::hash($this->config->data['hosts'][$host]), 'generation' => 0,
                    'resources' => $profile, 'catalog' => $r['catalog'], 'catalog_hash' => Json::hash($entry),
                    'recipe_hash' => $recipe->hash, 'status' => 'reserved', 'desired' => 'ready',
                    'handed_over' => false, 'ssh_keys' => $r['ssh_keys'], 'admin_email' => $r['admin_email'],
                    'steps' => [], 'result' => null, 'created_at' => gmdate(DATE_ATOM)];
            } else {
                $workspace = $state['workspaces'][$id] ?? throw new Fault('not_found', 'Workspace not found.');
                if ($workspace['tenant'] !== $r['tenant']) {
                    throw new Fault('forbidden', 'Workspace belongs to a different tenant.');
                }
                if ($workspace['status'] === 'deleted' && $r['action'] !== 'delete') {
                    throw new Fault('invalid_state', 'Deleted workspaces cannot be reused.');
                }
                foreach ($state['operations'] as $operation) {
                    if ($operation['workspace_id'] === $id && in_array($operation['status'], ['queued', 'running'], true)) {
                        throw new Fault('busy', 'A workspace operation is already pending.');
                    }
                }
            }
            $generation = ++$state['workspaces'][$id]['generation'];
            $operationId = Json::uuid();
            $operation = ['id' => $operationId, 'caller' => $caller, 'workspace_id' => $id,
                'generation' => $generation, 'request_hash' => $request->hash, 'request' => $r, 'action' => $r['action'], 'status' => 'queued',
                'error' => null, 'stage' => 'queued', 'created_at' => gmdate(DATE_ATOM), 'updated_at' => gmdate(DATE_ATOM)];
            $state['operations'][$operationId] = $operation;
            $state['requests'][$key] = $operationId;
            return $this->publicOperation($operation);
        });
    }

    private function allocate(array $state, array $profile): array
    {
        foreach ($this->config->data['hosts'] as $name => $host) {
            $used = ['cpu' => 0, 'memory_mib' => 0, 'disk_gib' => 0];
            $addresses = [];
            foreach ($state['workspaces'] as $workspace) {
                if ($workspace['host'] !== $name) { continue; }
                // Never silently reuse a retired address: stale routes and clients may still refer to it.
                $addresses[] = $workspace['address'];
                if ($workspace['status'] !== 'deleted') {
                    foreach ($used as $key => $_) { $used[$key] += $workspace['resources'][$key]; }
                }
            }
            foreach ($used as $key => $value) {
                if ($value + $profile[$key] > $host[$key . '_budget']) { continue 2; }
            }
            foreach ($host['addresses'] as $address) {
                if (!in_array($address, $addresses, true)) { return [$name, $address]; }
            }
        }
        throw new Fault('capacity_exhausted', 'No approved host has an available address and resource budget.');
    }

    public function operation(string $id): array
    {
        Validate::uuid($id);
        return $this->store->transaction(static fn (array &$s): array => $s['operations'][$id]
            ?? throw new Fault('not_found', 'Operation not found.'));
    }

    public function workspace(string $id): array
    {
        Validate::uuid($id);
        return $this->store->transaction(static fn (array &$s): array => $s['workspaces'][$id]
            ?? throw new Fault('not_found', 'Workspace not found.'));
    }

    public function change(string $operationId, callable $callback): mixed
    {
        return $this->store->transaction(static function (array &$s) use ($operationId, $callback): mixed {
            if (!isset($s['operations'][$operationId])) { throw new Fault('not_found', 'Operation not found.'); }
            $operation = &$s['operations'][$operationId];
            $workspace = &$s['workspaces'][$operation['workspace_id']];
            $result = $callback($operation, $workspace);
            $operation['updated_at'] = gmdate(DATE_ATOM);
            return $result;
        });
    }

    public function pending(): array
    {
        return $this->store->transaction(static fn (array &$s): array => array_values(array_filter($s['operations'],
            static fn (array $op): bool => in_array($op['status'], ['queued', 'running'], true))));
    }

    public function status(string $caller, string $operationId): array
    {
        $operation = $this->operation($operationId);
        $this->config->authorize($caller, $operation['request']['tenant'], 'status');
        $result = $this->publicOperation($operation);
        $workspace = $this->workspace($operation['workspace_id']);
        $result['workspace_status'] = $workspace['status'];
        $result['connection'] = $workspace['status'] === 'ready' ? $workspace['result'] : null;
        return $result;
    }

    public function retry(string $caller, string $operationId): array
    {
        $operation = $this->operation($operationId);
        $this->config->authorize($caller, $operation['request']['tenant'], 'retry');
        $this->config->authorize($operation['caller'], $operation['request']['tenant'], $operation['action']);
        return $this->store->transaction(function (array &$s) use ($operationId): array {
            $op = &$s['operations'][$operationId];
            if ($s['workspaces'][$op['workspace_id']]['status'] === 'deleted'
                || $s['workspaces'][$op['workspace_id']]['generation'] !== $op['generation']) {
                throw new Fault('stale_operation', 'A later operation superseded this request.');
            }
            if ($op['status'] !== 'failed') { throw new Fault('invalid_state', 'Only failed operations can be retried.'); }
            foreach ($s['operations'] as $other) {
                if ($other['workspace_id'] === $op['workspace_id'] && $other['id'] !== $op['id']
                    && in_array($other['status'], ['queued', 'running'], true)) {
                    throw new Fault('busy', 'Another workspace operation is pending.');
                }
            }
            $op['status'] = 'queued';
            $op['error'] = null;
            return $this->publicOperation($op);
        });
    }

    private function publicOperation(array $op): array
    {
        return array_intersect_key($op, array_flip(['id', 'workspace_id', 'action', 'status', 'stage', 'error', 'created_at', 'updated_at']));
    }
}
