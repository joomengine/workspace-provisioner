<?php

declare(strict_types=1);

namespace JoomEngine\Workspace;

final readonly class Request
{
    public const ACTIONS = ['create', 'verify', 'suspend', 'resume', 'replace-keys', 'delete', 'reconcile', 'backup', 'restore'];
    public array $data;
    public string $hash;

    public function __construct(array $data)
    {
        $required = ['version', 'request_id', 'workspace_id', 'tenant', 'action'];
        $action = $data['action'] ?? null;
        if (!in_array($action, self::ACTIONS, true)) {
            throw new Fault('invalid_action', 'Unsupported workspace action.');
        }
        if ($action === 'create') {
            $required = [...$required, 'name', 'catalog', 'profile', 'admin_email', 'ssh_keys'];
        } elseif ($action === 'restore') {
            $required[] = 'backup_id';
        } elseif ($action === 'replace-keys') {
            $required[] = 'ssh_keys';
        }
        Validate::object($data, $required);
        Validate::integer($data['version'], 1, 1);
        Validate::uuid($data['request_id']);
        Validate::uuid($data['workspace_id']);
        Validate::name($data['tenant']);
        if ($action === 'restore') { Validate::uuid($data['backup_id']); }
        if ($action === 'create') {
            foreach (['name', 'catalog', 'profile'] as $key) {
                Validate::name($data[$key]);
            }
            if (filter_var(Validate::text($data['admin_email']), FILTER_VALIDATE_EMAIL) === false) {
                throw new Fault('invalid_email', 'Invalid administrator email address.');
            }
        }
        if (isset($data['ssh_keys'])) {
            $data['ssh_keys'] = Validate::keys($data['ssh_keys']);
        }
        $this->data = $data;
        $this->hash = Json::hash($data);
    }
}
