<?php

declare(strict_types=1);

use JoomEngine\Workspace\{Files, Json, Recipe, Request, Vault};

function fixtureKey(): string
{
    return 'ssh-ed25519 ' . base64_encode(pack('N', 11) . 'ssh-ed25519' . pack('N', 32) . str_repeat('b', 32));
}
function fixtureRequest(): array
{
    return ['version' => 1, 'request_id' => Json::uuid(), 'workspace_id' => Json::uuid(),
        'tenant' => 'test', 'action' => 'create', 'name' => 'test-workspace', 'catalog' => 'standard',
        'profile' => 'small', 'admin_email' => 'developer@example.test', 'ssh_keys' => [fixtureKey()]];
}
test('requests reject privilege-bearing fields and invalid transitions', function (): void {
    $r = fixtureRequest();
    same('create', (new Request($r))->data['action']);
    rejects(fn () => new Request($r + ['privileged' => true]), 'invalid_fields');
    $r['action'] = 'exec';
    rejects(fn () => new Request($r), 'invalid_action');
});
test('recipes require postconditions and explicit VM authority', function (): void {
    $step = ['id' => 'cache', 'target' => 'joomla', 'argv' => ['cache:clean'],
        'timeout' => 30, 'repeat' => 'safe', 'check' => ['argv' => ['list'], 'stdout' => 'expected']];
    $doc = ['version' => 1, 'id' => 'test', 'steps' => [$step]];
    same(1, count((new Recipe($doc))->steps('joomla')));
    $doc['steps'][0]['target'] = 'vm';
    rejects(fn () => new Recipe($doc), 'invalid_recipe');
    $doc['steps'] = [$step, $step];
    rejects(fn () => new Recipe($doc), 'invalid_recipe');
    unset($step['check']);
    $doc['steps'] = [$step];
    rejects(fn () => new Recipe($doc), 'invalid_fields');
});
test('credential vault encrypts, resumes, reveals once and rejects symlinks', function (): void {
    $dir = sys_get_temp_dir() . '/jcb-vault-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    try {
        Files::write($dir . '/key', bin2hex(random_bytes(32)));
        $v = new Vault($dir, $dir . '/key');
        $id = Json::uuid();
        $c = $v->credentials($id);
        same($c, $v->credentials($id));
        same(false, str_contains(file_get_contents($dir . '/' . $id . '.json'), $c['admin_password']));
        same($c['admin_password'], $v->reveal($id)['admin_password']);
        rejects(fn () => $v->reveal($id), 'already_revealed');
        symlink($dir . '/key', $dir . '/link');
        rejects(fn () => Files::readPrivate($dir . '/link'), 'unsafe_path');
        $v->forget($id);
        same(false, is_file($dir . '/' . $id . '.json'));
    } finally {
        foreach (glob($dir . '/*') as $file) { unlink($file); }
        @unlink($dir . '/.lock');
        rmdir($dir);
    }
});
