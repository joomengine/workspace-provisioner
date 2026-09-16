<?php

declare(strict_types=1);

namespace JoomEngine\Workspace;

final readonly class IncusRuntime implements Runtime
{
    private const ROOT = '/opt/jcb-workspace';

    public function __construct(private Config $config, private IncusTransport $transport, private string $sourceRoot)
    {
    }

    private function host(array $w): array { return $this->config->data['hosts'][$w['host']]; }
    private function catalog(array $w): array { return $this->config->data['catalog'][$w['catalog']]; }
    private function api(array $w): IncusApi
    {
        $h = $this->host($w);
        return new IncusApi($this->transport, $h['remote'], $h['project']);
    }
    private function path(array $w): string { return '/1.0/instances/' . rawurlencode($w['instance']); }

    private function definition(array $w, bool $access): array
    {
        $h = $this->host($w);
        $nic = NetworkPolicy::nic($h, $w);
        $nic['security.acls'] = $h['network'] . ($access ? '-policy' : '-bootstrap');
        return ['type' => 'virtual-machine', 'profiles' => [],
            'config' => ['user.wp.owner' => $this->config->data['installation_id'], 'user.wp.workspace' => $w['id'],
                'user.wp.recipe' => $w['recipe_hash'], 'limits.cpu' => (string) $w['resources']['cpu'],
                'limits.memory' => $w['resources']['memory_mib'] . 'MiB', 'boot.autostart' => 'false',
                'security.secureboot' => 'true'],
            'devices' => ['root' => ['type' => 'disk', 'path' => '/', 'pool' => $h['storage'],
                'size' => $w['resources']['disk_gib'] . 'GiB', 'limits.max' => $w['resources']['io_mib'] . 'MiB'], 'eth0' => $nic]];
    }

    private function instance(array $w, bool $policyCheck = true): array
    {
        $instance = $this->api($w)->get($this->path($w));
        IncusApi::owned($instance, $this->config->data['installation_id'], $w['id']);
        if ($policyCheck) {
            $acl = $instance['devices']['eth0']['security.acls'] ?? '';
            $h = $this->host($w);
            if (!in_array($acl, [$h['network'] . '-policy', $h['network'] . '-bootstrap'], true)) {
                throw new Fault('policy_drift', 'Unexpected workspace ACL.');
            }
            $expected = $this->definition($w, $acl === $h['network'] . '-policy');
            foreach (['type', 'profiles', 'devices'] as $key) {
                if (Json::hash($instance[$key] ?? null) !== Json::hash($expected[$key])) {
                    throw new Fault('policy_drift', 'Workspace type, profiles or devices differ from the approved definition.');
                }
            }
            foreach ($expected['config'] as $key => $value) {
                if (($instance['config'][$key] ?? null) !== $value) { throw new Fault('policy_drift', 'Workspace resource or security policy differs.'); }
            }
            foreach ($instance['config'] as $key => $_) {
                if (!isset($expected['config'][$key]) && !str_starts_with($key, 'volatile.') && !str_starts_with($key, 'image.')) {
                    throw new Fault('policy_drift', 'Unapproved additional instance configuration.');
                }
            }
        }
        return $instance;
    }

    public function ensure(array $workspace, callable $observe): void
    {
        (new Host($this->config, $this->transport))->preflight($workspace['host']);
        (new Host($this->config, $this->transport))->verify($workspace['host']);
        $api = $this->api($workspace);
        $existing = $api->find('/1.0/instances', $workspace['instance']);
        if ($existing === null) {
            if ($workspace['handed_over']) { throw new Fault('workspace_missing', 'Refusing to recreate a handed-over workspace.'); }
            $image = $api->get('/1.0/images/' . $this->catalog($workspace)['vm_image']);
            if (($image['type'] ?? '') !== 'virtual-machine' || ($image['properties']['wp.template.version'] ?? '') !== '1') {
                throw new Fault('unqualified_image', 'Select an image produced by the workspace VM builder.');
            }
            $api->mutate('POST', '/1.0/instances', ['name' => $workspace['instance'],
                ...$this->definition($workspace, false),
                'source' => ['type' => 'image', 'fingerprint' => $this->catalog($workspace)['vm_image']]], $observe);
        }
        $this->instance($workspace);
        $this->power($workspace, true);
    }

    private function power(array $w, bool $running): void
    {
        $instance = $this->instance($w, false);
        if (($instance['status'] ?? '') !== ($running ? 'Running' : 'Stopped')) {
            $this->api($w)->mutate('PUT', $this->path($w) . '/state',
                ['action' => $running ? 'start' : 'stop', 'timeout' => 30, 'force' => !$running]);
        }
        if (($this->instance($w, false)['status'] ?? '') !== ($running ? 'Running' : 'Stopped')) {
            throw new Fault('power_state_failed', 'Workspace did not reach the required power state.');
        }
    }

    private function guest(array $w, array $args, string $stdin = '', int $timeout = 120, bool $lock = true): array
    {
        $h = $this->host($w);
        $bounded = ['/usr/bin/timeout', '--signal=TERM', '--kill-after=5s', $timeout . 's', ...$args];
        if ($lock) { $bounded = ['/usr/bin/flock', '-w', '5', '/run/lock/jcb-workspace.lock', ...$bounded]; }
        return $this->transport->command($h['project'], ['exec', $h['remote'] . ':' . $w['instance'],
            '--mode', 'non-interactive', '--', ...$bounded], $stdin, $timeout + 20);
    }

    private function requiredGuest(array $w, array $args, string $stdin = '', int $timeout = 120): string
    {
        $result = $this->guest($w, $args, $stdin, $timeout);
        if ($result['exit'] !== 0) { throw new Fault('guest_action_failed', 'Guest action failed; private output was suppressed.'); }
        return $result['stdout'];
    }

    private function helper(array $w, string $action, string $input = '', int $timeout = 600): array
    {
        return Json::decode($this->requiredGuest($w, ['/usr/bin/php', self::ROOT . '/runner.php', $action], $input, $timeout));
    }

    private function upload(array $w, string $path, string $content, string $mode = '0600'): void
    {
        $h = $this->host($w);
        $this->transport->upload($h['remote'], $h['project'], $w['instance'], $path, $content, $mode);
    }

    public function prepare(array $workspace, array $credentials): void
    {
        if ($workspace['handed_over']) { throw new Fault('handed_over', 'Bootstrap is forbidden after customer handover.'); }
        $available = false;
        for ($i = 0; $i < 60; ++$i) {
            if ($this->guest($workspace, ['/usr/bin/true'], '', 5)['exit'] === 0) { $available = true; break; }
            sleep(2);
        }
        if (!$available) { throw new Fault('agent_unavailable', 'Incus guest agent did not become ready.'); }
        $this->requiredGuest($workspace, ['/usr/bin/install', '-d', '-m', '0700', self::ROOT, self::ROOT . '/lib', self::ROOT . '/lib/src']);
        $files = ['guest/runner.php' => self::ROOT . '/runner.php', 'bootstrap.php' => self::ROOT . '/lib/bootstrap.php',
            'guest/probe.php' => self::ROOT . '/probe.php', 'guest/install.sh' => self::ROOT . '/install.sh',
            'guest/jcb-workspace.service' => '/etc/systemd/system/jcb-workspace.service'];
        foreach (['Fault', 'Files', 'Json', 'Validate', 'Process'] as $class) { $files['src/' . $class . '.php'] = self::ROOT . '/lib/src/' . $class . '.php'; }
        foreach ($files as $source => $target) {
            $contents = file_get_contents($this->sourceRoot . '/' . $source);
            if ($contents === false) { throw new Fault('missing_asset', 'A required packaged guest asset is missing.'); }
            $this->upload($workspace, $target, $contents, '0644');
        }
        $h = $this->host($workspace);
        $c = $this->catalog($workspace);
        [$gateway, $prefix] = explode('/', $h['bridge_address']);
        $nic = NetworkPolicy::nic($h, $workspace);
        $this->upload($workspace, self::ROOT . '/workspace.json', Json::encode(['version' => 1, 'id' => $workspace['id'],
            'uid' => $c['uid'], 'gid' => $c['gid'], 'address' => $workspace['address'], 'mac' => $nic['hwaddr'],
            'gateway' => $gateway, 'prefix' => (int) $prefix, 'dns' => $h['dns']]));
        $this->helper($workspace, 'prepare');
        foreach (['admin_username', 'admin_password', 'database_password', 'database_root_password'] as $name) {
            $this->upload($workspace, self::ROOT . '/secrets/' . $name, $credentials[$name]);
        }
        $this->upload($workspace, self::ROOT . '/authorized-keys/developer', implode("\n", $workspace['ssh_keys']) . "\n", '0644');
        $this->upload($workspace, self::ROOT . '/compose.json', Json::encode(Compose::render($workspace, $c, true)));
    }

    public function initialize(array $workspace): void { $this->helper($workspace, 'initialize', '', 3000); }

    public function start(array $workspace): void
    {
        $this->instance($workspace);
        $this->power($workspace, true);
        $this->helper($workspace, 'start');
    }

    public function verify(array $workspace): array
    {
        (new Host($this->config, $this->transport))->verify($workspace['host']);
        $this->instance($workspace);
        $result = $this->helper($workspace, 'verify');
        return ['exposure' => 'private', 'web_url' => 'http://' . $workspace['address'] . '/',
            'ssh_host' => $workspace['address'], 'ssh_port' => 2222, 'ssh_user' => 'developer',
            'ssh_host_fingerprint' => Validate::text($result['ssh_host_fingerprint'], 512),
            'ssh_host_public_key' => Validate::text($result['ssh_host_public_key'], 512),
            'php' => Validate::text($result['php'], 20), 'credential_reference' => $workspace['id']];
    }

    public function handover(array $workspace): void
    {
        $this->upload($workspace, self::ROOT . '/compose.json', Json::encode(Compose::render($workspace, $this->catalog($workspace))));
        $this->helper($workspace, 'handover');
    }

    public function access(array $workspace, bool $enabled): void
    {
        $instance = $this->instance($workspace);
        $expected = $this->definition($workspace, $enabled);
        $this->api($workspace)->mutate('PUT', $this->path($workspace),
            ['architecture' => $instance['architecture'], 'config' => $instance['config'],
                'devices' => $expected['devices'], 'profiles' => [], 'description' => $instance['description'] ?? '']);
        $this->instance($workspace);
    }

    public function suspend(array $workspace): void
    {
        // Stop even when host/network policy has drifted, provided resource ownership still matches.
        $this->power($workspace, false);
    }

    public function delete(array $workspace): void
    {
        $api = $this->api($workspace);
        $existing = $api->find('/1.0/instances', $workspace['instance']);
        if ($existing === null) { return; }
        IncusApi::owned($existing, $this->config->data['installation_id'], $workspace['id']);
        $this->suspend($workspace);
        $api->mutate('DELETE', $this->path($workspace));
        if ($api->find('/1.0/instances', $workspace['instance']) !== null) {
            throw new Fault('deletion_incomplete', 'An owned VM remains after deletion.');
        }
    }

    public function replaceKeys(array $workspace, array $keys): void
    {
        $this->helper($workspace, 'rekey', Json::encode(Validate::keys($keys)));
    }

    private function recipeCommand(array $workspace, array $step, array $argv, string $stdin = ''): array
    {
        if ($workspace['handed_over']) { throw new Fault('handed_over', 'Initialization recipes cannot run after customer handover.'); }
        $c = $this->catalog($workspace);
        if ($step['target'] === 'vm') {
            $digest = $c['vm_executables'][$argv[0]] ?? throw new Fault('forbidden', 'Unapproved VM executable.');
            $actual = $this->requiredGuest($workspace, ['/usr/bin/sha256sum', '--', $argv[0]]);
            if (!hash_equals($digest, substr($actual, 0, 64))) { throw new Fault('helper_changed', 'VM helper digest changed.'); }
            return $this->guest($workspace, $argv, $stdin, $step['timeout']);
        }
        return $this->guest($workspace, ['/usr/bin/docker', 'compose', '--project-name', 'jcb-workspace', '-f', self::ROOT . '/compose.json',
            'exec', '-T', '--user', $c['uid'] . ':' . $c['gid'], 'web', '/usr/local/bin/php', '/var/www/html/cli/joomla.php', ...$argv],
            $stdin, $step['timeout']);
    }

    public function recipeCheck(array $workspace, array $step): bool
    {
        $r = $this->recipeCommand($workspace, $step, $step['check']['argv']);
        return $r['exit'] === 0 && (!isset($step['check']['stdout']) || trim($r['stdout']) === $step['check']['stdout']);
    }

    public function recipeRun(array $workspace, array $step, string $stdin): void
    {
        $r = $this->recipeCommand($workspace, $step, $step['argv'], $stdin);
        if ($r['exit'] !== 0) { throw new Fault('recipe_failed', 'A private recipe step failed; output was suppressed.'); }
    }

    public function backup(array $workspace, string $operation): array
    {
        Validate::uuid($operation);
        $directory = $this->config->data['backup_directory'];
        Files::protectedPath($directory, true);
        if ((fileperms($directory) & 0077) !== 0) { throw new Fault('unsafe_path', 'Backup directory must be owner-only.'); }
        $this->helper($workspace, 'quiesce');
        $this->suspend($workspace);
        $path = $directory . '/' . $workspace['id'] . '-' . $operation . '.tar.gz';
        Files::protectedPath($path);
        $h = $this->host($workspace);
        if (is_file($path)) { throw new Fault('backup_exists', 'Backup path already exists; inspect the previous attempt.'); }
        $r = $this->transport->command($h['project'], ['export', $h['remote'] . ':' . $workspace['instance'], $path, '--instance-only'], '', 3600);
        if ($r['exit'] !== 0) { throw new Fault('backup_failed', 'Workspace export failed; residual archive remains private.'); }
        chmod($path, 0600);
        return ['archive' => basename($path), 'sha256' => hash_file('sha256', $path), 'format' => 'incus-instance-export',
            'encrypted' => false, 'requires_encrypted_storage' => true];
    }
}
