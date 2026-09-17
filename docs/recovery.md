# Encrypted backups and retained-workspace recovery

`backup` and `restore` are authorized lifecycle operations submitted through the same PHP/CLI interface as create, suspend and resume. They are not shell shortcuts, and they never require a customer to receive VM or Incus access.

## Backup

Submit a request with `action: backup`, a new `request_id`, the existing `workspace_id`, and its `tenant`. The workspace must have completed handover and be verified ready. The worker closes customer ingress, starts/verifies the guest management path, stops its Compose workloads cleanly, stops the VM, and exports the owned instance. It immediately seals the export with authenticated streaming encryption and records its authenticated manifest. Both the instance root disk and all VM-local application/database directories are in that export. Custom Incus storage volumes are not included; this version does not attach them to workspaces.

The archive and manifest are bound to the installation, workspace, image digests, recipe and inventory identity. Retrying the same operation returns the existing authenticated backup instead of exporting a different point in time under the same backup ID. Ordinary status returns `last_backup`, including the backup ID and encryption format. It never returns the plaintext archive or encryption key. After a successful backup, the worker verifies and restores access to the running workspace.

Copy the encrypted backup directory and its manifest to separate protected/off-host storage under your retention policy. Back up the operation store and master encryption key separately. Deleting a workspace intentionally does not silently purge retained backup archives; operators must apply their approved retention policy. Losing the key makes encrypted archives unrecoverable.

Export/import needs private temporary plaintext staging on the management machine. Staging is owner-only and removed on normal success/failure; it is never unpacked or executed on that machine. A power loss or SIGKILL can leave owner-only `.staging-*` directories. Keep the backing storage encrypted and inspect/clean stale staging only after confirming no worker/import/export is active. This is not a claim that overwriting/unlinking files securely erases SSD media.

## Restore

Restore is deliberately conservative: it recovers an **absent VM** into the retained workspace's existing host/project/address reservation. It does not overwrite an existing VM, allocate a new customer identity, reactivate a deleted workspace, or silently migrate between hosts. To intentionally restore an existing retained VM, add `replace_existing: true` to the restore request. The controller authenticates the selected backup first, creates and records an encrypted safety backup of the current VM, and only then retires that owned VM and imports the requested backup. The safety backup uses the restore operation ID and is returned as `last_backup`. Its checkpoints prevent repeated retirement after a lost response. Without this explicit boolean, an existing VM is a conflict. Never submit a normal provisioner `delete` as preparation for restore: that retires the workspace identity.

First suspend the retained workspace, or establish a failed state after the VM was lost. Submit the normal version-1 request with `action: restore` and `backup_id` identifying one of that workspace's recorded successful backups. Unknown or cross-workspace backup IDs fail. The worker authenticates the manifest/archive before importing. Corruption, wrong policy/recipe/images or unowned target resources fail closed.

Incus imports the stopped VM with bootstrap-only ingress and an operation identity applied at creation. A lost API response is safe to retry: the same operation recognizes the imported identity and does not import twice. A same-name resource without that identity is a conflict, not permission to replace it. Current authorized SSH keys are reapplied, so an old backup does not resurrect revoked keys. Application recipes, Composer bootstrap and Joomla installation are never rerun as part of recovery.

After verification, the restored VM remains **suspended**. Submit a separate `resume` request when access should be restored. Existing SSH host keys from the same retained workspace are preserved and connection information is checked again. Database/file contents return to the chosen backup point; administrator passwords may therefore reflect that point in time. Do not represent an older backup as retaining later application changes.

## Verification

`php tests/run.php` exercises authenticated encryption, corruption/truncation/context rejection, export/import adapter ownership, interrupted import retry, current-key application, and restore-to-suspended lifecycle semantics using synthetic archives and an Incus API simulator. These tests are not a real filesystem/database restore. A real Incus/KVM export/import and application-level data comparison must pass in the protected lab before recovery is qualified. See [qualification](qualification.md).

Incus reference: https://linuxcontainers.org/incus/docs/main/howto/instances_backup/ and https://linuxcontainers.org/incus/docs/main/reference/manpages/incus/import/.
