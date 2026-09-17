# Phase 1 runtime coverage and review handoff

This map distinguishes implemented runtime from tests that require an operator's actual infrastructure. It is not a security certificate. CI run links and exact reviewed revisions belong in the PR; source and release artifacts carry their own commit identity.

| Objective | Runtime and tooling | Verification entry points |
| --- | --- | --- |
| Standalone setup and host boundaries | `Config`, `Host`, `NetworkPolicy`, `bin/workspace`, `docs/operations.md` | Configuration/topology/Incus contract cases; actual host preflight |
| Reusable VM and development images | `tools/build-vm.sh`, `guest/build-base.sh`, `tools/build-images.sh`, `images/development` | Shell checks; hosted development-image build; operator VM acceptance |
| Versioned requests and private adaptations | `Request`, `Recipe`, `Schema`, `WorkspaceComposer`, schema generator | Request/recipe/schema/Composer contract cases; synthetic Docker Composer test |
| Private image selection including latest | `ImageResolver`, saved `resolved_images`, `Compose` | Image-resolution and interrupted-install tests; hosted Docker integration |
| Durable service interface and worker | `Application`, `Journal`, `FileStore`, `PgStore`, `Engine`, CLI, `WorkerService` | Idempotency/capacity/authorization/fencing cases, PostgreSQL tests, worker-unit tests |
| Joomla/JCB and container-only SSH | `Compose`, guest helpers/probe, development image | Hosted actual JCB installation, SSH/SFTP, shared files and runtime matching |
| Lifecycle, revocation and no-reinstall recovery | `Engine`, `IncusRuntime`, guest service and key handling | Injected failure/retry/handover cases; operator lifecycle runner |
| Scoped credentials and private state | `Files`, `Vault`, per-workspace secret transfer | Envelope/permissions/symlink tests; hosted container secret boundaries |
| Encrypted backups and retained-workspace restore | `Archive`, `BackupStore`, runtime backup/restore, explicit replacement checkpoints | Corruption/context tests, lost-response adapter tests, encrypted safety-backup retry; operator DB/file restore |
| Semantic releases and installable latest packages | `Release`, release planner, package/publish/download scripts, workflows | Version tests, reproducible package/Composer/schema tests, offline download tests |
| Human-run infrastructure acceptance | `tests/incus.php`, `tests/LabPlan.php`, qualification guide | Opt-in safety tests; actual multi-workspace lifecycle/network/restore runner on operator hosts |

## Handoff standard

All runtime/tooling objectives above are part of the implementation. A PR with that implementation and successful available automated checks is ready for human review. The human team then reviews, merges and performs host-specific acceptance. No external App, required lab check or manual proof submission is needed to make the source reviewable or to operate its automatic release workflow.

Actual Incus/KVM execution is not claimed by hosted Docker or mocked API tests. The lab runner reports skipped image-specific/topology checks separately, and the qualification guide identifies physical-host reboot, active spoofing, stress and security review work. These are deployment acceptance tasks, not absent runtime methods. Never relabel an unrun test as passed or infer that a published package has been validated on every operator network.

## Deliberate scope boundaries

The controller manages independent Incus remotes and serializes infrastructure operations per store. It does not claim clustered HA, parallel executor throughput, arbitrary guest distributions, arbitrary Docker overrides or invisible upgrades of customer data. The initial VM builder targets Ubuntu 24.04 and the development builder targets the matching Apache JCB runtime. These constraints are validated/documented rather than silently weakened.

Public DNS, OPNsense publication, account registration, trials and billing are external integrations. The package exposes a trusted PHP/CLI lifecycle and private connection information for them; it does not implement or depend on a commercial portal.
