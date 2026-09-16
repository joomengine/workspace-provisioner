<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$tests = [];
function test(string $name, callable $body): void
{
    global $tests;
    if (isset($tests[$name])) {
        throw new RuntimeException('Duplicate test name.');
    }
    $tests[$name] = $body;
}
function same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Values differ: ' . var_export($expected, true) . ' / ' . var_export($actual, true));
    }
}
function rejects(callable $body, string $reason): void
{
    try {
        $body();
    } catch (JoomEngine\Workspace\Fault $error) {
        same($reason, $error->reason);
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $reason);
}
foreach (glob(__DIR__ . '/cases/*.php') as $file) {
    require $file;
}
$failed = 0;
$started = microtime(true);
foreach ($tests as $name => $body) {
    try {
        $body();
        echo 'PASS ' . $name . "\n";
    } catch (Throwable $error) {
        ++$failed;
        fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . "\n");
    }
}
echo json_encode(['tests' => count($tests), 'failed' => $failed,
    'seconds' => round(microtime(true) - $started, 3)], JSON_THROW_ON_ERROR) . "\n";
exit($failed > 0 || count($tests) === 0 ? 1 : 0);
