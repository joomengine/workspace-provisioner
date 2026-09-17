#!/usr/bin/env bash
set -euo pipefail
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
work=$(mktemp -d)
trap 'rm -rf -- "$work"' EXIT
bash "$root/tools/package.sh" 0.1.0-dev.1 "$work/one"
bash "$root/tools/package.sh" 0.1.0-dev.1 "$work/two"
cmp "$work/one/SHA256SUMS" "$work/two/SHA256SUMS"
(cd "$work/one" && sha256sum -c SHA256SUMS)
mkdir "$work/unpacked"
tar -xzf "$work/one/workspace-provisioner.tar.gz" -C "$work/unpacked"
package="$work/unpacked/workspace-provisioner"
for path in bin/workspace bootstrap.php composer.json composer.lock guest/runner.php images/development/Dockerfile; do
    test -f "$package/$path"
done
[[ ! -e $package/.git && ! -e $package/.github && ! -e $package/local && ! -e $package/var ]]
php "$package/bin/workspace" help >/dev/null
# Test Composer's actual validation/autoloading when available (required in hosted package CI).
if command -v composer >/dev/null; then
    (cd "$package" && composer validate --strict && composer install --no-dev --no-plugins --no-scripts --no-interaction)
    (cd "$package" && php -r 'require "vendor/autoload.php"; exit(class_exists("JoomEngine\\Workspace\\Application") ? 0 : 1);')
fi
printf 'PASS reproducible package, complete runtime assets, checksums and extracted CLI\n'
