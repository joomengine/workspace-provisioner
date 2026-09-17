# Automated versions and releases

Merging reviewed work to `main` triggers the complete Quality workflow (PHP and PostgreSQL), Docker integration, and installable package verification. Successful checks publish a semantic version and advance GitHub's stable **latest release**. No separate lab service, GitHub App, repository variable or human qualification check is needed to publish. Public pull-request workflows have no publishing permission.

The first version is `0.1.0`. Subsequent releases increment major for conventional breaking-change commits, minor for `feat`, and patch otherwise. Release planning considers commits since the highest reachable stable tag. Existing version tags and published assets are immutable; retrying publication resumes the same draft or recognizes an already published revision. Publication is serialized. A superseded main revision cannot advance latest.

## Package contents

The release includes `workspace-provisioner.tar.gz`, `workspace-provisioner.zip`, `release.json`, and `SHA256SUMS`. Both archives contain runtime PHP, `bin/workspace`, guest helpers, image builders, schemas, examples, operator tooling, documentation, the license, Composer manifest/lock and generated autoload files. No private configuration, customer data or operator credentials are packaged. Package tests extract and execute the CLI, validate Composer metadata, and compare two independently built archives.

`release.json` records the version, tag and exact commit. Checksums cover the download assets. Publication first uploads to a draft, downloads and verifies its assets, then publishes and advances latest. A failed draft is not the stable channel.

## Tracking latest

The stable download endpoint is:

```
https://github.com/joomengine/workspace-provisioner/releases/latest/download/workspace-provisioner.tar.gz
```

Resolve the latest release once, then download all assets from that **version's** URLs and verify `SHA256SUMS`; otherwise a new release between downloads can mix versions. Keep the previously installed package for rollback. A latest release is an alias to a versioned artifact, not a mutable version tag or a moving Git branch. Composer uses semantic version tags or constraints; `latest` is not a Composer version constraint.

The provisioner's package version is independent of the JCB Docker selector. The operator's external catalog chooses JCB, database and development images and may use `latest`; the provisioner resolves floating image tags to digests once per new workspace. Existing workspace digests and customer files are never silently rewritten by a provisioner update.

## Review and deployment

A complete runtime with passing automated checks is ready for human review. Reviewers and operators perform infrastructure acceptance on their selected Incus/KVM hosts after that handoff. These tests remain supplied and documented; they are not an invented prerequisite for marking a code PR ready or producing a package. Neither a published release nor green hosted CI claims that every operator topology has been tested or that VM escape is impossible.

The source-only workflow archive is a review aid. Release-package workflow artifacts are installable candidates, not automatically published releases. No release from this branch is published before the owner merges it.

The bundled `tools/download-release.sh` accepts `latest` or an explicit stable `vMAJOR.MINOR.PATCH` and a new absolute destination directory. It resolves the release once, downloads all assets by immutable version URL, validates their checksum manifest and release identity, then publishes the download directory. It does not extract or execute downloaded files, overwrite an existing directory, or mutate private configuration. `tests/download.sh` verifies this behaviour without network access. Preview package versions retain their full numbered artifact identity in `release.json`; generated Composer metadata uses Composer-compatible development/RC notation.
