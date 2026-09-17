#!/usr/bin/env bash
set -euo pipefail
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
work=$(mktemp -d)
trap 'rm -rf -- "$work"' EXIT
mkdir "$work/bin" "$work/fixture"
printf 'synthetic tar package' > "$work/fixture/workspace-provisioner.tar.gz"
printf 'synthetic zip package' > "$work/fixture/workspace-provisioner.zip"
printf '%s\n' '{"tag":"v1.2.3","name":"joomengine/workspace-provisioner"}' > "$work/fixture/release.json"
printf '%s\n' '{"tag_name":"v1.2.3","draft":false,"prerelease":false}' > "$work/fixture/api.json"
(cd "$work/fixture" && sha256sum workspace-provisioner.tar.gz workspace-provisioner.zip release.json > SHA256SUMS)
cat > "$work/bin/curl" <<'CURL'
#!/usr/bin/env bash
set -euo pipefail
url=${!#}
case "$url" in
    https://api.github.com/repos/joomengine/workspace-provisioner/releases/latest|https://api.github.com/repos/joomengine/workspace-provisioner/releases/tags/v1.2.3)
        cat "$WP_DOWNLOAD_FIXTURE/api.json" ;;
    https://github.com/joomengine/workspace-provisioner/releases/download/v1.2.3/*)
        cat "$WP_DOWNLOAD_FIXTURE/${url##*/}" ;;
    *) echo 'Unexpected URL or moving-URL asset download.' >&2; exit 1 ;;
esac
CURL
chmod +x "$work/bin/curl"
export PATH="$work/bin:$PATH" WP_DOWNLOAD_FIXTURE="$work/fixture"
bash "$root/tools/download-release.sh" latest "$work/latest"
bash "$root/tools/download-release.sh" v1.2.3 "$work/version"
cmp "$work/latest/SHA256SUMS" "$work/version/SHA256SUMS"
if bash "$root/tools/download-release.sh" latest "$work/latest" >/dev/null 2>&1; then exit 1; fi
printf 'corrupted' >> "$work/fixture/workspace-provisioner.tar.gz"
if bash "$root/tools/download-release.sh" latest "$work/corrupt" >/dev/null 2>&1; then exit 1; fi
[[ ! -e $work/corrupt ]]
if bash "$root/tools/download-release.sh" '../unsafe' "$work/unsafe" >/dev/null 2>&1; then exit 1; fi
[[ -z $(find "$work" -maxdepth 1 -name '.wp-download-*' -print -quit) ]]
printf 'PASS version-bound latest downloads, checksum enforcement, retries and cleanup\n'
