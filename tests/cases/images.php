<?php

declare(strict_types=1);

use JoomEngine\Workspace\{ImageResolver, Json};

test('floating image selectors resolve to manifest digests without changing pinned choices', function (): void {
    $manifest = '{"schemaVersion":2,"manifests":[]}';
    $calls = [];
    $resolver = new ImageResolver([], static function (string $ref) use (&$calls, $manifest): string { $calls[] = $ref; return $manifest; });
    $pinned = 'registry.example.test:5000/team/db@sha256:' . str_repeat('a', 64);
    $images = $resolver->resolve(['jcb_image' => 'example/jcb:latest', 'database_image' => $pinned,
        'development_image' => 'registry.example.test:5000/team/development:v2']);
    same('example/jcb@sha256:' . hash('sha256', $manifest), $images['jcb_image']);
    same($pinned, $images['database_image']);
    same('registry.example.test:5000/team/development@sha256:' . hash('sha256', $manifest), $images['development_image']);
    same(2, count($calls));
    foreach (['example/jcb', 'https://example/a:latest', '--authfile=x', 'a/../b:latest', 'a/b:latest;id'] as $bad) {
        rejects(fn () => ImageResolver::selector($bad), 'invalid_image');
    }
});
