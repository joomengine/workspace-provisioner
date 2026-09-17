<?php

declare(strict_types=1);

require '/opt/wp/lib/bootstrap.php';

use JoomEngine\Workspace\{Fault, Files, Json, Process, Validate};

try {
    if (posix_geteuid() === 0) { throw new Fault('root_forbidden', 'Composer must run as the workload identity.'); }
    $input = Json::decode(stream_get_contents(STDIN, 1048577));
    Validate::object($input, ['directory', 'dev', 'replace', 'timeout', 'manifest', 'lock', 'auth']);
    if (!is_bool($input['dev']) || !is_bool($input['replace'])) { throw new Fault('invalid_composer', 'Invalid flags.'); }
    $relative = Validate::text($input['directory'], 512);
    if ($relative !== '.' && !preg_match('~^[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*$~D', $relative)) {
        throw new Fault('invalid_composer', 'Invalid Composer working directory.');
    }
    $timeout = Validate::integer($input['timeout'], 1, 1800);
    $target = '/var/www/html';
    foreach ($relative === '.' ? [] : explode('/', $relative) as $segment) {
        $target .= '/' . $segment;
        if (is_link($target) || (file_exists($target) && !is_dir($target))) { throw new Fault('unsafe_path', 'Unsafe Composer directory.'); }
        if (!is_dir($target) && !mkdir($target, 0755)) { throw new Fault('file_write_failed', 'Cannot create Composer directory.'); }
    }
    foreach (['manifest' => 'composer.json', 'lock' => 'composer.lock'] as $key => $filename) {
        Json::decode($input[$key]);
        $path = $target . '/' . $filename;
        if (!$input['replace'] && is_file($path) && !hash_equals(hash('sha256', $input[$key]), hash_file('sha256', $path))) {
            throw new Fault('composer_conflict', 'Existing Composer files differ; replacement requires explicit operator configuration.');
        }
        Files::write($path, $input[$key], 0644);
    }
    $home = '/tmp/composer-' . bin2hex(random_bytes(16));
    if (!mkdir($home, 0700)) { throw new Fault('file_write_failed', 'Cannot create temporary Composer home.'); }
    Files::write($home . '/auth.json', Json::encode($input['auth']));
    // This entire one-off container, including /tmp, is removed after execution.
    $environment = ['HOME' => $home, 'COMPOSER_HOME' => $home];
    $process = new Process();
    $base = ['/usr/local/bin/composer', '--no-interaction', '--no-plugins', '--no-scripts', '--no-cache', '--working-dir', $target];
    $process->requireSuccess([...$base, 'validate', '--check-lock', '--no-check-publish'], '', $timeout, $environment);
    $flags = $input['dev'] ? [] : ['--no-dev'];
    $process->requireSuccess([...$base, 'install', '--prefer-dist', '--no-progress', ...$flags], '', $timeout, $environment);
    $process->requireSuccess([...$base, 'check-platform-reqs', ...$flags], '', $timeout, $environment);
    if (!is_file($target . '/vendor/autoload.php')) { throw new Fault('composer_incomplete', 'Composer autoloader is missing.'); }
    unlink($home . '/auth.json');
    echo Json::encode(['installed' => true, 'lock_sha256' => hash('sha256', $input['lock'])]);
} catch (Throwable $error) {
    fwrite(STDERR, Json::encode(['error' => $error instanceof Fault ? $error->reason : 'composer_failed']));
    exit(1);
}
