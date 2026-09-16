<?php

declare(strict_types=1);

namespace JoomEngine\Workspace;

/** Uses the official client for TLS trust, Unix sockets, file transfer and exec transport. */
final readonly class Incus implements IncusTransport
{
    public function __construct(private array $settings, private Process $process = new Process())
    {
    }

    public function request(string $remote, string $project, string $method, string $path, ?array $body = null): array
    {
        Validate::name($remote);
        Validate::name($project);
        if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE'], true) || !str_starts_with($path, '/1.0')) {
            throw new Fault('invalid_api_call', 'Unsupported Incus API operation.');
        }
        // Raw query paths need explicit project scoping, independent of client defaults.
        $path .= (str_contains($path, '?') ? '&' : '?') . 'project=' . rawurlencode($project);
        $arguments = ['query', $remote . ':' . $path, '--request', $method, '--raw'];
        if ($body !== null) { $arguments = [...$arguments, '--data-file', '-']; }
        $result = $this->command($project, $arguments, $body === null ? '' : Json::encode($body));
        if ($result['exit'] !== 0) {
            throw new Fault('incus_request_failed', 'Incus request failed; private response details were suppressed.');
        }
        $response = Json::decode($result['stdout']);
        if (($response['type'] ?? '') === 'error' || ($response['error_code'] ?? 0) >= 400) {
            throw new Fault('incus_request_failed', 'Incus rejected the request.');
        }
        return $response;
    }

    public function command(string $project, array $arguments, string $stdin = '', int $timeout = 60): array
    {
        Validate::name($project);
        return $this->process->run([$this->settings['binary'], '--project', $project, ...$arguments], $stdin, $timeout,
            ['INCUS_CONF' => $this->settings['config_directory'], 'HOME' => $this->settings['config_directory']]);
    }

    public function upload(string $remote, string $project, string $instance, string $path, string $content, string $mode = '0600'): void
    {
        Validate::name($remote);
        Validate::name($instance);
        Validate::path($path);
        if (!in_array($mode, ['0600', '0644', '0755'], true)) {
            throw new Fault('invalid_mode', 'Unsupported guest file mode.');
        }
        $result = $this->command($project, ['file', 'push', '-', $remote . ':' . $instance . $path,
            '--uid', '0', '--gid', '0', '--mode', $mode], $content);
        if ($result['exit'] !== 0) {
            throw new Fault('guest_transfer_failed', 'Unable to transfer the target-scoped guest file.');
        }
    }
}
