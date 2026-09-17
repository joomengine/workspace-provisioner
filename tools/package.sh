#!/usr/bin/env bash
set -euo pipefail
umask 022
export LC_ALL=C TZ=UTC
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
cd "$root"
[[ $# == 2 ]] || { echo 'Usage: bash tools/package.sh VERSION NEW_OUTPUT_DIRECTORY' >&2; exit 2; }
version=$(php tools/release.php version "$1")
output=$2
[[ $output == /* && ! -e $output && ! -L $output ]] || { echo 'Use a new absolute output directory.' >&2; exit 2; }
for tool in git tar gzip zip sha256sum composer; do command -v "$tool" >/dev/null; done
git diff --quiet HEAD -- .
[[ -z $(git status --porcelain --untracked-files=no) ]] || { echo 'Commit source changes before packaging.' >&2; exit 1; }
stage=$(mktemp -d)
trap 'rm -rf -- "$stage"' EXIT
mkdir -p "$output" "$stage/workspace-provisioner" "$stage/composer-home" "$stage/composer-cache"
# Only tracked public assets are packaged, never a local override or operator directory.
paths=(README.md LICENSE AGENTS.md CONTRIBUTING.md composer.json composer.lock bootstrap.php bin src guest images tools docs examples tests)
git archive HEAD -- "${paths[@]}" | tar -xf - -C "$stage/workspace-provisioner"
package="$stage/workspace-provisioner"
php tools/release.php manifest "$version" > "$package/release.json"
cp "$package/release.json" "$output/release.json"
# Install only the committed lock with an isolated Composer home; scripts/plugins cannot run.
(
    unset COMPOSER COMPOSER_AUTH COMPOSER_VENDOR_DIR COMPOSER_BIN_DIR COMPOSER_IGNORE_PLATFORM_REQS COMPOSER_IGNORE_PLATFORM_REQ
    export COMPOSER_HOME="$stage/composer-home" COMPOSER_CACHE_DIR="$stage/composer-cache" COMPOSER_ROOT_VERSION="$version"
    cd "$package"
    composer validate --strict
    composer install --no-dev --no-plugins --no-scripts --no-interaction --no-progress --optimize-autoloader
    composer check-platform-reqs --no-dev
    php -r 'require "vendor/autoload.php"; exit(class_exists("JoomEngine\\Workspace\\Application") ? 0 : 1);'
)
mkdir "$package/schemas"
for type in operator inventory catalog recipe request; do php "$package/tools/schema.php" "$type" > "$package/schemas/$type.schema.json"; done
epoch=$(git show -s --format=%ct HEAD)
find "$package" -exec touch -h -d "@$epoch" {} +
tar --sort=name --mtime="@$epoch" --owner=0 --group=0 --numeric-owner \
    -C "$stage" -cf - workspace-provisioner | gzip -n > "$output/workspace-provisioner.tar.gz"
(cd "$stage" && find workspace-provisioner -type f | sort | zip -X -q "$output/workspace-provisioner.zip" -@)
(cd "$output" && sha256sum workspace-provisioner.tar.gz workspace-provisioner.zip release.json > SHA256SUMS)
php "$package/bin/workspace" help >/dev/null
printf 'Packaged version %s from %s\n' "$version" "$(git rev-parse HEAD)"
