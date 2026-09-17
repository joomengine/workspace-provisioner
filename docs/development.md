# Development environment

Use Linux with PHP 8.3 or 8.4 and the json, pdo, posix and sodium extensions. The core deliberately has no external PHP runtime dependencies: the CLI/application interface can be called without downloading a framework. Composer supplies standard package metadata and optional PSR-4 autoloading; bootstrap.php also supports a source checkout directly.

On a disposable Debian/Ubuntu development machine, install PHP CLI and its PostgreSQL extension, PostgreSQL, Composer, util-linux, jq and ShellCheck through the distribution packages appropriate to the selected PHP version. Do not run host preparation merely to execute unit tests.

Run:

```bash
php tools/check.php
php tests/run.php
```

These commands do not contact Incus, read operator configuration or require Docker. Tests create owner-only temporary directories and synthetic credentials, then remove them.

For database tests, create an isolated PostgreSQL database whose name ends in `_test`. Set WP_TEST_DSN, WP_TEST_USER and WP_TEST_PASSWORD to that disposable database, then run `php tests/postgres.php`. The test refuses a DSN without the test-name convention. It exercises migrations, rollback, visibility and advisory-lock behaviour; never aim it at an operational database.

The file-backed store is for local development or a single controller on local storage. PostgreSQL is recommended for operational installations. The initial journal serializes short state mutations and allows one active infrastructure executor per store; this is an intentional safety boundary, not a claim of parallel provisioning. Multiple trusted submitters may enqueue requests. State transactions are not held across remote operations.

Public CI uses read-only repository permissions, pinned actions and disposable hosted runners. It does not receive operator secrets or access a compute host. Actual VM qualification is subsequent operator acceptance, not a prerequisite for handing completed runtime code to human reviewers.
