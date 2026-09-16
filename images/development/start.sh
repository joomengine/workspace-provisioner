#!/bin/bash
set -euo pipefail
[[ "$(id -u developer)" == "${WP_EXPECT_UID:-33}" ]]
[[ "$(id -g developer)" == "${WP_EXPECT_GID:-33}" ]]
[[ -f /etc/ssh/host-keys/ssh_host_ed25519_key ]]
[[ -s /etc/ssh/authorized-keys/developer ]]
mkdir -p /run/sshd
chmod 0755 /run/sshd
/usr/sbin/sshd -t
exec /usr/sbin/sshd -D -e
