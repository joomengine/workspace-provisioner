# Versioned packages and the latest channel

The generic provisioner is released independently of any operator's chosen JCB image, private recipes, Composer manifests, credentials or topology. Those inputs stay outside the repository and distribution archive. The provisioner's own composer.json and composer.lock ship in the package; they are not the customer's application dependency manifest.

## Automatic publication

The `Automated stable release` workflow runs for main changes, an explicit rerun, or an authorized `workspace-qualified` repository dispatch. It runs the PHP/database quality suite, Docker integration, and package verification before publication. The publisher additionally requires a successful `Incus qualification` check for the **exact main commit**, issued by the GitHub App ID in the `QUALIFICATION_APP_ID` repository variable. A missing, pending, failed or untrusted check prevents publication. Configure the `release` environment and its branch/protection rules before enabling stable releases. No production-connected self-hosted runner is attached to public pull-request jobs.

After the lab records that check, it can send the authorized repository dispatch to resume the release automatically. That integration must be supplied by the protected lab; this repository does not invent a passing lab result. The workflow always checks the current main SHA and rejects stale qualification. It never publishes the PR head or silently marks Phase 1 qualified.

Versioning starts at 0.1.0. A conventional `feat:` commit advances the minor number; an exclamation mark in a conventional header or a `BREAKING CHANGE:` footer advances the major number. Other changes advance the patch number. The highest required increment wins. Commit messages therefore need to reflect compatibility impact. No manual version bump or moving Git tag is required. Repeating a published revision does not replace its assets.

The workflow calculates the version, builds all assets, creates/updates a **draft** release, uploads and re-downloads/checks its assets, then publishes it and marks it latest. It performs these steps in one workflow rather than depending on a token-created tag to trigger a second workflow. A failed build or upload leaves the previous latest release in place.

## Package contents and usage

Every version has these stable asset names:

- `workspace-provisioner.tar.gz` and `workspace-provisioner.zip`: the PHP application, Composer metadata/lock, CLI, Bash helpers, guest assets, image definitions, examples and documentation.
- `release.json`: version, exact source SHA, source timestamp and package entrypoint.
- `SHA256SUMS`: hashes for the two archives and release metadata.

`bash tests/package.sh` builds twice, compares checksums, extracts the archive and exercises its CLI and Composer autoloading. Hosted CI requires Composer for this check. The code has no external PHP runtime packages; PHP and its documented extensions, Incus tooling and host prerequisites remain operator-managed. The archive is a provisioner distribution, not a bundled VM disk or JCB Docker image.

Use GitHub's `releases/latest` endpoint for a stable channel. Resolve that endpoint to one version tag before downloading its assets; do not fetch the archive and checksum independently through a moving latest URL. Record the resolved version, source SHA and checksum in the operator deployment record. Preserve immutable versioned releases for rollback. The first latest channel is available only after the first qualified stable publication.

A new provisioner package must not replace a running worker mid-operation or reinitialize existing workspaces. The surrounding service stages the package, verifies it, drains/stops workers, applies reviewed state migrations where necessary and switches versions under its deployment policy. Existing workspace image digests and user files are not automatically rewritten by a provisioner release.

The source-only workflow artifact is a review aid; a release-package artifact is an installable candidate. Neither is a published or qualified stable release by itself.
