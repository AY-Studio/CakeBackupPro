#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
exec "$APP_ROOT/plugins/CakeBackupPro/scripts/backup-manager.sh" backup full --prune
