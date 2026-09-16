<?php

declare(strict_types=1);

namespace JoomEngine\Workspace;

final class Validate
{
    public static function object(mixed $value, array $required, array $optional = []): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new Fault('invalid_object', 'An object is required.');
        }
        if (array_diff($required, array_keys($value)) || array_diff(array_keys($value), [...$required, ...$optional])) {
            throw new Fault('invalid_fields', 'Required fields are missing or unknown fields were supplied.');
        }
        return $value;
    }

    public static function text(mixed $value, int $max = 255): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > $max || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new Fault('invalid_text', 'Invalid or oversized string.');
        }
        return $value;
    }

    public static function name(mixed $value): string
    {
        $value = self::text($value, 63);
        if (!preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $value)) {
            throw new Fault('invalid_name', 'Names must be lowercase ASCII identifiers beginning with a letter.');
        }
        return $value;
    }

    public static function uuid(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value)) {
            throw new Fault('invalid_id', 'A canonical UUID is required.');
        }
        return $value;
    }

    public static function integer(mixed $value, int $min, int $max): int
    {
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new Fault('invalid_integer', 'Integer outside the permitted range.');
        }
        return $value;
    }

    public static function list(mixed $value, int $min = 0, int $max = 256): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) < $min || count($value) > $max) {
            throw new Fault('invalid_list', 'Invalid list or list length.');
        }
        return $value;
    }

    public static function digest(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[a-f0-9]{64}$/D', $value)) {
            throw new Fault('invalid_digest', 'A complete SHA-256 digest is required.');
        }
        return $value;
    }

    public static function image(mixed $value): string
    {
        $value = self::text($value, 300);
        if (!preg_match('~^[a-z0-9][a-z0-9._:/-]*@sha256:[a-f0-9]{64}$~D', $value)) {
            throw new Fault('unpinned_image', 'A Docker image reference pinned by SHA-256 digest is required.');
        }
        return $value;
    }

    public static function ip(mixed $value): string
    {
        if (!is_string($value) || filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new Fault('invalid_ip', 'An IPv4 address is required.');
        }
        return $value;
    }

    public static function cidr(mixed $value): string
    {
        $parts = explode('/', self::text($value));
        if (count($parts) !== 2 || !ctype_digit($parts[1]) || (int) $parts[1] > 32) {
            throw new Fault('invalid_cidr', 'An IPv4 CIDR is required.');
        }
        self::ip($parts[0]);
        return $value;
    }

    public static function contains(string $cidr, string $ip): bool
    {
        [$network, $bits] = explode('/', self::cidr($cidr));
        self::ip($ip);
        $mask = (int) $bits === 0 ? 0 : (0xffffffff << (32 - (int) $bits)) & 0xffffffff;
        return (ip2long($network) & $mask) === (ip2long($ip) & $mask);
    }

    public static function path(mixed $value): string
    {
        $value = self::text($value, 4096);
        if (!str_starts_with($value, '/') || preg_match('~(?:^|/)\.{1,2}(?:/|$)|//~', $value)) {
            throw new Fault('invalid_path', 'An absolute normalized path is required.');
        }
        return $value;
    }

    public static function keys(mixed $value): array
    {
        $keys = [];
        foreach (self::list($value, 1, 16) as $key) {
            $parts = preg_split('/ +/', self::text($key, 4096), 3);
            if (count($parts) < 2 || $parts[0] !== 'ssh-ed25519') {
                throw new Fault('invalid_ssh_key', 'Only plain Ed25519 public keys without authorized_keys options are accepted.');
            }
            $raw = base64_decode($parts[1], true);
            $prefix = pack('N', 11) . 'ssh-ed25519' . pack('N', 32);
            if ($raw === false || strlen($raw) !== strlen($prefix) + 32 || !str_starts_with($raw, $prefix)) {
                throw new Fault('invalid_ssh_key', 'Malformed Ed25519 public key.');
            }
            $keys[] = 'ssh-ed25519 ' . base64_encode($raw);
        }
        return array_values(array_unique($keys));
    }

    public static function argv(mixed $value): array
    {
        $args = self::list($value, 1, 128);
        foreach ($args as $arg) {
            if (!is_string($arg) || strlen($arg) > 8192 || str_contains($arg, "\0")) {
                throw new Fault('invalid_argv', 'Invalid command argument.');
            }
        }
        if ($args[0] === '') {
            throw new Fault('invalid_argv', 'An executable is required.');
        }
        return $args;
    }
}
