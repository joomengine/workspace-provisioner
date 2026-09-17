# Qualification and evidence

A source change, passing unit test and real infrastructure qualification are different facts. Keep this distinction in PR checklists and release reports. No completed full Incus/KVM qualification is claimed by this document.

## Public automated checks

Run `php tools/check.php` and `php tests/run.php` for syntax and deterministic unit/contract tests. `php tests/postgres.php` exercises the operation store against the explicitly configured disposable PostgreSQL database described in [development](development.md). `WP_RUN_CONTAINER_TESTS=1 bash tests/container.sh` is an opt-in integration test for an isolated Docker host. It must not run against a production daemon. The script creates and removes only its uniquely named test project and files.

The Docker test resolves public image tags, builds the development image from the selected JCB base, installs actual Joomla/JCB, applies synthetic locked Composer files, repeats dependency installation, checks SSH/SFTP/shared files, compares PHP runtimes, restarts services and inspects container restrictions. It does not claim VM isolation, a successful JCB component compilation, or a successful full VM restore.

`bash tests/package.sh` verifies reproducibility, runtime assets, archive checksums and the extracted CLI. With Composer available it validates the shipped lockfile and generated autoloader; the GitHub package job installs Composer, so these checks must run there. A local run without Composer is not evidence for that portion. Stable release publication is separately gated; [releases](releases.md) describes the exact-commit check and the protected lab identity.

## Protected real-VM lab

Provisioning/isolation qualification requires administrator-approved, disposable Incus/KVM hosts and a protected management machine. Use synthetic identities and empty customer data only. Keep lab configuration outside the checkout. No public pull-request job receives lab credentials. Only reviewed commits may run in the lab. Two VMs on one host do not establish a cross-host result; use at least two distinct compute hosts for that assertion.

Before tests, record the source commit, VM fingerprint, resolved application image digests, PHP/Docker/Incus/QEMU/kernel versions, policy and recipe identities, and non-sensitive host identifiers. Confirm the target hosts/projects and storage are disposable and not connected to production networks. The operator must expressly approve destructive lifecycle tests and capacity stress. Do not initialize unrelated Incus infrastructure to make a test pass.

The following are mandatory acceptance groups, not evidence of an already completed harness or run:

| Group | Required successful assertions |
| --- | --- |
| Host and image | Non-destructive preflight, owned-resource preparation, incompatible capability rejection, clean VM images and distinct keys/identities |
| Application | Standard deployment without private adaptations; separate synthetic VM/Joomla recipe; actual component import, compile, install and working frontend |
| Access and persistence | Container-only SSH/SFTP, matching users/runtimes, shared file edits, database/file persistence after container, VM and compute-host restart |
| Isolation | Known-live forbidden destinations denied from the development shell and from root inside the disposable VM; same-host and cross-host cases; spoofing and IPv6 restrictions |
| Limits | External CPU/memory/storage/I/O/network containment without destabilizing neighboring workspaces or the host |
| Recovery | Failure injection between side effect and state persistence, duplicate and concurrent requests, safe retry, no reinstall after handover, retained-data resume |
| Revocation | Actual active SSH/web access stopped by suspension, revoked keys rejected, resumed access only after verification |
| Backup and deletion | Authenticated encrypted export, corruption/wrong-context rejection, actual restore, stale key protection and owned-resource cleanup |
| Confidentiality | No infrastructure credentials/private recipes in guests, public output, image layers or test artifacts |

A negative networking test must first demonstrate that its destination is listening and reachable from an authorized source. Otherwise a closed port or a broken route can give a false isolation result. A policy JSON comparison is not a substitute for packets sent between real machines. An uncontrolled failed backup is not a successful recovery test.

## Evidence record

For each objective preserve a sanitized record with objective ID, implementation commit/files, test file and exact command, expected result/assertions, actual pass/fail/skip outcome, UTC time and environment identity. Reference the complete log/report. Do not summarize a skipped check as passed. Reports should cover the exact reviewed source revision; rerun affected tests after changes.

Public evidence can include commit IDs, image digests, test names and outcomes. Keep private recipes, real addresses, credentials and customer data out of it. Retain detailed private diagnostics separately. Store long-lived qualification evidence beyond the expiration of short-lived workflow artifacts.

The trusted lab's GitHub App may emit an `Incus qualification` check only after verifying every required group. The check must target the exact commit and identify the retained report. Configuring an App ID or sending a repository dispatch does not itself establish qualification. A missing result blocks stable release promotion. This repository does not create a successful check from a self-declared JSON file.
