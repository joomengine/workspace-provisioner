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

test('registry resolution scopes auth explicitly and cleans anonymous credentials', function (): void {
    $dir = sys_get_temp_dir() . '/wp-image-test-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    try {
        $report = $dir . '/report.json';
        $program = '#!' . PHP_BINARY . "\n<?php\n" . '$index = array_search("--authfile", $argv, true);'
            . 'if ($index === false) { exit(2); } $path = $argv[$index + 1];'
            . 'file_put_contents(' . var_export($report, true) . ', json_encode(["path"=>$path,"data"=>file_get_contents($path),"mode"=>(fileperms($path)&0777)]));'
            . 'echo \'{"schemaVersion":2,"manifests":[]}\';';
        JoomEngine\Workspace\Files::write($dir . '/registry', $program, 0700);
        $pinned = 'example/pinned@sha256:' . str_repeat('a', 64);
        $catalog = ['jcb_image' => 'example/public:latest', 'database_image' => $pinned, 'development_image' => $pinned];
        (new ImageResolver(['binary' => $dir . '/registry']))->resolve($catalog);
        $result = Json::decode(file_get_contents($report));
        same('{"auths":{}}', $result['data']);
        same(0600, $result['mode']);
        same(false, file_exists($result['path']));
        same(false, is_dir(dirname($result['path'])));
        JoomEngine\Workspace\Files::write($dir . '/auth.json', '{"auths":{"example.test":{}}}');
        (new ImageResolver(['binary' => $dir . '/registry', 'auth_file' => $dir . '/auth.json']))->resolve($catalog);
        $result = Json::decode(file_get_contents($report));
        same($dir . '/auth.json', $result['path']);
        same(true, file_exists($result['path']));
    } finally {
        foreach (glob($dir . '/*') as $path) { unlink($path); }
        rmdir($dir);
    }
});
