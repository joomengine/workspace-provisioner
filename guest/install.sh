#!/bin/bash
set -euo pipefail
# Initial installation only: never used as the normal web entrypoint.
export JOOMLA_ADMIN_USERNAME="$(cat /run/secrets/admin-username)"
export JOOMLA_ADMIN_PASSWORD="$(cat /run/secrets/admin-password)"
# Upstream installs synchronously before starting Apache. Customer ingress remains closed.
exec /usr/local/bin/docker-entrypoint.sh apache2-foreground
