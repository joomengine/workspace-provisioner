<?php

declare(strict_types=1);

namespace JoomEngine\Workspace\Testing;

use JoomEngine\Workspace\{Config, Fault, Validate};

final readonly class LabPlan
{
    public function __construct(public array $data, Config $config)
    {
        Validate::object($data, ['version', 'operator', 'caller', 'tenant', 'catalog', 'profile',
            'confirm_installation', 'disposable', 'instances'], ['application_checks', 'denied_targets']);
        Validate::integer($data['version'], 1, 1);
        Validate::path($data['operator']);
        foreach (['caller', 'tenant', 'catalog', 'profile'] as $key) { Validate::name($data[$key]); }
        if ($data['disposable'] !== true || $data['confirm_installation'] !== $config->data['installation_id']) {
            throw new Fault('lab_not_authorized', 'An explicit disposable-lab installation confirmation is required.');
        }
        Validate::integer($data['instances'], 2, 4);
        if (!isset($config->data['catalog'][$data['catalog']], $config->data['profiles'][$data['profile']])) {
            throw new Fault('invalid_lab', 'Select existing lab catalog and resource profiles.');
        }
        foreach (['create', 'verify', 'reconcile', 'suspend', 'resume', 'replace-keys', 'backup', 'restore', 'delete', 'status'] as $action) {
            $config->authorize($data['caller'], $data['tenant'], $action);
        }
        foreach (Validate::list($data['application_checks'] ?? [], 0, 32) as $check) {
            Validate::object($check, ['argv', 'verify_argv'], ['stdout', 'timeout', 'frontend_path', 'frontend_contains']);
            Validate::integer($check['timeout'] ?? 600, 1, 1800);
            if (isset($check['frontend_path']) || isset($check['frontend_contains'])) {
                if (!str_starts_with(Validate::text($check['frontend_path'] ?? '', 2048), '/')) {
                    throw new Fault('invalid_lab', 'Frontend checks require a target-local URL path.');
                }
                Validate::text($check['frontend_contains'] ?? '', 8192);
            }
            foreach ([$check['argv'], $check['verify_argv']] as $command) {
                Validate::argv($command);
                if (!preg_match('/^[a-zA-Z][a-zA-Z0-9:_-]*$/D', $command[0])) {
                    throw new Fault('invalid_lab', 'Application checks must name Joomla CLI commands.');
                }
            }
            if (isset($check['stdout'])) { Validate::text($check['stdout'], 8192); }
        }
        foreach (Validate::list($data['denied_targets'] ?? [], 0, 32) as $target) {
            Validate::object($target, ['address', 'port']);
            if (!is_string($target['address']) || filter_var($target['address'], FILTER_VALIDATE_IP) === false) {
                throw new Fault('invalid_lab', 'Probe targets must be literal approved lab IP addresses.');
            }
            Validate::integer($target['port'], 1, 65535);
        }
    }
}
