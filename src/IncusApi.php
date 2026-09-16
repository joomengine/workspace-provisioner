<?php

declare(strict_types=1);

namespace JoomEngine\Workspace;

final readonly class IncusApi
{
    public function __construct(public IncusTransport $transport, public string $remote, public string $project)
    {
    }

    public function get(string $path): mixed
    {
        $response = $this->transport->request($this->remote, $this->project, 'GET', $path);
        if (!array_key_exists('metadata', $response)) {
            throw new Fault('invalid_incus_response', 'Incus response has no metadata.');
        }
        return $response['metadata'];
    }

    public function find(string $collection, string $name): ?array
    {
        foreach ($this->get($collection) as $url) {
            if (!is_string($url)) { throw new Fault('invalid_incus_response', 'Expected an Incus resource URL list.'); }
            $path = parse_url($url, PHP_URL_PATH);
            if (is_string($path) && rawurldecode(basename($path)) === $name) {
                return $this->get($collection . '/' . rawurlencode($name));
            }
        }
        return null;
    }

    public function mutate(string $method, string $path, ?array $body = null, ?callable $observe = null): void
    {
        $response = $this->transport->request($this->remote, $this->project, $method, $path, $body);
        if (($response['type'] ?? '') !== 'async') { return; }
        $operation = $response['operation'] ?? '';
        if (!is_string($operation) || !preg_match('~^/1\.0/operations/[0-9a-f-]{36}$~D', $operation)) {
            throw new Fault('invalid_incus_response', 'Missing asynchronous operation identifier.');
        }
        if ($observe !== null) { $observe($operation); }
        $this->wait($operation);
    }

    public function wait(string $operation, int $timeout = 1800): void
    {
        if (!preg_match('~^/1\.0/operations/[0-9a-f-]{36}$~D', $operation)) {
            throw new Fault('invalid_operation', 'Invalid Incus operation path.');
        }
        $start = hrtime(true);
        do {
            $result = $this->get($operation);
            $code = $result['status_code'] ?? 0;
            if ($code === 200) { return; }
            if ($code >= 400) { throw new Fault('incus_operation_failed', 'Incus asynchronous operation failed.'); }
            usleep(250000);
        } while ((hrtime(true) - $start) / 1e9 < $timeout);
        throw new Fault('incus_operation_timeout', 'Incus operation remains unresolved; inspect before retrying.');
    }

    public static function owned(array $resource, string $owner, ?string $workspace = null): void
    {
        $config = $resource['config'] ?? [];
        if (($config['user.wp.owner'] ?? '') !== $owner
            || ($workspace !== null && ($config['user.wp.workspace'] ?? '') !== $workspace)) {
            throw new Fault('ownership_conflict', 'Refusing to adopt, modify or delete an unowned resource.');
        }
    }
}
