# Phase-one objectives and acceptance

The deliverable is the complete standalone provisioner, not a portal or billing system. A trusted operator/service submits a versioned request; the provisioner reserves resources, constructs an isolated VM, initializes Joomla/JCB, verifies it, and supplies private connection metadata.

## Deliverables

1. Non-destructive host inspection and explicitly authorized preparation of owned project/network resources.
2. Reusable VM image preparation with pinned source fingerprint, fresh instance identity and no baked credentials.
3. PHP request validation, durable operation state, idempotency, reservations, serialization, worker/CLI, and recovery.
4. Pinned JCB and MariaDB workloads, restricted SSH/development image, shared guest-local Joomla files and persistent storage.
5. External versioned VM/container recipes with scoped secret references, bounded execution and verified postconditions.
6. Create, status, verify, suspend, resume, key replacement, reconciliation and ownership-scoped deletion.
7. Functional, persistence, failure, isolation, secret-handling and cleanup tests plus safe public CI.

## Acceptance evidence

A standard deployment must work without private adaptations. Qualification must exercise an actual JCB component import/compile/install and frontend, SSH/SFTP file edits, matching PHP/file identity, and container/VM restart persistence.

Use two known-live workspace endpoints to test same-host and cross-host denial. Test from an intentionally root-controlled disposable guest as well as the development shell. Verify MAC/IP spoofing restrictions, IPv6 blocking, management/metadata isolation, CPU/memory/disk/network limits and denied SMTP. The VM boundary remains required even if container restrictions fail.

Interrupt provisioning between side effects and state persistence; retry the same request and prove no duplication or reinstall. Test failed recipes, rejected unsafe configuration, revoked keys and active-session termination, retained-data resume and complete owned-resource deletion. Test backup/restore in isolation before declaring recovery qualified.

Record commit, image digests/fingerprints, host versions, policy and recipe identity, test outcome and environment. Mock/API tests and hosted CI cannot substitute for real KVM qualification. No release is security-certified merely because this checklist exists.

## Ownership

The provisioner owns its VM lifecycle and resource records. A calling service owns user eligibility and any public edge publication. Phase-one ready means verified private endpoints, not a public DNS record or firewall rule.

## Code-review handoff

Phase-one runtime completion and infrastructure acceptance are separate milestones. Once all runtime, configuration, packaging, developer guidance and test tooling are implemented and available automated checks pass, mark the pull request ready for human review. Report real-VM tests not run as unrun; they do not block that handoff. Humans review, merge and exercise the supplied infrastructure tests on their selected hosts. Do not check an unexecuted test as passed or require an external lab approval service for release automation.
