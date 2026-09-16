<?php

declare(strict_types=1);

namespace JoomEngine\Workspace;

final class Json
{
    public static function decode(string $input): array
    {
        if (strlen($input) > 8 * 1024 * 1024) {
            throw new Fault('input_too_large', 'JSON exceeds the permitted size.');
        }
        try {
            $value = json_decode($input, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new Fault('invalid_json', 'Invalid JSON document.');
        }
        if (!is_array($value)) {
            throw new Fault('invalid_json', 'A JSON object or array is required.');
        }
        return $value;
    }

    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    }

    public static function hash(mixed $value): string
    {
        return hash('sha256', self::encode(self::canonical($value)));
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        return array_map(self::canonical(...), $value);
    }

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 15) | 64);
        $b[8] = chr((ord($b[8]) & 63) | 128);
        $h = bin2hex($b);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4)
            . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
    }
}
