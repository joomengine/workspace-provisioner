<?php

declare(strict_types=1);

namespace JoomEngine\Workspace;

final readonly class Engine
{
    public function __construct(private Config $config, private Journal $journal, private Runtime $runtime, private Vault $vault, private ?ImageResolver $imageResolver = null)
    {
    }

    public function tick(): ?array
    {
        return $this->journal->store->exclusive(function (): ?array {
            $pending = $this->journal->pending();
            if ($pending === []) { return null; }
            $op = $pending[0];
            $id = $op['id'];
            $workspace = $this->journal->workspace($op['workspace_id']);
            try {
                $this->config->authorize($op['caller'], $workspace['tenant'], $op['action']);
                if ($workspace['generation'] !== $op['generation']) { throw new Fault('stale_operation', 'Operation was superseded.'); }
                $host = $this->config->data['hosts'][$workspace['host']] ?? null;
                if ($host === null || Json::hash($host) !== $workspace['host_hash']) {
                    throw new Fault('inventory_changed', 'Host inventory changed; reconcile it explicitly before execution.');
                }
                if (!in_array($op['action'], ['suspend', 'delete'], true)) {
                    $catalog = $this->config->data['catalog'][$workspace['catalog']] ?? null;
                    if ($catalog === null || Json::hash($catalog) !== $workspace['catalog_hash']
                        || $this->config->recipe($workspace['catalog'])->hash !== $workspace['recipe_hash']) {
                        throw new Fault('recipe_changed', 'Pinned catalog or recipe changed; refusing an implicit upgrade.');
                    }
                }
                if (in_array($op['action'], ['suspend', 'delete', 'resume'], true)) {
                    $desired = match ($op['action']) { 'suspend' => 'suspended', 'delete' => 'deleted', default => 'ready' };
                    $this->journal->change($id, static function (array &$o, array &$w) use ($desired): void { $w['desired'] = $desired; });
                }
                $this->stage($id, 'running');
                switch ($op['action']) {
                    case 'create':
                        $this->create($id, $workspace);
                        break;
                    case 'suspend':
                        $this->stage($id, 'suspending');
                        $this->runtime->suspend($workspace);
                        $this->runtime->access($workspace, false);
                        $this->state($id, 'suspended', 'suspended');
                        break;
                    case 'resume':
                        if (!$workspace['handed_over']) { throw new Fault('not_initialized', 'Use the original create operation to finish initialization.'); }
                        $this->stage($id, 'resuming');
                        $this->runtime->access($workspace, false);
                        $this->runtime->start($workspace);
                        $this->runtime->handover($workspace);
                        $this->ready($id, $workspace);
                        break;
                    case 'verify':
                    case 'reconcile':
                        if ($workspace['desired'] === 'deleted') {
                            $this->runtime->delete($workspace);
                            $this->vault->forget($workspace['id']);
                            $this->state($id, 'deleted', 'deleted');
                        } elseif ($workspace['desired'] === 'suspended') {
                            $this->runtime->suspend($workspace);
                            $this->state($id, 'suspended', 'suspended');
                        } elseif ($workspace['handed_over']) {
                            $this->runtime->access($workspace, false);
                            $this->runtime->start($workspace);
                            $this->ready($id, $workspace);
                        } else {
                            throw new Fault('not_initialized', 'Workspace initialization has not completed.');
                        }
                        break;
                    case 'replace-keys':
                        if (!$workspace['handed_over']) { throw new Fault('not_initialized', 'Workspace is not initialized.'); }
                        $this->runtime->access($workspace, false);
                        $this->runtime->start($workspace);
                        $this->runtime->replaceKeys($workspace, $op['request']['ssh_keys']);
                        $this->journal->change($id, static function (array &$o, array &$w) use ($op): void { $w['ssh_keys'] = $op['request']['ssh_keys']; });
                        if ($workspace['desired'] === 'deleted') {
                            $this->runtime->delete($workspace);
                            $this->vault->forget($workspace['id']);
                            $this->state($id, 'deleted', 'deleted');
                        } elseif ($workspace['desired'] === 'suspended') {
                            $this->runtime->suspend($workspace);
                            $this->state($id, 'suspended', 'suspended');
                        } else { $this->ready($id, $workspace); }
                        break;
                    case 'backup':
                        if (!$workspace['handed_over'] || $workspace['desired'] !== 'ready') {
                            throw new Fault('invalid_state', 'A verified running workspace is required for backup.');
                        }
                        $this->runtime->access($workspace, false);
                        $backup = $this->runtime->backup($workspace, $id);
                        $this->journal->change($id, static function (array &$o, array &$w) use ($backup, $id): void {
                            $w['last_backup'] = $backup;
                            $w['backups'][$id] = $backup;
                        });
                        $this->runtime->start($workspace);
                        $this->ready($id, $workspace);
                        break;
                    case 'restore':
                        if (!$workspace['handed_over'] || !isset($workspace['backups'][$op['request']['backup_id']])) {
                            throw new Fault('invalid_restore', 'The requested backup is not owned by this workspace.');
                        }
                        $this->stage($id, 'restoring');
                        $this->journal->change($id, static function (array &$o, array &$w): void { $w['desired'] = 'suspended'; });
                        $this->runtime->verifyBackup($workspace, $op['request']['backup_id']);
                        if ($op['request']['replace_existing'] ?? false) {
                            // Explicit replacement saves a rollback point before retiring the owned VM.
                            // Checkpoints make a lost delete/import response safe to retry.
                            $this->checkpoint($id, 'restore-safety-backup', function () use ($workspace, $id): void {
                                $backup = $this->runtime->backup($workspace, $id);
                                $this->journal->change($id, static function (array &$o, array &$w) use ($backup, $id): void {
                                    $w['backups'][$id] = $backup;
                                    $w['last_backup'] = $backup;
                                    $o['safety_backup_id'] = $id;
                                });
                            });
                            $this->checkpoint($id, 'restore-retire', fn () => $this->runtime->delete($workspace));
                        }
                        $this->runtime->restore($workspace, $op['request']['backup_id'], $id);
                        $this->journal->change($id, static function (array &$o, array &$w) use ($id): void { $w['restore_operation'] = $id; });
                        $workspace['restore_operation'] = $id;
                        // Imported images remain private until current keys and policy are reapplied.
                        $this->runtime->access($workspace, false);
                        $this->runtime->start($workspace);
                        $this->runtime->handover($workspace);
                        $this->runtime->replaceKeys($workspace, $workspace['ssh_keys']);
                        $this->runtime->verify($workspace);
                        $this->runtime->suspend($workspace);
                        $this->state($id, 'suspended', 'suspended');
                        break;
                    case 'delete':
                        $this->stage($id, 'deleting');
                        $this->runtime->delete($workspace);
                        $this->vault->forget($workspace['id']);
                        $this->state($id, 'deleted', 'deleted');
                        break;
                }
                $this->journal->change($id, static function (array &$o, array &$w): void {
                    $o['status'] = 'succeeded'; $o['stage'] = 'complete'; $o['error'] = null;
                });
            } catch (\Throwable $error) {
                $stopped = false;
                try { $this->runtime->suspend($workspace); $stopped = true; } catch (\Throwable) { }
                $reason = $error instanceof Fault ? $error->reason : 'internal_error';
                $this->journal->change($id, static function (array &$o, array &$w) use ($reason, $stopped): void {
                    $o['status'] = 'failed'; $o['error'] = $reason;
                    $w['status'] = $w['status'] === 'deleted' ? 'deleted' : 'failed';
                    $w['access_stop_confirmed'] = $stopped;
                });
            }
            return array_intersect_key($this->journal->operation($id),
                array_flip(['id', 'workspace_id', 'action', 'status', 'stage', 'error']));
        });
    }

    private function create(string $id, array $workspace): void
    {
        if (!isset($workspace['resolved_images'])) {
            $this->stage($id, 'resolving-images');
            $images = ($this->imageResolver ?? new ImageResolver($this->config->data['image_resolver'] ?? []))
                ->resolve($this->config->data['catalog'][$workspace['catalog']]);
            $this->journal->change($id, static function (array &$o, array &$w) use ($images): void { $w['resolved_images'] = $images; });
            $workspace['resolved_images'] = $images;
        }
        $this->stage($id, 'provisioning');
        $this->runtime->ensure($workspace, function (string $remoteOperation) use ($id): void {
            $this->journal->change($id, static function (array &$o, array &$w) use ($remoteOperation): void { $o['remote_operation'] = $remoteOperation; });
        });
        if (!$workspace['handed_over']) {
            $this->stage($id, 'configuring');
            $this->checkpoint($id, 'bootstrap', fn () => $this->runtime->prepare($workspace, $this->vault->credentials($workspace['id'])));
            $recipe = $this->config->recipe($workspace['catalog']);
            foreach ($recipe->steps('vm') as $step) { $this->recipe($id, $workspace, $step); }
            $this->checkpoint($id, 'installation', fn () => $this->runtime->initialize($workspace));
            foreach ($recipe->steps('joomla') as $step) { $this->recipe($id, $workspace, $step); }
            $this->runtime->start($workspace);
            $this->runtime->verify($workspace);
            // Persist the boundary first: retry never bootstraps a handed-over workspace.
            $this->journal->change($id, static function (array &$o, array &$w): void { $w['handed_over'] = true; });
            $workspace['handed_over'] = true;
        } else {
            $this->runtime->start($workspace);
        }
        $this->runtime->handover($workspace);
        $this->ready($id, $workspace);
    }

    private function checkpoint(string $id, string $step, callable $body): void
    {
        $op = $this->journal->operation($id);
        if (($op['checkpoints'][$step] ?? '') === 'complete') { return; }
        $this->journal->change($id, static function (array &$o, array &$w) use ($step): void { $o['checkpoints'][$step] = 'running'; });
        $body();
        $this->journal->change($id, static function (array &$o, array &$w) use ($step): void { $o['checkpoints'][$step] = 'complete'; });
    }

    private function recipe(string $operation, array $workspace, array $step): void
    {
        $current = $this->journal->workspace($workspace['id']);
        $previous = $current['steps'][$step['id']] ?? null;
        if ($previous === 'complete') { return; }
        $this->stage($operation, 'recipe:' . $step['id']);
        if (!$this->runtime->recipeCheck($workspace, $step)) {
            if ($previous === 'running' && $step['repeat'] === 'once') {
                throw new Fault('ambiguous_recipe', 'A non-repeatable step has an unresolved outcome; inspect it before continuing.');
            }
            $this->journal->change($operation, static function (array &$o, array &$w) use ($step): void { $w['steps'][$step['id']] = 'running'; });
            $stdin = isset($step['stdin_secret'])
                ? Files::readPrivate($this->config->data['recipe_secrets'] . '/' . Validate::name($step['stdin_secret']), 65536) : '';
            $this->runtime->recipeRun($workspace, $step, $stdin);
            if (!$this->runtime->recipeCheck($workspace, $step)) { throw new Fault('postcondition_failed', 'Recipe postcondition did not hold.'); }
        }
        $this->journal->change($operation, static function (array &$o, array &$w) use ($step): void { $w['steps'][$step['id']] = 'complete'; });
    }

    private function ready(string $id, array $workspace): void
    {
        $this->stage(id: $id, stage: 'verifying');
        $result = $this->runtime->verify($workspace);
        $this->runtime->access($workspace, true);
        $this->journal->change($id, static function (array &$o, array &$w) use ($result): void {
            $w['result'] = $result; $w['status'] = 'ready'; $w['desired'] = 'ready'; $w['access_stop_confirmed'] = false;
        });
    }

    private function stage(string $id, string $stage): void
    {
        $this->journal->change($id, static function (array &$o, array &$w) use ($stage): void {
            $o['status'] = 'running'; $o['stage'] = $stage;
        });
    }

    private function state(string $id, string $status, string $desired): void
    {
        $this->journal->change($id, static function (array &$o, array &$w) use ($status, $desired): void {
            $w['status'] = $status; $w['desired'] = $desired;
            $w['access_stop_confirmed'] = in_array($status, ['suspended', 'deleted'], true);
        });
    }
}
