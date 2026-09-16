<?php

declare(strict_types=1);

namespace JoomEngine\Workspace;

final class Files
{
    public static function protectedPath(string $path, bool $directory = false): void
    {
        Validate::path($path);
        $current = $directory ? $path : dirname($path);
        while ($current !== '/') {
            $s = @lstat($current);
            $stickyRoot = $s !== false && ($s['mode'] & 01000) && $s['uid'] === 0;
            if ($s === false || is_link($current) || !is_dir($current)
                || (!in_array($s['uid'], [0, posix_geteuid()], true))
                || (($s['mode'] & 0022) !== 0 && !$stickyRoot)) {
                throw new Fault('unsafe_path', 'Private paths must have trusted owners and non-writable parents.');
            }
            $current = dirname($current);
        }
        if (is_link($path)) {
            throw new Fault('unsafe_path', 'Symbolic links are not allowed for private files.');
        }
    }

    public static function readPrivate(string $path, int $limit = 1048576): string
    {
        self::protectedPath($path);
        $s = @lstat($path);
        if ($s === false || ($s['mode'] & 0170000) !== 0100000 || ($s['mode'] & 0077) !== 0
            || !in_array($s['uid'], [0, posix_geteuid()], true) || $s['size'] > $limit) {
            throw new Fault('unsafe_file', 'Private files must be regular, owner-only files within size limits.');
        }
        $data = @file_get_contents($path, false, null, 0, $limit + 1);
        if ($data === false || strlen($data) > $limit) {
            throw new Fault('file_read_failed', 'Unable to read private file.');
        }
        return $data;
    }

    public static function write(string $path, string $data, int $mode = 0600): void
    {
        self::protectedPath($path);
        $temp = dirname($path) . '/.write-' . bin2hex(random_bytes(16));
        $old = umask(0077);
        $stream = @fopen($temp, 'xb');
        umask($old);
        if ($stream === false) {
            throw new Fault('file_write_failed', 'Unable to create private staging file.');
        }
        try {
            for ($offset = 0; $offset < strlen($data); $offset += $written) {
                $written = fwrite($stream, substr($data, $offset));
                if ($written === false || $written === 0) {
                    throw new Fault('file_write_failed', 'Unable to write private staging file.');
                }
            }
            if (!fflush($stream) || !fsync($stream) || !chmod($temp, $mode) || !rename($temp, $path)) {
                throw new Fault('file_write_failed', 'Unable to persist private file.');
            }
        } finally {
            fclose($stream);
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }

    public static function lock(string $path, callable $body, bool $nonBlocking = false): mixed
    {
        self::protectedPath($path);
        $old = umask(0077);
        $handle = @fopen($path, 'c+b');
        umask($old);
        if ($handle === false) {
            throw new Fault('lock_failed', 'Unable to open lock.');
        }
        try {
            if (!flock($handle, LOCK_EX | ($nonBlocking ? LOCK_NB : 0))) {
                throw new Fault('busy', 'Another worker owns this operation.');
            }
            return $body();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
