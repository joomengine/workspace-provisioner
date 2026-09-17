#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use JoomEngine\Workspace\{Fault, Json, Process, Release};

try {
    $process = new Process();
    $git = static fn (array $args): string => trim($process->requireSuccess(['/usr/bin/git', '-C', dirname(__DIR__), ...$args]));
    $mode = $argv[1] ?? '';
    if ($mode === 'version') { echo Release::version($argv[2] ?? '') . "\n"; exit(0); }
    $commit = $git(['rev-parse', 'HEAD']);
    if ($mode === 'manifest') {
        echo Json::encode(Release::manifest($argv[2] ?? '', $commit, (int) $git(['show', '-s', '--format=%ct', 'HEAD'])));
        exit(0);
    }
    if ($mode !== 'plan') { throw new Fault('usage', 'Use release.php plan, version VERSION, or manifest VERSION.'); }
    $tags = array_values(array_filter(explode("\n", $git(['tag', '--merged', 'HEAD'])),
        static fn (string $tag): bool => (bool) preg_match('/^v(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)$/D', $tag)));
    usort($tags, static fn (string $a, string $b): int => version_compare(substr($a, 1), substr($b, 1)));
    $previous = $tags === [] ? null : end($tags);
    if ($previous !== null && $git(['rev-list', '-n', '1', $previous]) === $commit) {
        echo Json::encode(['release' => false, 'tag' => $previous, 'version' => substr($previous, 1), 'commit' => $commit]);
        exit(0);
    }
    $messages = array_values(array_filter(explode("\0", $git(['log', '--format=%B%x00', $previous === null ? 'HEAD' : $previous . '..HEAD'])),
        static fn (string $message): bool => trim($message) !== ''));
    $version = Release::next($previous === null ? null : substr($previous, 1), array_map(trim(...), $messages));
    echo Json::encode(['release' => true, 'version' => $version, 'tag' => 'v' . $version, 'commit' => $commit]);
} catch (Throwable $error) {
    fwrite(STDERR, Json::encode(['error' => $error instanceof Fault ? $error->reason : 'release_failed']));
    exit(1);
}
