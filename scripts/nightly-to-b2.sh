#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
ENV_FILE="${BACKUP_ENV_FILE:-$APP_ROOT/.ddev/.env.backup}"
LOG_DIR="$APP_ROOT/logs"
STAGING_DIR="$APP_ROOT/tmp/backups"
TIMESTAMP="$(date +"%Y%m%d-%H%M%S")"
DB_DUMP="$STAGING_DIR/db-${TIMESTAMP}.sql.gz"
HOSTNAME_TAG="$(hostname -s 2>/dev/null || hostname)"

mkdir -p "$LOG_DIR" "$STAGING_DIR"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "Backup env file not found: $ENV_FILE"
  echo "Copy plugins/CakeBackupPro/scripts/env.backup.example to .ddev/.env.backup and fill values."
  exit 1
fi

set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

required_vars=(
  RESTIC_REPOSITORY
  RESTIC_PASSWORD
  AWS_ACCESS_KEY_ID
  AWS_SECRET_ACCESS_KEY
)
for var in "${required_vars[@]}"; do
  if [[ -z "${!var:-}" ]]; then
    echo "Missing required env var: $var"
    exit 1
  fi
done

if ! command -v ddev >/dev/null 2>&1; then
  echo "ddev is not installed or not in PATH"
  exit 1
fi

if ! command -v restic >/dev/null 2>&1; then
  echo "restic is not installed or not in PATH"
  exit 1
fi

RESTIC_ARGS=()
if [[ -n "${RESTIC_S3_ENDPOINT:-}" ]]; then
  RESTIC_ARGS+=("-o" "s3.endpoint=${RESTIC_S3_ENDPOINT}")
fi

cd "$APP_ROOT"

echo "[$(date -Iseconds)] Starting backup"

ddev start -y >/dev/null
ddev export-db --file "$DB_DUMP"

if ! restic "${RESTIC_ARGS[@]}" snapshots >/dev/null 2>&1; then
  restic "${RESTIC_ARGS[@]}" init
fi

BACKUP_PATHS=(
  "$APP_ROOT/config"
  "$APP_ROOT/src"
  "$APP_ROOT/templates"
  "$APP_ROOT/www"
  "$APP_ROOT/uploads"
  "$APP_ROOT/.ddev/config.yaml"
  "$APP_ROOT/composer.json"
  "$APP_ROOT/composer.lock"
  "$DB_DUMP"
)

if [[ -f "$APP_ROOT/config/.env" ]]; then
  BACKUP_PATHS+=("$APP_ROOT/config/.env")
fi

restic "${RESTIC_ARGS[@]}" backup \
  "${BACKUP_PATHS[@]}" \
  --tag "cake-backup-pro" \
  --tag "nightly" \
  --host "$HOSTNAME_TAG"

restic "${RESTIC_ARGS[@]}" forget --keep-within 45d --prune

rm -f "$DB_DUMP"

echo "[$(date -Iseconds)] Backup complete"
