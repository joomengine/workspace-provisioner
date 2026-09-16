<?php

declare(strict_types=1);

namespace JoomEngine\Workspace;

interface IncusTransport
{
    public function request(string $remote, string $project, string $method, string $path, ?array $body = null): array;
    public function command(string $project, array $arguments, string $stdin = '', int $timeout = 60): array;
    public function upload(string $remote, string $project, string $instance, string $path, string $content, string $mode = '0600'): void;
}
