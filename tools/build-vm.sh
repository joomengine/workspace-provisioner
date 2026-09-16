#!/bin/bash
set -euo pipefail
umask 077
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
if [[ $# -ne 4 || $1 == --help ]]; then
    echo 'Usage: bash tools/build-vm.sh SOURCE_FINGERPRINT STORAGE_POOL BUILD_NETWORK OUTPUT_JSON' >&2
    echo 'Uses an existing local Ubuntu 24.04 Incus VM image and existing build network/storage; never initializes a host.' >&2
    exit 2
fi
source_image=$1 pool=$2 network=$3 output=$4
[[ $source_image =~ ^[a-f0-9]{64}$ && $pool =~ ^[a-zA-Z0-9_-]+$ && $network =~ ^[a-zA-Z0-9_-]+$ ]]
[[ $output == /* && ! -e $output && ! -L $output && -d $(dirname -- "$output") ]]
command -v incus >/dev/null
command -v jq >/dev/null
command -v php >/dev/null
incus --project default query "/1.0/images/$source_image" --raw | jq -e '.metadata.type == "virtual-machine"' >/dev/null
incus --project default storage show "$pool" >/dev/null
incus --project default network show "$network" >/dev/null
identity=$(php -r 'echo bin2hex(random_bytes(8));')
project="wp-build-$identity"
alias="wp-template-$identity"
created=false
cleanup() {
    local status=$?
    trap - EXIT
    if [[ $created == true ]]; then
        if [[ $(incus project get "$project" user.wp.builder 2>/dev/null) == "$identity" ]]; then
            incus --project "$project" delete builder --force >/dev/null 2>&1 || true
            incus project delete "$project" >/dev/null 2>&1 || true
        else
            echo 'Builder ownership changed; resources were not removed.' >&2
        fi
    fi
    exit "$status"
}
trap cleanup EXIT
incus project create "$project" -c "user.wp.builder=$identity" -c features.images=false -c features.profiles=true -c features.networks=false
created=true
incus --project "$project" init "$source_image" builder --vm --profile '' --storage "$pool" --network "$network" \
    -c limits.cpu=2 -c limits.memory=2GiB -c boot.autostart=false -d root,size=12GiB
incus --project "$project" start builder
ready=false
for ((i=0; i<90; i++)); do
    if incus --project "$project" exec builder -- /usr/bin/true 2>/dev/null; then ready=true; break; fi
    sleep 2
done
[[ $ready == true ]]
incus --project "$project" file push "$root/guest/build-base.sh" builder/root/wp-build-base.sh --mode 0700
incus --project "$project" exec builder -- /bin/bash /root/wp-build-base.sh
incus --project "$project" stop builder --timeout 60
incus --project "$project" publish builder --alias "$alias" wp.template.version=1 "wp.source.fingerprint=$source_image"
fingerprint=$(incus --project default query "/1.0/images/aliases/$alias" --raw | jq -er '.metadata.target')
[[ $fingerprint =~ ^[a-f0-9]{64}$ ]]
jq -n --arg fingerprint "$fingerprint" --arg source "$source_image" \
    '{version:1,vm_image:$fingerprint,source_fingerprint:$source,qualification:"not-yet-run"}' > "$output"
echo "Created VM template $fingerprint; provenance written to $output"
