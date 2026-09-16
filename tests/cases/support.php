<?php

declare(strict_types=1);

use JoomEngine\Workspace\{Fault, Json, Process, Validate};

test('canonical hashes ignore object-key order, not list order', function (): void {
    same(Json::hash(['a' => 1, 'b' => 2]), Json::hash(['b' => 2, 'a' => 1]));
    same(false, Json::hash([1, 2]) === Json::hash([2, 1]));
});
test('reject unknown fields and noncanonical identifiers', function (): void {
    rejects(fn () => Validate::object(['a' => 1, 'root' => true], ['a']), 'invalid_fields');
    rejects(fn () => Validate::name('x;id'), 'invalid_name');
    rejects(fn () => Validate::integer('4', 1, 10), 'invalid_integer');
    rejects(fn () => Validate::path('/etc/../tmp/x'), 'invalid_path');
    same(true, Validate::contains('10.77.0.0/24', '10.77.0.11'));
    same(false, Validate::contains('10.77.0.0/24', '10.77.1.11'));
    $id = Json::uuid(); same($id, Validate::uuid($id));
});
test('strict SSH keys strip comments and reject options', function (): void {
    $key = 'ssh-ed25519 ' . base64_encode(pack('N', 11) . 'ssh-ed25519' . pack('N', 32) . str_repeat('a', 32));
    same([$key], Validate::keys([$key . ' comment', $key]));
    rejects(fn () => Validate::keys(['command="id" ' . $key]), 'invalid_ssh_key');
    rejects(fn () => Validate::keys(['ssh-ed25519 YQ==']), 'invalid_ssh_key');
});
test('process arguments and stdin are not evaluated by a shell', function (): void {
    $r = (new Process())->run([PHP_BINARY, '-r', 'echo $argv[1].stream_get_contents(STDIN);', '$(id);*'], 'secret');
    same(0, $r['exit']);
    same('$(id);*secret', $r['stdout']);
});
test('process failures are sanitized and output is bounded', function (): void {
    rejects(fn () => (new Process())->requireSuccess([PHP_BINARY, '-r', 'fwrite(STDERR,"PRIVATE");exit(9);']), 'command_failed');
    rejects(fn () => (new Process())->run([PHP_BINARY, '-r', 'echo str_repeat("x", 2000000);']), 'output_limit');
    rejects(fn () => (new Process())->run([PHP_BINARY, '-r', 'sleep(10);'], '', 1), 'process_timeout');
});
