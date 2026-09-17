#!/usr/bin/env bash
set -euo pipefail
umask 077
[[ $# == 2 ]] || { echo 'Usage: bash tools/download-release.sh latest|vMAJOR.MINOR.PATCH NEW_DIRECTORY' >&2; exit 2; }
selector=$1 destination=$2
[[ $selector == latest || $selector =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || exit 2
[[ $destination == /* && ! -e $destination && ! -L $destination && -d $(dirname -- "$destination") ]] || exit 2
for tool in curl jq sha256sum; do command -v "$tool" >/dev/null; done
repository=joomengine/workspace-provisioner
stage=$(mktemp -d "$(dirname -- "$destination")/.wp-download-XXXXXXXX")
trap 'rm -rf -- "$stage"' EXIT
endpoint="tags/$selector"
[[ $selector != latest ]] || endpoint=latest
curl --fail --silent --show-error --location --proto '=https' --tlsv1.2 --retry 3 --max-time 120 \
    "https://api.github.com/repos/$repository/releases/$endpoint" > "$stage/api.json"
tag=$(jq -er 'select(.draft == false and .prerelease == false) | .tag_name' "$stage/api.json")
[[ $tag =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo 'Invalid stable release tag.' >&2; exit 1; }
[[ $selector == latest || $selector == "$tag" ]] || exit 1
# Resolve latest exactly once, then use immutable version URLs for the entire download.
for asset in workspace-provisioner.tar.gz workspace-provisioner.zip release.json SHA256SUMS; do
    curl --fail --silent --show-error --location --proto '=https' --tlsv1.2 --retry 3 --max-time 300 \
        "https://github.com/$repository/releases/download/$tag/$asset" > "$stage/$asset"
done
[[ $(wc -l < "$stage/SHA256SUMS") -eq 3 ]]
grep -Eq '^[a-f0-9]{64}  workspace-provisioner.tar.gz$' "$stage/SHA256SUMS"
grep -Eq '^[a-f0-9]{64}  workspace-provisioner.zip$' "$stage/SHA256SUMS"
grep -Eq '^[a-f0-9]{64}  release.json$' "$stage/SHA256SUMS"
(cd "$stage" && sha256sum --strict -c SHA256SUMS)
[[ $(jq -er '.tag' "$stage/release.json") == "$tag" ]]
[[ $(jq -er '.name' "$stage/release.json") == "$repository" ]]
rm "$stage/api.json"
[[ ! -e $destination && ! -L $destination ]]
mv -T -- "$stage" "$destination"
printf 'Verified %s; packages saved without changing installed code or private configuration.\n' "$tag"
