<?php

declare(strict_types=1);

namespace JoomEngine\Workspace;

/** Release planning is deterministic; it never mutates a remote repository. */
final class Release
{
    public static function version(string $version): string
    {
        if (!preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-(?:dev|rc)\.[0-9]+(?:\.[a-f0-9]+)?)?$/D', $version)) {
            throw new Fault('invalid_version', 'Expected a semantic release version.');
        }
        return $version;
    }

    public static function next(?string $previous, array $messages): string
    {
        if ($messages === []) { throw new Fault('no_changes', 'There are no commits to release.'); }
        if ($previous === null) { return '0.1.0'; }
        self::version($previous);
        if (str_contains($previous, '-')) { throw new Fault('invalid_version', 'The previous stable release must not be a prerelease.'); }
        $parts = array_map(intval(...), explode('.', $previous));
        $level = 2;
        foreach ($messages as $message) {
            if (preg_match('/^[a-z]+(?:\([^\r\n)]+\))?!:|^BREAKING[ -]CHANGE:/m', $message)) { $level = 0; break; }
            if (preg_match('/^feat(?:\([^\r\n)]+\))?:/', $message)) { $level = min($level, 1); }
        }
        ++$parts[$level];
        for ($i = $level + 1; $i < 3; ++$i) { $parts[$i] = 0; }
        return implode('.', $parts);
    }

    public static function manifest(string $version, string $commit, int $timestamp): array
    {
        self::version($version);
        if (!preg_match('/^[a-f0-9]{40}$/D', $commit) || $timestamp < 0) {
            throw new Fault('invalid_release', 'Release source identity is invalid.');
        }
        return ['schema_version' => 1, 'name' => 'joomengine/workspace-provisioner',
            'version' => $version, 'tag' => 'v' . $version, 'commit' => $commit,
            'source_date_epoch' => $timestamp, 'prerelease' => str_contains($version, '-'),
            'entrypoint' => 'bin/workspace', 'configuration' => 'external-operator-files',
            'qualification' => 'See the release qualification check; packaging alone is not VM qualification.'];
    }
}
