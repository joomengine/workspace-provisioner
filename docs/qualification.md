# Testing and human infrastructure acceptance

A complete runtime, its automated tests, and a deployed infrastructure acceptance result are distinct facts. A runtime PR is handed to humans by marking it ready for review after implementation and available automated checks are complete. Unrun real-VM tests are disclosed, not invented as a prerequisite for that handoff or for package publication.

## Automated checks

`php tools/check.php` validates PHP syntax/JSON and `php tests/run.php` runs deterministic unit and contract tests. `php tests/postgres.php` tests the state store against the disposable database documented in [development](development.md). `WP_RUN_CONTAINER_TESTS=1 bash tests/container.sh` installs actual Joomla/JCB and exercises restricted SSH/SFTP, shared files, matching PHP, private Composer inputs, restarts and workload settings on an isolated Docker host. It never runs against a production daemon.

`bash tests/package.sh` requires Composer and verifies two independently built archives, checksums, generated autoload files/schemas and the extracted CLI. Hosted package CI runs these checks and publishes a candidate artifact. Stable package publication runs after merge and its automated checks as described in [releases](releases.md). There is no required external check-emitting App or lab-dispatch service.

Mocks and Docker tests do not claim KVM isolation. Public PR jobs have no privileged infrastructure credentials and must not execute on a production-connected runner.

## Real Incus lifecycle runner

The package includes `tests/incus.php`. Invoke it explicitly:

```
WP_RUN_INCUS_TESTS=1 php tests/incus.php /absolute/private/lab-plan.json /absolute/private/new-report.json
```

This operator tool creates two to four disposable workspaces, verifies idempotent submissions, SSH host identities, SSH/SFTP shared-file roundtrips, web reads, network denial from the customer shell and guest root, key revocation and active-session termination, encrypted backup/replacement restore with file/database comparisons, and container/VM restart persistence. It deletes its own workspaces through the normal lifecycle even after failure. A cleanup failure is reported as a failed run, not hidden. Encrypted test backups remain in the private backup directory for inspection and deliberate retention cleanup.

The external plan requires `version: 1`, the absolute `operator` configuration path, `caller`, `tenant`, `catalog`, `profile`, `instances` (2-4), `disposable: true` and `confirm_installation` matching that configuration's installation UUID. It rejects unknown fields, missing authorization or an existing operation/workspace history. Create a fresh dedicated configuration/store for each run; never point it at a live installation. Prepare its owned host resources and images first using [operations](operations.md). The runner verifies, but does not initialize, host networks/storage. It needs direct authorized routing to the private workspace IPs, OpenSSH client/SFTP, curl, PHP and the configured Incus client on the management machine.

The test creates unique keys and synthetic data, not real customer material. Do not share production topology or credentials in a public workflow. It executes bounded guest-root *test probes* through the existing Incus management path; this never grants a customer VM access.

## Application-specific and network checks

Optional `application_checks` is a list of objects with `argv` and `verify_argv`, each starting with a Joomla CLI command name. These execute as the customer inside the development container, not guest root. An optional `stdout` requires an exact verification output. `timeout` is 1-1800 seconds (default 600). Supply the selected JCB release's real component import, compile and installation operations and meaningful postconditions. `frontend_path` plus `frontend_contains` can verify the generated component's frontend through the private workspace endpoint. These commands and private demo selections stay in the external plan; the public harness does not guess version-specific command names. Omission is recorded as **skip**, not as a compiled-component pass.

Optional `denied_targets` is a list of `{address, port}` objects identifying approved, known-live private lab destinations (IPv4 or IPv6). The management client must first connect successfully, then both the customer shell and root inside its VM must be denied. A dead listener or broken baseline route fails the test rather than falsely proving isolation.

Workspace-to-workspace probes also establish a live baseline. Same-host and cross-host results are recorded separately according to actual allocator placement. Configure capacity/address pools so two workspaces land on one host and another on a distinct host to exercise both cases. Two VMs on one host never count as a cross-host test. An unexercised topology is explicitly skipped.

## Remaining human acceptance

The runner does not reboot a physical compute server, launch uncontrolled stress jobs, or pretend that comparing JSON proves resistance to every spoofing attack. Human infrastructure acceptance additionally exercises compute-host reboot/reconciliation, active MAC/IP spoofing resistance, IPv6 policy, external CPU/memory/disk/I/O/network containment, image-update recovery, and a security review of the intended topology. Deterministic tests already inject lifecycle failures and check interrupted import, stale ownership, unsafe configuration, timeout/output limits, secret envelopes and backup corruption. Extend controlled lab tests as new environments are qualified.

Reports record the source revision, runtime/image/policy/recipe identity, individual pass/fail/skip results, timestamps and cleanup outcome. Raw command output, customer data, keys and addresses are not included. The report is a private mode-0600 file; review/sanitize it before any public publication. It explicitly does not label a lifecycle run as complete security certification. Retain long-lived human acceptance reports independently of expiring CI artifacts.
