#!/bin/bash
set -euo pipefail
umask 077
[[ ${WP_RUN_CONTAINER_TESTS:-} == 1 ]] || { echo 'Set WP_RUN_CONTAINER_TESTS=1 only on a disposable Docker test host.' >&2; exit 2; }
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
work=$(mktemp -d /tmp/wp-container-XXXXXXXX)
project="wp-test-$(basename -- "$work" | tr '[:upper:]' '[:lower:]')"
compose=(docker compose --project-name "$project" -f "$work/compose.json")
cleanup() {
    local status=$?
    trap - EXIT
    if [[ -f $work/compose.json ]]; then "${compose[@]}" down --remove-orphans --volumes >/dev/null 2>&1 || true; fi
    if [[ $work == /tmp/wp-container-* ]]; then
        if [[ $(id -u) == 0 ]]; then rm -rf -- "$work"; else sudo -n rm -rf -- "$work"; fi
    fi
    exit "$status"
}
trap cleanup EXIT
mkdir -p "$work"/{data/{site,database,home,build},control/{authorized-keys,host-keys,secrets}}
chmod 0755 "$work/data" "$work/data/site" "$work/control/authorized-keys"
cp "$root/guest/install.sh" "$root/guest/probe.php" "$work/control/"
chmod 0644 "$work/control/"{install.sh,probe.php}
ssh-keygen -q -t ed25519 -N '' -f "$work/client"
ssh-keygen -q -t ed25519 -N '' -f "$work/control/host-keys/ssh_host_ed25519_key"
cp "$work/client.pub" "$work/control/authorized-keys/developer"
chmod 0644 "$work/control/authorized-keys/developer"
if [[ $(id -u) == 0 ]]; then chown -R 0:0 "$work/control/authorized-keys"; else sudo -n chown -R 0:0 "$work/control/authorized-keys"; fi
if [[ $(id -u) == 0 ]]; then chown 33:33 "$work/data/"{site,home,build}; else sudo -n chown 33:33 "$work/data/"{site,home,build}; fi
resolve() {
    docker pull "$1" >&2
    docker image inspect --format '{{index .RepoDigests 0}}' "$1"
}
jcb=$(resolve "${WP_TEST_JCB_IMAGE:-octoleo/joomengine:latest}")
database=$(resolve "${WP_TEST_DATABASE_IMAGE:-mariadb:11.4}")
composer=$(resolve "${WP_TEST_COMPOSER_IMAGE:-composer:2}")
development="$project:development"
docker build --build-arg "JCB_IMAGE=$jcb" --build-arg "COMPOSER_IMAGE=$composer" --tag "$development" "$root/images/development"
php "$root/tests/render-compose.php" "$work" "$jcb" "$database" "$development" bootstrap > "$work/compose.json"
"${compose[@]}" config --quiet
"${compose[@]}" up -d --wait --wait-timeout 300 database
"${compose[@]}" up -d --no-deps installer
ready=false
for ((i=0; i<180; i++)); do
    if "${compose[@]}" exec -T --user 33:33 installer php /opt/wp/probe.php >/dev/null 2>&1 \
        && "${compose[@]}" exec -T --user 33:33 installer php -r 'exit(@fsockopen("127.0.0.1",80)?0:1);' >/dev/null 2>&1; then
        ready=true; break
    fi
    sleep 2
done
[[ $ready == true ]] || { echo 'Initial JCB readiness failed; private installer output was not printed.' >&2; exit 1; }
"${compose[@]}" rm --stop --force installer
php "$root/tests/render-compose.php" "$work" "$jcb" "$database" "$development" normal > "$work/compose.next.json"
mv "$work/compose.next.json" "$work/compose.json"
rm -f "$work/control/secrets/"{admin_password,admin_username}
"${compose[@]}" up -d --wait --wait-timeout 300 database web development
"${compose[@]}" exec -T --user 33:33 web php /opt/wp/probe.php > "$work/web.json"
"${compose[@]}" exec -T --user 33:33 development php /opt/wp/probe.php > "$work/dev.json"
cmp "$work/web.json" "$work/dev.json"
printf '[127.0.0.1]:12222 %s\n' "$(cat "$work/control/host-keys/ssh_host_ed25519_key.pub")" > "$work/known_hosts"
ssh_opts=(-F /dev/null -i "$work/client" -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o "UserKnownHostsFile=$work/known_hosts" -o ConnectTimeout=10)
[[ $(ssh "${ssh_opts[@]}" -p 12222 developer@127.0.0.1 'id -u') == 33 ]]
ssh "${ssh_opts[@]}" -p 12222 developer@127.0.0.1 "printf shared-files > /var/www/html/wp-shared.txt; test ! -S /var/run/docker.sock"
[[ $(curl --fail --silent --max-time 15 http://127.0.0.1:18080/wp-shared.txt) == shared-files ]]
printf sftp-check > "$work/upload.txt"
printf 'put %s /var/www/html/wp-sftp.txt\nget /var/www/html/wp-sftp.txt %s\n' "$work/upload.txt" "$work/download.txt" > "$work/sftp.batch"
sftp "${ssh_opts[@]}" -P 12222 -b "$work/sftp.batch" developer@127.0.0.1
cmp "$work/upload.txt" "$work/download.txt"
if ssh "${ssh_opts[@]}" -p 12222 root@127.0.0.1 true 2>/dev/null; then echo 'Root SSH unexpectedly accepted.' >&2; exit 1; fi
"${compose[@]}" restart web development
sleep 3
[[ $(curl --fail --silent --max-time 15 http://127.0.0.1:18080/wp-shared.txt) == shared-files ]]
[[ $(ssh "${ssh_opts[@]}" -p 12222 developer@127.0.0.1 'id -u') == 33 ]]
"${compose[@]}" exec -T --user 33:33 web php /opt/wp/probe.php >/dev/null
container=$("${compose[@]}" ps -q development)
docker inspect "$container" | jq -e '.[0] | (.HostConfig.Privileged == false) and (.HostConfig.ReadonlyRootfs == true) and (.HostConfig.CapDrop | index("ALL") != null) and ([.Mounts[].Source | contains("docker.sock")] | any | not)' >/dev/null
printf 'PASS container installation, runtime parity, SSH/SFTP, shared files, restart persistence and privilege policy\n'
printf 'Tested JCB: %s\nTested database: %s\n' "$jcb" "$database"
