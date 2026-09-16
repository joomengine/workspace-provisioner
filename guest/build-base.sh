#!/bin/bash
set -euo pipefail
umask 077
[[ $(id -u) == 0 ]]
# This helper runs ONLY inside the disposable builder VM, never on the compute host.
# shellcheck source=/dev/null
source /etc/os-release
[[ ${ID:-} == ubuntu && ${VERSION_ID:-} == 24.04 ]]
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y --no-install-recommends docker.io docker-compose-v2 php-cli php-mysql php-pgsql \
    php-mbstring php-xml php-curl ca-certificates curl jq openssh-client util-linux
php -r 'exit(PHP_VERSION_ID >= 80300 && extension_loaded("posix") && extension_loaded("sodium") ? 0 : 1);'
docker compose version
systemctl enable docker.service systemd-networkd.service
systemctl disable --now ssh.service ssh.socket 2>/dev/null || true
systemctl mask ssh.service ssh.socket
[[ $(systemctl is-enabled ssh.service || true) == masked ]]
install -d -m 0755 /etc/docker /etc/systemd/network /opt/jcb-workspace
printf '%s\n' '{"log-driver":"local","log-opts":{"max-size":"10m","max-file":"3"},"live-restore":false}' > /etc/docker/daemon.json
chmod 0644 /etc/docker/daemon.json
install -d -m 0755 /usr/share/jcb-workspace
dpkg-query -W -f='${Package}\t${Version}\n' > /usr/share/jcb-workspace/packages.tsv
# No workspace, credentials, keys or mutable application state are baked into the template.
rm -f /etc/ssh/ssh_host_* /root/.ssh/authorized_keys /root/.bash_history /root/wp-build-base.sh
find /home -maxdepth 3 -type f -name authorized_keys -delete
if [[ -d /etc/cloud ]]; then touch /etc/cloud/cloud-init.disabled; fi
systemctl stop systemd-networkd.service
rm -f /etc/systemd/network/*.network /etc/netplan/*.yaml /var/lib/systemd/random-seed
find /var/log -type f -exec truncate --size=0 {} +
rm -rf /var/lib/dhcp/* /var/lib/cloud/instances/*
: > /etc/machine-id
rm -f /var/lib/dbus/machine-id
ln -s /etc/machine-id /var/lib/dbus/machine-id
sync
