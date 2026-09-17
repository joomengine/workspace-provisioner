#!/usr/bin/env bash
set -euo pipefail
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
work=$(mktemp -d)
trap 'rm -rf -- "$work"' EXIT
command -v composer >/dev/null
bash "$root/tools/package.sh" 0.1.0-dev.1 "$work/one"
bash "$root/tools/package.sh" 0.1.0-dev.1 "$work/two"
cmp "$work/one/SHA256SUMS" "$work/two/SHA256SUMS"
(cd "$work/one" && sha256sum -c SHA256SUMS)
mkdir "$work/unpacked"
tar -xzf "$work/one/workspace-provisioner.tar.gz" -C "$work/unpacked"
package="$work/unpacked/workspace-provisioner"
for path in bin/workspace bootstrap.php composer.json composer.lock guest/runner.php images/development/Dockerfile \
    vendor/autoload.php schemas/request.schema.json schemas/operator.schema.json tools/worker-service.php tests/run.php tests/incus.php tests/LabPlan.php; do
    test -f "$package/$path"
done
[[ ! -e $package/.git && ! -e $package/.github && ! -e $package/local && ! -e $package/var ]]
php "$package/bin/workspace" help >/dev/null
(cd "$package" && php -r 'require "vendor/autoload.php"; exit(class_exists("JoomEngine\\Workspace\\Application") ? 0 : 1);')
(cd "$package" && composer validate --strict && composer check-platform-reqs --no-dev)
for type in operator inventory catalog recipe request; do
    php "$package/tools/schema.php" "$type" > "$work/schema.json"
    cmp "$work/schema.json" "$package/schemas/$type.schema.json"
done
printf 'PASS reproducible installable package, Composer autoload, schemas, checksums and extracted CLI\n'

bash "$root/tests/download.sh"
