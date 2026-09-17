<?php

declare(strict_types=1);

namespace JoomEngine\Workspace;

/** Compose JSON is generated, not merged with arbitrary customer YAML. */
final class Compose
{
    public static function render(array $workspace, array $catalog, bool $installer = false): array
    {
        $base = '/opt/jcb-workspace';
        $site = self::mount('/srv/jcb/site', '/var/www/html');
        $probe = self::mount($base . '/probe.php', '/opt/wp/probe.php', true);
        $limit = $workspace['resources']['memory_mib'];
        $common = ['restart' => 'unless-stopped', 'init' => true, 'security_opt' => ['no-new-privileges:true'],
            'pids_limit' => 256, 'logging' => ['driver' => 'local', 'options' => ['max-size' => '10m', 'max-file' => '3']]];
        $web = $common + ['image' => $catalog['jcb_image'], 'read_only' => true,
            'entrypoint' => ['apache2-foreground'], 'command' => [],
            'environment' => ['APACHE_RUN_USER' => '#' . $catalog['uid'], 'APACHE_RUN_GROUP' => '#' . $catalog['gid']],
            'volumes' => [$site, $probe], 'ports' => [$workspace['address'] . ':80:80'],
            'networks' => ['application', 'database'], 'depends_on' => ['database' => ['condition' => 'service_healthy']],
            'tmpfs' => ['/tmp:rw,nosuid,nodev,size=256m,mode=1777', '/var/run/apache2:rw,nosuid,nodev,size=8m', '/var/lock/apache2:rw,nosuid,nodev,size=8m'],
            'cap_drop' => ['ALL'], 'cap_add' => ['SETUID', 'SETGID', 'NET_BIND_SERVICE', 'KILL', 'CHOWN'],
            'mem_limit' => (int) floor($limit * 0.45) . 'm'];
        $database = $common + ['image' => $catalog['database_image'],
            'environment' => ['MARIADB_DATABASE' => 'joomla', 'MARIADB_USER' => 'joomla',
                'MARIADB_PASSWORD_FILE' => '/run/secrets/database-password',
                'MARIADB_ROOT_PASSWORD_FILE' => '/run/secrets/database-root-password'],
            'secrets' => ['database-password', 'database-root-password'],
            'volumes' => [self::mount('/srv/jcb/database', '/var/lib/mysql')],
            'networks' => ['database'], 'mem_limit' => max(256, (int) floor($limit * 0.2)) . 'm',
            'healthcheck' => ['test' => ['CMD', 'healthcheck.sh', '--connect', '--innodb_initialized'],
                'interval' => '5s', 'timeout' => '5s', 'retries' => 60, 'start_period' => '30s']];
        $development = $common + ['image' => $catalog['development_image'], 'read_only' => true,
            'environment' => ['WP_EXPECT_UID' => (string) $catalog['uid'], 'WP_EXPECT_GID' => (string) $catalog['gid']],
            'volumes' => [$site, $probe, self::mount('/srv/jcb/home', '/home/developer'),
                self::mount('/srv/jcb/build', '/workspace/build'),
                self::mount($base . '/authorized-keys', '/etc/ssh/authorized-keys', true),
                self::mount($base . '/host-keys', '/etc/ssh/host-keys', true)],
            'working_dir' => '/var/www/html', 'ports' => [$workspace['address'] . ':2222:2222'],
            'networks' => ['application', 'database'], 'mem_limit' => max(256, (int) floor($limit * 0.2)) . 'm',
            'tmpfs' => ['/run:rw,nosuid,nodev,size=16m', '/tmp:rw,nosuid,nodev,size=128m,mode=1777'],
            'cap_drop' => ['ALL'], 'cap_add' => ['SETUID', 'SETGID', 'SYS_CHROOT', 'CHOWN', 'DAC_OVERRIDE', 'AUDIT_WRITE'],
            'healthcheck' => ['test' => ['CMD', '/usr/sbin/sshd', '-t'], 'interval' => '10s', 'timeout' => '5s', 'retries' => 6]];
        $services = ['database' => $database, 'web' => $web, 'development' => $development];
        $secrets = ['database-password' => ['file' => $base . '/secrets/database_password'],
            'database-root-password' => ['file' => $base . '/secrets/database_root_password']];
        if ($installer) {
            $services['installer'] = $common + ['image' => $catalog['jcb_image'],
                'entrypoint' => ['/bin/bash', '/opt/wp/install.sh'], 'command' => [],
                'restart' => 'no', 'volumes' => [$site, $probe, self::mount($base . '/install.sh', '/opt/wp/install.sh', true)],
                'networks' => ['application', 'database'], 'mem_limit' => (int) floor($limit * 0.45) . 'm',
                'environment' => ['JOOMLA_DB_HOST' => 'database', 'JOOMLA_DB_USER' => 'joomla', 'JOOMLA_DB_NAME' => 'joomla',
                    'JOOMLA_DB_PASSWORD_FILE' => '/run/secrets/database-password',
                    'JOOMLA_ADMIN_EMAIL' => str_replace('$', '$$', $workspace['admin_email']),
                    'JOOMLA_SITE_NAME' => 'JCB Development Workspace', 'JOOMLA_ADMIN_USER' => 'Workspace Developer',
                    'APACHE_RUN_USER' => '#' . $catalog['uid'], 'APACHE_RUN_GROUP' => '#' . $catalog['gid']],
                'secrets' => ['database-password', 'admin-password', 'admin-username']];
            $services['installer']['restart'] = 'no';
            if (isset($catalog['composer'])) {
                $services['composer'] = $common + ['image' => $catalog['development_image'],
                    'profiles' => ['bootstrap'], 'user' => $catalog['uid'] . ':' . $catalog['gid'],
                    'read_only' => true, 'entrypoint' => ['/usr/local/bin/php', '/opt/wp/composer-install.php'],
                    'command' => [], 'working_dir' => '/var/www/html', 'cap_drop' => ['ALL'],
                    'networks' => ['application'], 'mem_limit' => (int) floor($limit * 0.4) . 'm',
                    'tmpfs' => ['/tmp:rw,nosuid,nodev,size=512m,mode=1777'],
                    'volumes' => [$site, self::mount($base . '/composer-install.php', '/opt/wp/composer-install.php', true),
                        self::mount($base . '/lib', '/opt/wp/lib', true)]];
                $services['composer']['restart'] = 'no';
            }
            $secrets['admin-password'] = ['file' => $base . '/secrets/admin_password'];
            $secrets['admin-username'] = ['file' => $base . '/secrets/admin_username'];
        }
        return ['name' => 'jcb-workspace', 'services' => $services,
            'networks' => ['application' => ['driver' => 'bridge'], 'database' => ['driver' => 'bridge', 'internal' => true]],
            'secrets' => $secrets];
    }

    private static function mount(string $source, string $target, bool $readOnly = false): array
    {
        return ['type' => 'bind', 'source' => $source, 'target' => $target, 'read_only' => $readOnly,
            'bind' => ['create_host_path' => false]];
    }
}
