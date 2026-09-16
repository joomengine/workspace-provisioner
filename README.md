# JoomEngine Workspace Provisioner

A reusable, PHP-first provisioner for Joomla Component Builder development workspaces: one Incus virtual machine per workspace, with customer SSH/SFTP confined to a restricted development container inside that VM.

## Objective

Provision a standard workspace from the public JCB Docker images, share its actual Joomla files with the development container, and expose a machine-readable lifecycle interface for a trusted calling service. The default installation must work without private adaptations.

The first release covers non-destructive host preflight/preparation, repeatable VM images, pinned Docker workloads, persistent storage, external network/resource enforcement, key-only SSH, versioned initialization recipes, durable operations, verification, suspend/resume, key replacement, deletion, and automated tests.

## Security boundaries

- No customer SSH into the VM or physical host; no Docker/Incus socket, privileged development container, or host-system mounts.
- The VM is the primary isolation boundary. Controls inside the guest do not replace host-side enforcement.
- Operator recipes are external, trusted configuration with separate VM/container targets. Customer input cannot select arbitrary commands, devices, images, or networks.
- Infrastructure credentials remain on the management machine. Published code, images, examples, logs, and CI artifacts must not contain private recipes or credentials.
- Existing resources are never adopted, initialized, reformatted, or deleted without ownership checks and explicit administrator intent.

## Implementation and qualification

Phase-one implementation is being assembled in reviewable increments. A passing unit test or a running container is not evidence of VM isolation. Actual qualification requires a dedicated, disposable Incus/KVM environment and the release's integration tests; never attach public pull-request jobs to production infrastructure.

The upstream image source is [joomengine/docker](https://github.com/joomengine/docker). This project consumes approved image digests rather than duplicating the upstream build system.

## Scope exclusions

No customer portal, billing, public DNS automation, or production firewall publication is included. Those systems can consume the lifecycle interface without owning or reimplementing it.

## License

Apache-2.0; see [LICENSE](LICENSE). Upstream software and container images retain their respective licenses.
