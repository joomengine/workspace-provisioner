#!/usr/bin/env bash
set -euo pipefail
export LC_ALL=C TZ=UTC
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
cd "$root"
[[ $# == 2 ]] || { echo 'Usage: bash tools/package.sh VERSION NEW_OUTPUT_DIRECTORY' >&2; exit 2; }
version=$(php tools/release.php version "$1")
output=$2
[[ $output == /* && ! -e $output && ! -L $output ]] || { echo 'Use a new absolute output directory.' >&2; exit 2; }
for tool in git tar gzip zip sha256sum; do command -v "$tool" >/dev/null; done
git diff --quiet HEAD -- .
[[ -z $(git status --porcelain --untracked-files=no) ]] || { echo 'Commit source changes before packaging.' >&2; exit 1; }
stage=$(mktemp -d)
trap 'rm -rf -- "$stage"' EXIT
mkdir -p "$output" "$stage/workspace-provisioner"
# An explicit tracked-path allowlist excludes operator state, CI secrets and local overrides.
paths=(README.md LICENSE AGENTS.md CONTRIBUTING.md composer.json composer.lock bootstrap.php bin src guest images tools docs examples)
[[ ! -d schemas ]] || paths+=(schemas)
git archive HEAD -- "${paths[@]}" | tar -xf - -C "$stage/workspace-provisioner"
php tools/release.php manifest "$version" > "$stage/workspace-provisioner/release.json"
cp "$stage/workspace-provisioner/release.json" "$output/release.json"
epoch=$(git show -s --format=%ct HEAD)
find "$stage/workspace-provisioner" -exec touch -h -d "@$epoch" {} +
tar --sort=name --mtime="@$epoch" --owner=0 --group=0 --numeric-owner \
    -C "$stage" -cf - workspace-provisioner | gzip -n > "$output/workspace-provisioner.tar.gz"
(cd "$stage" && find workspace-provisioner -type f | sort | zip -X -q "$output/workspace-provisioner.zip" -@)
(cd "$output" && sha256sum workspace-provisioner.tar.gz workspace-provisioner.zip release.json > SHA256SUMS)
php "$stage/workspace-provisioner/bin/workspace" help >/dev/null
printf 'Packaged version %s from %s\n' "$version" "$(git rev-parse HEAD)"
