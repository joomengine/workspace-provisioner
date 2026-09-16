# Contributor and agent instructions

Read README.md, docs/phase-one.md, docs/configuration.md, and docs/security.md before changing runtime behaviour. Preserve the public, standalone nature of this project.

## Boundaries

- One Incus VM per workspace. Customer SSH/SFTP ends in its restricted development container, never in the VM or compute host.
- PHP owns coordination, validation, durable state and lifecycle. Bash owns small, fixed OS/bootstrap operations. No custom Python, Laravel, or Joomla-based controller.
- No Docker/Incus sockets, privileged development containers, physical-host mounts, or infrastructure credentials in customer workloads.
- Private configuration is outside the checkout. Never request, copy, print, commit or upload actual operator recipes, credentials, topology or customer data.
- Post-handover Joomla files and CLI are customer-controlled code. Do not execute them with guest-root or host authority.
- Fail closed on incompatible Incus capabilities, unknown request fields, resource ownership conflicts, invalid recipes, and missing security controls.

## Development

Use PHP 8.3 or later on Linux. The core uses PHP extensions and the official Incus client rather than vendored application frameworks. Run `php tools/check.php` and `php tests/run.php`. PostgreSQL integration additionally needs pdo_pgsql and the disposable test database described in docs/development.md. Real VM tests require the dedicated lab described in docs/qualification.md.

Changes are incremental: one focused commit per completed task, pushed to the active review branch. Update tests and documentation with behaviour changes. Do not force-push or merge without authorization. Keep progress checkboxes truthful: implementation, mocked tests, database tests, container tests, and real VM qualification are separate evidence.

## Testing safety

Unit tests must not call live Incus or access external credentials. Privileged tests require explicit lab configuration and opt-in. Never connect public pull-request code to production or a persistent privileged runner. Avoid claiming that tests prove escape impossible.

Do not silently adopt unrelated resources, initialize an existing host, format storage, reset customer data, retry non-idempotent steps, or convert private endpoints into publicly reachable services.
