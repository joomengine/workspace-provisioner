# Contributing

Contributions are reviewed through pull requests with focused commits. Read AGENTS.md and the phase-one acceptance criteria first.

Use PHP 8.3+, four-space indentation, strict types, typed interfaces and argument-array subprocess execution. Bash scripts use strict mode, explicit validation and ShellCheck. Production code must not require private recipes or a proprietary service.

Run `php tools/check.php` and `php tests/run.php` before pushing. Database and infrastructure tests are opt-in and must use disposable resources. Include the exact verification performed in the PR; do not mark real-VM gates passed based on mocks.

Discuss changes to isolation, persistence, request/recipe compatibility or destructive operations before broadening authority. Unknown or unsupported configuration must fail rather than fall back to weaker security.

Report suspected vulnerabilities privately using the repository's private vulnerability reporting facility when enabled. Do not attach credentials, customer data, exploit targets or private operational configuration to a public issue.

Apache-2.0 covers original contributions. Preserve upstream notices; container dependencies retain their own licenses.
