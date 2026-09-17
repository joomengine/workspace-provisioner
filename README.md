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

The Phase 1 runtime, configuration contracts, image builders, lifecycle tooling and release packaging are implemented. Review the current PR checks and the acceptance guide before deployment. A passing unit test or a running container is not evidence of VM isolation. Actual qualification requires a dedicated, disposable Incus/KVM environment and the release's integration tests; never attach public pull-request jobs to production infrastructure.

The upstream image source is [joomengine/docker](https://github.com/joomengine/docker). This project consumes approved image digests rather than duplicating the upstream build system.

## Scope exclusions

No customer portal, billing, public DNS automation, or production firewall publication is included. Those systems can consume the lifecycle interface without owning or reimplementing it.

## License

Apache-2.0; see [LICENSE](LICENSE). Upstream software and container images retain their respective licenses.

## Operator and developer entry points

Run `php bin/workspace help` for the service/CLI interface. Start with [operator installation](docs/operations.md), [configuration schemas](docs/schemas.md), [external configuration](docs/configuration.md), [development setup](docs/development.md), [security boundaries](docs/security.md), and [qualification evidence](docs/qualification.md). [Release automation](docs/releases.md) documents semantic versions, installable archives, Composer metadata and the stable `latest` channel.

Production image selectors, private initialization recipes and workspace Composer files belong in external operator configuration. A configured container tag such as `latest` is resolved once per new workspace and stored as an exact digest. Restart/retry does not silently upgrade existing workspaces. Generated Compose definitions preserve the platform's security policy; arbitrary Compose overrides are not accepted.

## Installable releases

After a reviewed change is merged to `main`, automated checks build versioned tar/zip packages containing the PHP runtime, Composer manifest/lock/autoload, guest and image tooling, schemas, examples and tests. Publication advances the stable latest release only after checks and asset verification succeed. `bash tools/download-release.sh latest NEW_DIRECTORY` resolves that release once and downloads matching checksummed assets without modifying your installation or private configuration. [Implementation coverage](docs/implementation.md) maps the runtime objectives to code and tests.
