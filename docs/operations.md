# Operator installation and lifecycle

## Management and compute prerequisites

Use a dedicated Linux management account with PHP 8.3+, JSON/PDO/POSIX/Sodium, an official Incus client and an authenticated client configuration. PostgreSQL additionally needs `pdo_pgsql`; floating container selectors need Skopeo. Package building requires Git, Composer 2, tar/gzip/zip and checksum tools. The controller does not require a local Docker daemon when consuming existing image digests.

The compute hosts need functioning Incus/KVM with the API capabilities tested by `host:preflight` and existing ZFS, LVM or Btrfs storage pools. This implementation targets independent Incus remotes with distinct non-overlapping workspace subnets; it rejects clustered endpoints instead of pretending to provide cluster placement/HA. Routing from the approved gateway/test client to each private guest subnet is an operator network requirement. The management machine's Incus path is separate from customer SSH.

Do not initialize an existing Incus installation, format a disk, expose an Incus API publicly, disable certificate validation, or add the customer to host management groups. Establish authenticated Incus remotes using the official client and verify server certificates out-of-band. The provisioner uses the configured client directory; no token is sent to a guest. API credentials have significant infrastructure authority even though the worker OS account is unprivileged.

## Build images once, instantiate many times

1. On a trusted image-building host with Incus, import/select an official **Ubuntu 24.04 VM image** and record its fingerprint. Provide an existing storage pool and an existing isolated build network with package-download access. `bash tools/build-vm.sh SOURCE_FINGERPRINT STORAGE_POOL BUILD_NETWORK OUTPUT_JSON` creates its own short-lived project/VM, builds and sanitizes the template, publishes its fingerprint and writes provenance to a new private JSON file. It never initializes the host. The builder currently intentionally supports Ubuntu 24.04, not an untested arbitrary distribution.
2. Copy that published VM image to the default image store on each approved compute remote using the Incus image-copy command. Preserve the exact fingerprint and `wp.template.version` metadata. Workspace projects consume that existing image rather than build an OS on every request.
3. On an isolated Docker build machine with push access to the chosen development-image registry, run `bash tools/build-images.sh JCB_IMAGE MARIADB_IMAGE COMPOSER_IMAGE DEVELOPMENT_TAG VM_PROVENANCE_JSON OUTPUT_CATALOG_JSON`. Supply the operator-selected image references; this resolves/pins bases, builds and publishes a matching development image and writes the catalog. The development builder targets the Debian-based Apache JCB variant and UID/GID 33. The images must be guest-readable. Actual operator references, private branches and custom manifests remain outside the public tree.

Image provenance is not proof of successful infrastructure qualification. Maintain approved image/recipe versions and test upgrades in a separate empty workspace. Existing customer workspaces retain their saved digests.

## Configure and run

Install an extracted release into a version-specific, administrator-owned path. It includes Composer autoload files and can execute without installing Composer on the runtime machine. Keep private state/configuration outside that release path. From a source checkout, run `composer install --no-dev --no-plugins --no-scripts` or use the bundled bootstrap for core commands.

Prepare the external inventory, catalog and Incus client directory according to [configuration](configuration.md). Run `php bin/workspace configure` with the paths listed by `help`; it refuses an existing output directory and generates unique encryption material. Run `validate`, then `state:migrate`. For PostgreSQL, configure a dedicated database/role before migration. Do not put passwords in command arguments.

Run `host:preflight` for each host, inspect its result, then explicitly run `host:prepare --confirm INSTALLATION_UUID` using the UUID from configuration. This creates only owned project/network/ACL resources on the selected remote. Storage remains untouched. Repeating preparation must preserve those definitions; foreign or changed resources produce conflicts, not silent adoption.

Submit a versioned JSON request on stdin using `submit --config FILE --caller NAME`. Record the returned operation ID. Run `worker --once` to execute one operation or run `worker` under a supervisor. Query `status` for progress. The CLI and `Application` PHP facade share the same lifecycle code; a future authenticated service calls the facade, not a second set of deployment scripts. The caller label is an authorization scope, not proof of caller identity.

`credentials --workspace UUID --output NEW_FILE` retrieves the initial administrator credential once into a mode-0600 file. Connection status supplies the private web endpoint and container SSH endpoint/host key. Customer SSH never targets the guest OS. Supply the customer public key in the create request; do not upload their private key.

## Worker supervision

`php tools/worker-service.php PRIVATE_CONFIG SYSTEM_USER NEW_UNIT_FILE` generates a hardened systemd unit for the current installed release. Use a dedicated non-root account that owns the private state and can read the Incus credentials. The generator does not install or start anything. Inspect the new unit, install it as `joomengine-workspace.service` under `/etc/systemd/system`, then explicitly reload systemd and enable/start it. `journalctl -u joomengine-workspace` shows sanitized operation results.

The generated unit grants write access only to state/credential/backup directories. It does not grant a Docker socket, root privileges, or write access to the installed code. Use canonical service paths without whitespace or expansion characters. The worker reloads its external config between operations. Process termination may interrupt a remote operation; the persisted operation/checkpoints are designed to inspect and resume it, not assume a timed-out action never happened.

## Suspend, recover, delete and update

Use the same request interface for verify/reconcile, suspend/resume, SSH key replacement, encrypted backup, restore and deletion. Reconciliation of a retained ready workspace restarts and verifies it without rerunning installation. Guests deliberately have `boot.autostart=false`: following a compute-host restart, run reconciliation for retained ready workspaces rather than automatically starting suspended guests. Store operation IDs in the calling service and submit explicit lifecycle requests; no hidden billing scheduler exists in this package.

Suspension stops the actual VM, terminating existing sessions. Replacement keys restart the development service. A restore finishes suspended and needs a separate resume. [Recovery](recovery.md) describes absent-VM and explicit replacement paths. Deletion removes only the recorded owned VM and credentials; retained encrypted backups remain under the operator's separate retention policy, and retired names/addresses are not silently reused.

For provisioner updates, resolve `latest` once and download the versioned package/checksums as described in [releases](releases.md). Stop the worker, retain the old installed release and database/key backups, install the new release, run validation/migration and regenerate the service for that version-specific directory before starting it. Never overwrite the external operator configuration. Automated package publication does not authorize replacing running customer application images or resetting their databases.
