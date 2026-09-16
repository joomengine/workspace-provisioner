#!/bin/bash
set -euo pipefail
umask 077
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
if [[ $# -ne 6 || $1 == --help ]]; then
    echo 'Usage: bash tools/build-images.sh JCB_IMAGE MARIADB_IMAGE COMPOSER_IMAGE DEVELOPMENT_TAG VM_PROVENANCE_JSON OUTPUT_CATALOG_JSON' >&2
    echo 'Resolves inputs to digests, builds and PUSHES the development image using existing operator registry authentication.' >&2
    exit 2
fi
jcb=$1 database=$2 composer=$3 development=$4 vm=$5 output=$6
for image in "$jcb" "$database" "$composer" "$development"; do
    [[ $image =~ ^[a-z0-9][a-z0-9._/:@-]+$ ]]
done
[[ $vm == /* && -f $vm && $output == /* && ! -e $output && ! -L $output ]]
command -v docker >/dev/null
command -v jq >/dev/null
resolve() {
    local image=$1 digest
    docker pull "$image" >&2
    digest=$(docker image inspect --format '{{index .RepoDigests 0}}' "$image")
    [[ $digest =~ @sha256:[a-f0-9]{64}$ ]]
    printf '%s' "$digest"
}
jcb=$(resolve "$jcb")
database=$(resolve "$database")
composer=$(resolve "$composer")
fingerprint=$(jq -er '.vm_image' "$vm")
[[ $fingerprint =~ ^[a-f0-9]{64}$ ]]
docker build --pull --build-arg "JCB_IMAGE=$jcb" --build-arg "COMPOSER_IMAGE=$composer" \
    --tag "$development" "$root/images/development"
docker push "$development"
development=$(resolve "$development")
jq -n --arg vm "$fingerprint" --arg jcb "$jcb" --arg db "$database" --arg dev "$development" \
    '{standard:{vm_image:$vm,jcb_image:$jcb,database_image:$db,development_image:$dev,uid:33,gid:33}}' > "$output"
echo "Pinned catalog written to $output. Run container and VM qualification before approving it."
