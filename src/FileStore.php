<?php

declare(strict_types=1);

namespace JoomEngine\Workspace;

/** Local development/single-controller store. Not suitable for NFS or multiple control hosts. */
final readonly class FileStore implements Store
{
    public function __construct(private string $path)
    {
        Files::protectedPath($path);
    }

    public function transaction(callable $callback): mixed
    {
        return Files::lock($this->path . '.lock', function () use ($callback): mixed {
            $state = is_file($this->path) ? Json::decode(Files::readPrivate($this->path, 8388608)) : self::emptyState();
            $result = $callback($state);
            Files::write($this->path, Json::encode($state));
            return $result;
        });
    }

    public function exclusive(callable $callback): mixed
    {
        return Files::lock($this->path . '.executor', $callback, true);
    }

    public static function emptyState(): array
    {
        return ['version' => 1, 'installation_id' => null, 'workspaces' => [], 'operations' => [], 'requests' => []];
    }
}
