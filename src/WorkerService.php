<?php

declare(strict_types=1);

namespace JoomEngine\Workspace;

/** Generates a service definition only; installation/start remain explicit administrator actions. */
final class WorkerService
{
    public static function render(Config $config, string $configuration, string $root, string $php, string $user): string
    {
        if ($user === 'root' || !preg_match('/^[a-z_][a-z0-9_-]{0,31}$/D', $user)) {
            throw new Fault('invalid_service_user', 'Use a dedicated non-root system account.');
        }
        $path = static function (string $value): string {
            Validate::path($value);
            if (!preg_match('~^/[a-zA-Z0-9_./-]+$~D', $value)) {
                throw new Fault('invalid_service_path', 'Service paths must not contain whitespace or systemd expansion syntax.');
            }
            return $value;
        };
        foreach ([$configuration, $root, $php] as $value) { $path($value); }
        $writes = [$config->data['vault']['directory'], $config->data['backup_directory']];
        if ($config->data['state']['driver'] === 'file') { $writes[] = dirname($config->data['state']['path']); }
        $writes = array_unique(array_map($path, $writes));
        return "[Unit]\nDescription=JoomEngine workspace operation worker\nAfter=network-online.target\nWants=network-online.target\n\n"
            . "[Service]\nType=simple\nUser={$user}\nWorkingDirectory={$root}\n"
            . "ExecStart={$php} {$root}/bin/workspace worker --config {$configuration}\n"
            . "Restart=on-failure\nRestartSec=5\nTimeoutStopSec=30\nKillMode=control-group\nUMask=0077\n"
            . "NoNewPrivileges=true\nPrivateTmp=true\nPrivateDevices=true\nProtectSystem=strict\nProtectHome=read-only\n"
            . "ProtectKernelTunables=true\nProtectKernelModules=true\nProtectControlGroups=true\nRestrictSUIDSGID=true\n"
            . "LockPersonality=true\nRestrictAddressFamilies=AF_UNIX AF_INET AF_INET6\nCapabilityBoundingSet=\n"
            . 'ReadWritePaths=' . implode(' ', $writes) . "\n\n[Install]\nWantedBy=multi-user.target\n";
    }
}
