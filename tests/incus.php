#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/LabPlan.php';

use JoomEngine\Workspace\{Application, Config, Fault, Files, Incus, Json, Process, Validate};
use JoomEngine\Workspace\Testing\LabPlan;

// This is an opt-in operator test, never a hosted PR workflow or an implementation gate.
$report = ['version' => 1, 'kind' => 'actual-incus-lifecycle', 'started_at' => gmdate(DATE_ATOM), 'checks' => [],
    'operator_acceptance_remaining' => ['compute-host reboot drill', 'active spoofing and resource stress', 'security review']];
$app = null; $workspaces = []; $directory = null; $session = null; $reportPath = null; $failed = false;
$record = static function (string $name, string $result = 'pass') use (&$report): void { $report['checks'][] = compact('name', 'result'); };
$assert = static function (bool $ok): void { if (!$ok) { throw new Fault('lab_assertion_failed', 'A lab assertion failed; no private command output is printed.'); } };
try {
    if (getenv('WP_RUN_INCUS_TESTS') !== '1' || $argc !== 3) {
        throw new Fault('lab_opt_in', 'Usage: WP_RUN_INCUS_TESTS=1 php tests/incus.php PRIVATE_LAB_PLAN NEW_PRIVATE_REPORT');
    }
    $reportPath = Validate::path($argv[2]);
    Files::protectedPath($reportPath);
    if (file_exists($reportPath) || is_link($reportPath)) { throw new Fault('already_exists', 'Report output must not exist.'); }
    $data = Json::decode(Files::readPrivate($argv[1]));
    $config = Config::load($data['operator'] ?? '');
    $plan = new LabPlan($data, $config);
    $app = new Application($config, dirname(__DIR__));
    $assert($app->store->transaction(static fn (array &$state): bool => $state['workspaces'] === [] && $state['operations'] === []));
    $process = new Process();
    $transport = new Incus($config->data['incus']);
    $manifest = dirname(__DIR__) . '/release.json';
    $report['source_commit'] = is_file($manifest) ? Json::decode(file_get_contents($manifest))['commit']
        : trim($process->requireSuccess(['/usr/bin/git', '-C', dirname(__DIR__), 'rev-parse', 'HEAD']));
    $report['php'] = PHP_VERSION;
    foreach ($config->data['hosts'] as $name => $host) {
        $app->host->preflight($name);
        $app->host->verify($name); // Do not initialize or alter lab hosts here.
        $environment = $transport->request($host['remote'], $host['project'], 'GET', '/1.0')['metadata']['environment'];
        $report['hosts'][] = ['policy_hash' => Json::hash($host), 'incus' => $environment['server_version'] ?? null,
            'kernel' => $environment['kernel_version'] ?? null, 'driver' => $environment['driver'] ?? null,
            'driver_version' => $environment['driver_version'] ?? null];
    }
    $record('host-preflight-and-existing-owned-policy');
    $directory = sys_get_temp_dir() . '/wp-incus-lab-' . bin2hex(random_bytes(12));
    if (!mkdir($directory, 0700)) { throw new Fault('lab_setup_failed', 'Cannot create private test staging.'); }
    foreach (['original', 'replacement'] as $key) {
        $process->requireSuccess(['/usr/bin/ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', $directory . '/' . $key]);
    }
    $keys = ['original' => trim(file_get_contents($directory . '/original.pub')),
        'replacement' => trim(file_get_contents($directory . '/replacement.pub'))];
    $act = static function (string $id, string $action, array $extra = []) use ($app, $data, $assert): array {
        $request = ['version' => 1, 'request_id' => Json::uuid(), 'workspace_id' => $id, 'tenant' => $data['tenant'], 'action' => $action] + $extra;
        $op = $app->submit($data['caller'], $request);
        $assert($app->submit($data['caller'], $request)['id'] === $op['id']);
        $done = $app->engine->tick();
        $assert(($done['id'] ?? null) === $op['id'] && $done['status'] === 'succeeded');
        return $app->journal->status($data['caller'], $op['id']);
    };
    $sshArguments = static function (array $w, string $key = 'original') use ($directory): array {
        return ['/usr/bin/ssh', '-F', '/dev/null', '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', 'StrictHostKeyChecking=yes',
            '-o', 'UserKnownHostsFile=' . $directory . '/known_hosts', '-o', 'ConnectTimeout=5',
            '-o', 'ServerAliveInterval=2', '-o', 'ServerAliveCountMax=2', '-i', $directory . '/' . $key,
            '-p', '2222', 'developer@' . $w['address']];
    };
    $shell = static function (array $w, string $program, string $key = 'original', int $timeout = 120) use ($process, $sshArguments): string {
        return $process->requireSuccess([...$sshArguments($w, $key), 'php'], "<?php\n" . $program, $timeout);
    };
    $root = static function (array $w, array $command) use ($transport, $config, $assert): string {
        $h = $config->data['hosts'][$w['host']];
        $result = $transport->command($h['project'], ['exec', $h['remote'] . ':' . $w['instance'], '--mode', 'non-interactive',
            '--', '/usr/bin/timeout', '--kill-after=5s', '120s', ...$command], '', 140);
        $assert($result['exit'] === 0);
        return $result['stdout'];
    };
    $marker = 'wp-lab-' . bin2hex(random_bytes(8));
    $value = bin2hex(random_bytes(16));
    for ($i = 0; $i < $data['instances']; ++$i) {
        $id = Json::uuid(); $workspaces[] = $id; // Track even failed creation for ownership-scoped cleanup.
        $status = $act($id, 'create', ['name' => 'lab-' . bin2hex(random_bytes(8)), 'catalog' => $data['catalog'],
            'profile' => $data['profile'], 'admin_email' => 'lab@example.invalid', 'ssh_keys' => [$keys['original']]]);
        $w = $app->journal->workspace($id);
        $key = Validate::keys([$status['connection']['ssh_host_public_key']])[0];
        file_put_contents($directory . '/known_hosts', '[' . $w['address'] . ']:2222 ' . $key . "\n", FILE_APPEND);
        chmod($directory . '/known_hosts', 0600);
        $report['images'][] = ['vm' => $config->data['catalog'][$data['catalog']]['vm_image'],
            'containers' => array_map(static fn ($image) => substr($image, strpos($image, '@') + 1), $w['resolved_images']),
            'recipe_hash' => $w['recipe_hash']];
        $assert(trim($shell($w, 'echo posix_getuid() > 0 && !file_exists("/var/run/docker.sock") && !file_exists("/opt/jcb-workspace/workspace.json") ? "ok" : "bad";')) === 'ok');
        $shell($w, 'file_put_contents(' . var_export('/var/www/html/' . $marker . '.txt', true) . ', ' . var_export($value, true) . ');');
        $response = $process->requireSuccess(['/usr/bin/curl', '--fail', '--silent', '--show-error', '--noproxy', '*', '--max-time', '10',
            $status['connection']['web_url'] . $marker . '.txt']);
        $assert($response === $value);
    }
    $record('create-idempotency-container-only-ssh-and-shared-web-files');
    $ws = array_map($app->journal->workspace(...), $workspaces);
    $assert(count(array_unique(array_map(static fn ($w) => $w['result']['ssh_host_public_key'], $ws))) === count($ws));
    $record('distinct-workspace-ssh-host-identities');
    $first = $ws[0];
    Files::write($directory . '/sftp-source', $value);
    $process->requireSuccess(['/usr/bin/sftp', '-F', '/dev/null', '-q', '-b', '-', '-P', '2222', '-i', $directory . '/original',
        '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', 'StrictHostKeyChecking=yes',
        '-o', 'UserKnownHostsFile=' . $directory . '/known_hosts', 'developer@' . $first['address']],
        'put ' . $directory . '/sftp-source /var/www/html/' . $marker . ".sftp\nget /var/www/html/" . $marker . '.sftp ' . $directory . "/sftp-copy\n", 60);
    $assert(file_get_contents($directory . '/sftp-copy') === $value);
    $record('sftp-round-trip');
    // The only configurable application commands execute as the customer, never guest root.
    foreach ($data['application_checks'] ?? [] as $check) {
        foreach (['argv', 'verify_argv'] as $field) {
            $timeout = $check['timeout'] ?? 600;
            $command = ['/usr/bin/timeout', '--kill-after=5s', $timeout . 's', '/usr/local/bin/php', '/var/www/html/cli/joomla.php', ...$check[$field]];
            $program = '$p = proc_open(' . var_export($command, true) . ', [0=>["file","/dev/null","r"],1=>STDOUT,2=>STDERR], $pipes); exit(proc_close($p));';
            $out = $shell($first, $program, 'original', $timeout + 15);
            if ($field === 'verify_argv' && isset($check['stdout'])) { $assert(trim($out) === $check['stdout']); }
        }
        if (isset($check['frontend_path'])) {
            $body = $process->requireSuccess(['/usr/bin/curl', '--fail', '--silent', '--show-error', '--noproxy', '*', '--max-time', '30',
                'http://' . $first['address'] . $check['frontend_path']]);
            $assert(str_contains($body, $check['frontend_contains']));
        }
    }
    $record('operator-selected-jcb-import-compile-install-checks', empty($data['application_checks']) ? 'skip' : 'pass');
    // Reachability baseline comes from the authorized management client; failure is never counted as isolation.
    $sameHost = false; $crossHost = false;
    foreach ($ws as $from) {
        foreach ($ws as $to) {
            if ($from['id'] === $to['id']) { continue; }
            $target = 'tcp://' . $to['address'] . ':80';
            $live = @stream_socket_client($target, $errno, $error, 5); $assert(is_resource($live)); fclose($live);
            $program = '$s=@stream_socket_client(' . var_export($target, true) . ', $n, $e, 3); echo is_resource($s)?"open":"blocked";';
            $assert(trim($shell($from, $program)) === 'blocked');
            $assert(trim($root($from, ['/usr/bin/php', '-r', $program])) === 'blocked');
            if ($from['host'] === $to['host']) { $sameHost = true; } else { $crossHost = true; }
        }
    }
    $record('same-host-denial-from-shell-and-guest-root', $sameHost ? 'pass' : 'skip');
    $record('cross-host-denial-from-shell-and-guest-root', $crossHost ? 'pass' : 'skip');
    foreach ($data['denied_targets'] ?? [] as $target) {
        $address = str_contains($target['address'], ':') ? '[' . $target['address'] . ']' : $target['address'];
        $endpoint = 'tcp://' . $address . ':' . $target['port'];
        $live = @stream_socket_client($endpoint, $errno, $error, 5); $assert(is_resource($live)); fclose($live);
        $program = '$s=@stream_socket_client(' . var_export($endpoint, true) . ', $n, $e, 3); echo is_resource($s)?"open":"blocked";';
        $assert(trim($shell($first, $program)) === 'blocked');
        $assert(trim($root($first, ['/usr/bin/php', '-r', $program])) === 'blocked');
    }
    $record('known-live-management-or-ipv6-targets-denied', empty($data['denied_targets']) ? 'skip' : 'pass');
    // A synthetic DB table lets a restore prove application data, not merely file existence.
    $table = 'wp_lab_' . bin2hex(random_bytes(8));
    $db = 'require "/var/www/html/configuration.php"; $c=new JConfig(); $d=new mysqli($c->host,$c->user,$c->password,$c->db);';
    $shell($first, $db . '$d->query("CREATE TABLE ' . $table . ' (id INT PRIMARY KEY, value VARCHAR(64)) ENGINE=InnoDB"); $d->query("INSERT INTO ' . $table . ' VALUES (1,\'' . $value . '\')");');
    $backup = $act($first['id'], 'backup');
    $assert($backup['last_backup']['encrypted'] === true);
    $act($first['id'], 'replace-keys', ['ssh_keys' => [$keys['replacement']]]);
    $assert($process->run([...$sshArguments($first), 'true'], '', 15)['exit'] !== 0);
    $shell($first, 'file_put_contents(' . var_export('/var/www/html/' . $marker . '.txt', true) . ', "changed");' . $db . '$d->query("UPDATE ' . $table . ' SET value=\'changed\'");', 'replacement');
    $session = proc_open([...$sshArguments($first, 'replacement'), 'printf started; sleep 120'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $directory . '/session', 'w'], 2 => ['file', $directory . '/session-error', 'w']], $pipes);
    $assert(is_resource($session));
    for ($i = 0; $i < 100 && @file_get_contents($directory . '/session') !== 'started'; ++$i) { usleep(100000); }
    $assert(file_get_contents($directory . '/session') === 'started');
    $act($first['id'], 'suspend');
    for ($i = 0; $i < 100 && proc_get_status($session)['running']; ++$i) { usleep(100000); }
    $assert(!proc_get_status($session)['running']); proc_close($session); $session = null;
    $record('key-revocation-and-existing-session-termination');
    $restored = $act($first['id'], 'restore', ['backup_id' => $backup['last_backup']['id'], 'replace_existing' => true]);
    $assert($restored['workspace_status'] === 'suspended' && $restored['connection'] === null);
    $act($first['id'], 'resume');
    $assert(trim($shell($first, 'echo file_get_contents(' . var_export('/var/www/html/' . $marker . '.txt', true) . ');', 'replacement')) === $value);
    $assert(trim($shell($first, $db . 'echo $d->query("SELECT value FROM ' . $table . ' WHERE id=1")->fetch_row()[0];', 'replacement')) === $value);
    $assert($process->run([...$sshArguments($first), 'true'], '', 15)['exit'] !== 0);
    $record('encrypted-restore-file-database-and-current-key-preservation');
    $h = $config->data['hosts'][$first['host']];
    $root($first, ['/usr/bin/docker', 'compose', '-p', 'jcb-workspace', '-f', '/opt/jcb-workspace/compose.json', 'restart']);
    $act($first['id'], 'reconcile');
    $transport->command($h['project'], ['stop', $h['remote'] . ':' . $first['instance'], '--force']);
    $act($first['id'], 'reconcile');
    $assert(trim($shell($first, $db . 'echo $d->query("SELECT value FROM ' . $table . ' WHERE id=1")->fetch_row()[0];', 'replacement')) === $value);
    $record('container-and-vm-restart-persistence-without-reinstallation');
} catch (Throwable $error) {
    $failed = true;
    $report['error'] = $error instanceof Fault ? $error->reason : 'lab_failed';
} finally {
    if (is_resource($session)) { proc_terminate($session); proc_close($session); }
    if ($app !== null) {
        foreach (array_reverse($workspaces) as $id) {
            try {
                $workspace = $app->journal->workspace($id);
                // Restrict cleanup to IDs created by this run. Runtime verifies ownership before deletion.
                $act($id, 'delete');
                $record('owned-workspace-cleanup');
            } catch (Throwable) { $failed = true; $record('owned-workspace-cleanup', 'fail'); }
        }
    }
    if ($directory !== null && is_dir($directory)) {
        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $file) { if ($file->isFile()) { unlink($file->getPathname()); } }
        rmdir($directory);
    }
    $report['finished_at'] = gmdate(DATE_ATOM);
    $report['result'] = $failed ? 'fail' : 'pass';
    $report['complete_security_qualification'] = false;
    if ($reportPath !== null && !file_exists($reportPath) && !is_link($reportPath)) {
        try { Files::write($reportPath, Json::encode($report)); } catch (Throwable) { $failed = true; }
    }
    echo Json::encode(['result' => $failed ? 'fail' : 'pass', 'checks' => count($report['checks']),
        'message' => $failed ? 'Lab test failed or was not authorized; inspect the private report.' : 'Lifecycle run completed; inspect individual skips and remaining human acceptance.']);
}
exit($failed ? 1 : 0);
