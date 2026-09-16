#!/bin/bash
set -euo pipefail
# Initial installation only: never used as the normal web entrypoint.
JOOMLA_ADMIN_USERNAME=$(cat /run/secrets/admin-username)
JOOMLA_ADMIN_PASSWORD=$(cat /run/secrets/admin-password)
export JOOMLA_ADMIN_USERNAME JOOMLA_ADMIN_PASSWORD
# Upstream installs synchronously before starting Apache. Customer ingress remains closed.
exec /entrypoint.sh apache2-foreground
