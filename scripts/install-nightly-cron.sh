#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
PLUGIN_ROOT="$APP_ROOT/plugins/CakeBackupPro"
LOG_FILE="$APP_ROOT/logs/nightly-backup.log"
SCRIPT_PATH="$PLUGIN_ROOT/scripts/nightly-to-b2.sh"
CRON_LINE="0 0 * * * /bin/bash -lc '$SCRIPT_PATH >> \"$LOG_FILE\" 2>&1'"

mkdir -p "$APP_ROOT/logs"

existing="$(crontab -l 2>/dev/null || true)"
cleaned="$(printf '%s\n' "$existing" | grep -v "$SCRIPT_PATH" || true)"

{
  printf '%s\n' "$cleaned"
  printf '%s\n' "$CRON_LINE"
} | crontab -

echo "Installed nightly cron job:"
echo "$CRON_LINE"
