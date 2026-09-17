<?php

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

use JoomEngine\Workspace\{Fault, Files, Json, Process, Validate};

const ROOT = '/opt/jcb-workspace';
$process = new Process();
$run = static fn (array $args, string $input = '', int $timeout = 120): string => $process->requireSuccess($args, $input, $timeout);
$compose = static fn (array $args, string $input = '', int $timeout = 120): string =>
    $run(['/usr/bin/docker', 'compose', '--project-name', 'jcb-workspace', '-f', ROOT . '/compose.json', ...$args], $input, $timeout);
try {
    if (posix_geteuid() !== 0) { throw new Fault('root_required', 'Guest management requires the platform identity.'); }
    $action = $argv[1] ?? '';
    $settings = Json::decode(Files::readPrivate(ROOT . '/workspace.json'));
    $uid = Validate::integer($settings['uid'], 1, 60000);
    $gid = Validate::integer($settings['gid'], 1, 60000);
    $identity = $uid . ':' . $gid;
    $exec = static fn (string $service, array $args, int $timeout = 120): string =>
        $compose(['exec', '-T', '--user', $identity, $service, ...$args], '', $timeout);
    $ready = static function (string $service) use ($exec): array {
        $data = Json::decode($exec($service, ['/usr/local/bin/php', '/opt/wp/probe.php']));
        if (($data['ready'] ?? false) !== true) { throw new Fault('not_ready', 'JCB is not ready.'); }
        return $data;
    };
    $response = ['ok' => true];
    switch ($action) {
        case 'prepare':
            if (is_file(ROOT . '/handed-over')) { throw new Fault('handed_over', 'Bootstrap cannot run after customer handover.'); }
            foreach (['/srv/jcb', '/srv/jcb/site', '/srv/jcb/database', '/srv/jcb/home', '/srv/jcb/build',
                ROOT . '/secrets', ROOT . '/host-keys', ROOT . '/authorized-keys'] as $path) {
                if (is_link($path)) { throw new Fault('unsafe_path', 'Refusing a symbolic-link guest directory.'); }
                $run(['/usr/bin/install', '-d', '-m', str_starts_with($path, ROOT) ? '0700' : '0755', $path]);
            }
            $run(['/usr/bin/chown', $identity, '/srv/jcb/site', '/srv/jcb/home', '/srv/jcb/build']);
            chmod(ROOT . '/authorized-keys', 0755);
            if (!is_file(ROOT . '/host-keys/ssh_host_ed25519_key')) {
                $run(['/usr/bin/ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', ROOT . '/host-keys/ssh_host_ed25519_key']);
            }
            // Match the approved NIC by MAC rather than trusting the guest's interface naming.
            $mac = $settings['mac'];
            if (!preg_match('/^02(?::[0-9a-f]{2}){5}$/D', $mac)) { throw new Fault('invalid_mac', 'Invalid guest NIC identity.'); }
            $address = Validate::ip($settings['address']);
            $gateway = Validate::ip($settings['gateway']);
            $prefix = Validate::integer($settings['prefix'], 16, 28);
            $dns = array_map(Validate::ip(...), Validate::list($settings['dns'], 1, 3));
            $network = "[Match]\nMACAddress={$mac}\n\n[Network]\nDHCP=no\nAddress={$address}/{$prefix}\nGateway={$gateway}\nIPv6AcceptRA=no\nLinkLocalAddressing=no\n";
            foreach ($dns as $server) { $network .= "DNS={$server}\n"; }
            Files::write('/etc/systemd/network/10-jcb-workspace.network', $network, 0644);
            $run(['/usr/bin/systemctl', 'enable', '--now', 'systemd-networkd.service']);
            $run(['/usr/bin/networkctl', 'reload']);
            $found = false;
            foreach (glob('/sys/class/net/*/address') as $file) {
                if (trim(file_get_contents($file)) === $mac) {
                    $run(['/usr/bin/networkctl', 'reconfigure', basename(dirname($file))]);
                    $found = true;
                }
            }
            if (!$found) { throw new Fault('missing_nic', 'Approved workspace NIC was not found.'); }
            $run(['/usr/bin/systemctl', 'daemon-reload']);
            break;
        case 'initialize':
            if (is_file(ROOT . '/handed-over')) { throw new Fault('handed_over', 'Initialization is forbidden after handover.'); }
            $compose(['pull'], '', 1800);
            $compose(['up', '-d', '--wait', '--wait-timeout', '300', 'database'], '', 360);
            if (!is_file('/srv/jcb/site/configuration.php')) {
                $compose(['up', '-d', '--no-deps', 'installer'], '', 120);
                $complete = false;
                for ($i = 0; $i < 180; ++$i) {
                    try {
                        $ready('installer');
                        $exec('installer', ['/usr/local/bin/php', '-r', 'exit(@fsockopen("127.0.0.1",80)?0:1);']);
                        $complete = true;
                        break;
                    } catch (Fault) { sleep(2); }
                }
                if (!$complete && !is_file('/srv/jcb/site/configuration.php')) {
                    throw new Fault('installation_failed', 'Initial Joomla installation did not complete.');
                }
            }
            // Never run a second root installer after configuration.php exists.
            $compose(['rm', '--stop', '--force', 'installer']);
            $compose(['up', '-d', '--no-deps', 'web'], '', 120);
            try {
                $ready('web');
            } catch (Fault) {
                // Recover a pre-handover partial JCB installation as the workload identity.
                $exec('web', ['/usr/local/bin/php', '/var/www/html/cli/joomla.php',
                    'extension:install', '--path', '/usr/src/joomengine/jcb.zip', '--no-interaction'], 600);
                $ready('web');
            }
            break;
        case 'composer':
        case 'composer-cleanup':
            $cleanup = static function () use ($process): void {
                $result = $process->run(['/usr/bin/docker', 'container', 'ls', '-a', '--filter', 'name=^/jcb-workspace-composer$', '--format', '{{.ID}}']);
                if ($result['exit'] !== 0) { throw new Fault('composer_cleanup_failed', 'Unable to inspect the temporary dependency container.'); }
                if (trim($result['stdout']) !== '') {
                    $process->requireSuccess(['/usr/bin/docker', 'rm', '--force', 'jcb-workspace-composer']);
                }
            };
            $cleanup();
            if ($action === 'composer-cleanup') { break; }
            if (is_file(ROOT . '/handed-over')) { throw new Fault('handed_over', 'Dependency bootstrap is forbidden after handover.'); }
            $payload = Json::decode(stream_get_contents(STDIN, 1048577));
            $timeout = Validate::integer($payload['timeout'] ?? null, 1, 1800);
            try {
                $result = Json::decode($compose(['run', '--rm', '--no-deps', '-T', '--name', 'jcb-workspace-composer', 'composer'],
                    Json::encode($payload), $timeout));
                if (($result['installed'] ?? false) !== true) { throw new Fault('composer_incomplete', 'Dependency installation was not verified.'); }
            } finally { $cleanup(); }
            break;
        case 'start':
            $compose(['up', '-d', '--wait', '--wait-timeout', '300', 'database', 'web', 'development'], '', 360);
            break;
        case 'verify':
            $web = $ready('web');
            $dev = $ready('development');
            if ($web !== $dev) { throw new Fault('runtime_mismatch', 'Development and web PHP runtimes differ.'); }
            if (trim($exec('development', ['/usr/bin/id', '-u'])) !== (string) $uid
                || trim($exec('development', ['/usr/bin/id', '-g'])) !== (string) $gid) {
                throw new Fault('identity_mismatch', 'Development filesystem identity is incorrect.');
            }
            $compose(['exec', '-T', 'development', '/usr/sbin/sshd', '-t']);
            $run(['/usr/bin/curl', '--fail', '--silent', '--max-time', '15', '--output', '/dev/null', 'http://' . $settings['address'] . '/']);
            $fingerprint = trim($run(['/usr/bin/ssh-keygen', '-lf', ROOT . '/host-keys/ssh_host_ed25519_key.pub', '-E', 'sha256']));
            $response += ['php' => $web['php'], 'ssh_host_fingerprint' => $fingerprint,
                'ssh_host_public_key' => trim(file_get_contents(ROOT . '/host-keys/ssh_host_ed25519_key.pub'))];
            break;
        case 'handover':
            Files::write(ROOT . '/handed-over', "1\n");
            foreach (['admin_password', 'admin_username'] as $name) {
                $path = ROOT . '/secrets/' . $name;
                if (is_file($path)) { unlink($path); }
            }
            $run(['/usr/bin/systemctl', 'enable', 'jcb-workspace.service']);
            break;
        case 'rekey':
            $new = Validate::keys(Json::decode(stream_get_contents(STDIN)));
            Files::write(ROOT . '/authorized-keys/developer', implode("\n", $new) . "\n", 0644);
            $compose(['restart', 'development']);
            break;
        case 'quiesce':
            $compose(['stop', '--timeout', '30'], '', 120);
            break;
        default:
            throw new Fault('invalid_action', 'Unsupported guest helper action.');
    }
    echo Json::encode($response);
} catch (Throwable $error) {
    fwrite(STDERR, Json::encode(['error' => $error instanceof Fault ? $error->reason : 'guest_failure']));
    exit(1);
}
