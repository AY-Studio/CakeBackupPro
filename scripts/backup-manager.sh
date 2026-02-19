#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
PLUGIN_ROOT="$APP_ROOT/plugins/CakeBackupPro"
ENV_FILE="${BACKUP_ENV_FILE:-$APP_ROOT/.env.backup}"
LOG_DIR="${BACKUP_LOG_DIR:-$APP_ROOT/logs}"
STAGING_DIR="${BACKUP_STAGING_DIR:-$APP_ROOT/tmp/backups}"
LOCK_FILE="$STAGING_DIR/.backup-manager.lock"
DB_FILE="$STAGING_DIR/db-latest.sql.gz"
PROJECT_TAG="${BACKUP_PROJECT_TAG:-cake-backup-pro}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-45}"
BACKUP_DRIVER="${BACKUP_DRIVER:-native}" # native|restic
BACKUP_FILENAME_DATE_FORMAT="${BACKUP_FILENAME_DATE_FORMAT:-%Y%m%d-%H%M%S}"
BACKUP_FILE_COMPONENTS_DEFAULT="config,src,templates,webroot,resources,plugins,uploads,www,db_files,root_files,env_files"
BACKUP_FILE_COMPONENTS="${BACKUP_FILE_COMPONENTS:-$BACKUP_FILE_COMPONENTS_DEFAULT}"
BACKUP_SITE_URL="${BACKUP_SITE_URL:-${APP_FULL_BASE_URL:-${ACCOUNT_DOMAIN:-}}}"
RESTORE_TARGET_URL="${RESTORE_TARGET_URL:-${APP_FULL_BASE_URL:-${ACCOUNT_DOMAIN:-}}}"
RESTORE_SOURCE_URL="${RESTORE_SOURCE_URL:-}"
RESTORE_REWRITE_URLS="${RESTORE_REWRITE_URLS:-1}"
RESTORE_RUN_COMPOSER="${RESTORE_RUN_COMPOSER:-0}"
RESTORE_RUN_MIGRATIONS="${RESTORE_RUN_MIGRATIONS:-0}"
RESTORE_CLEAR_CACHE="${RESTORE_CLEAR_CACHE:-1}"
RESTORE_INCLUDE_ENV_FILES="${RESTORE_INCLUDE_ENV_FILES:-0}"
RESTORE_RUN_HEALTH_CHECK="${RESTORE_RUN_HEALTH_CHECK:-1}"

B2_API_URL=""
B2_DOWNLOAD_URL=""
B2_AUTH_TOKEN=""
B2_ACCOUNT_ID=""
B2_BUCKET=""
B2_PREFIX=""
B2_BUCKET_ID=""
EFFECTIVE_DB_HOST=""
EFFECTIVE_DB_PORT=""
EFFECTIVE_DB_NAME=""
EFFECTIVE_DB_USER=""
EFFECTIVE_DB_PASSWORD=""

usage() {
  cat <<USAGE
Usage:
  $0 backup <full|db|files> [--prune] [--components=a,b,c]
    (writes metadata with source URL when BACKUP_SITE_URL is set)
  $0 list
  $0 list-sets
  $0 restore-set <set-id|latest> <components-csv>
    (can auto-rewrite DB URLs from source metadata to RESTORE_TARGET_URL)
  $0 restore-full [set-id|latest]
    (restores full app stack with post-restore finalize steps)
  $0 restore-db <remote-file-name>
  $0 restore-files <remote-file-name> <target-dir>
  $0 restore-db-file <local-file-path>
  $0 restore-files-file <local-file-path> <target-dir>
  $0 schedule-install [cron-expression]
  $0 schedule-show
  $0 schedule-remove
USAGE
}

load_env() {
  if [[ -f "$ENV_FILE" ]]; then
    set -a
    # shellcheck disable=SC1090
    source "$ENV_FILE"
    set +a
  fi
}

require_cmd() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "Required command not found: $1"
    exit 1
  fi
}

require_common_env() {
  local required=(AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY)
  if [[ "$BACKUP_DRIVER" == "restic" ]]; then
    required+=(RESTIC_REPOSITORY RESTIC_PASSWORD)
  fi
  for var in "${required[@]}"; do
    if [[ -z "${!var:-}" ]]; then
      echo "Missing required env var: $var"
      exit 1
    fi
  done

  if [[ "$BACKUP_DRIVER" == "native" ]] && [[ -z "${BACKUP_PATH:-}" ]]; then
    echo "Missing required env var: BACKUP_PATH (bucket/prefix)"
    exit 1
  fi
}

require_db_env() {
  local required=(DB_HOST DB_NAME DB_USER)
  for var in "${required[@]}"; do
    if [[ -z "${!var:-}" ]]; then
      echo "Missing required DB env var: $var"
      exit 1
    fi
  done
}

trim_csv_item() {
  echo "$1" | xargs
}

normalize_url() {
  local url="$1"
  url="$(echo "$url" | xargs)"
  url="${url%/}"
  echo "$url"
}

print_unique_candidates() {
  local emitted=()
  local value e skip
  for value in "$@"; do
    skip=0
    for e in "${emitted[@]}"; do
      if [[ "$e" == "$value" ]]; then
        skip=1
        break
      fi
    done
    if [[ "$skip" -eq 0 && -n "$value" ]]; then
      emitted+=("$value")
      printf '%s\n' "$value"
    fi
  done
}

db_host_candidates() {
  local candidates=("${DB_HOST:-}")
  if [[ -n "${DB_HOST_FALLBACKS:-}" ]]; then
    local IFS=','
    read -r -a extra <<< "${DB_HOST_FALLBACKS}"
    local x
    for x in "${extra[@]}"; do
      candidates+=("$(trim_csv_item "$x")")
    done
  fi
  if [[ "${DB_HOST:-}" == "db" || -n "${DDEV_SITENAME:-}" ]]; then
    candidates+=("db" "127.0.0.1" "localhost")
  fi
  print_unique_candidates "${candidates[@]}"
}

db_port_candidates() {
  local candidates=("${DB_PORT:-3306}" "3306")
  if [[ -n "${DB_PORT_FALLBACKS:-}" ]]; then
    local IFS=','
    read -r -a extra <<< "${DB_PORT_FALLBACKS}"
    local x
    for x in "${extra[@]}"; do
      candidates+=("$(trim_csv_item "$x")")
    done
  fi
  print_unique_candidates "${candidates[@]}"
}

db_user_candidates() {
  local candidates=("${DB_USER:-}")
  if [[ -n "${DB_USER_FALLBACKS:-}" ]]; then
    local IFS=','
    read -r -a extra <<< "${DB_USER_FALLBACKS}"
    local x
    for x in "${extra[@]}"; do
      candidates+=("$(trim_csv_item "$x")")
    done
  fi
  if [[ "${DB_HOST:-}" == "db" || -n "${DDEV_SITENAME:-}" ]]; then
    candidates+=("db" "root")
  fi
  print_unique_candidates "${candidates[@]}"
}

db_name_candidates() {
  local candidates=("${DB_NAME:-}")
  if [[ -n "${DB_NAME_FALLBACKS:-}" ]]; then
    local IFS=','
    read -r -a extra <<< "${DB_NAME_FALLBACKS}"
    local x
    for x in "${extra[@]}"; do
      candidates+=("$(trim_csv_item "$x")")
    done
  fi
  if [[ "${DB_HOST:-}" == "db" || -n "${DDEV_SITENAME:-}" ]]; then
    candidates+=("db")
  fi
  print_unique_candidates "${candidates[@]}"
}

db_password_candidates_for_user() {
  local user="$1"
  local candidates=("${DB_PASSWORD:-}")

  if [[ "$user" == "db" ]]; then
    candidates+=("db")
  fi
  if [[ "$user" == "root" ]]; then
    candidates+=("root")
  fi

  if [[ -n "${DB_PASSWORD_FALLBACKS:-}" ]]; then
    local IFS=','
    read -r -a extra <<< "${DB_PASSWORD_FALLBACKS}"
    local x
    for x in "${extra[@]}"; do
      candidates+=("$(trim_csv_item "$x")")
    done
  fi

  candidates+=("")
  print_unique_candidates "${candidates[@]}"
}

resolve_working_db_credentials() {
  require_cmd mysql

  if [[ -n "$EFFECTIVE_DB_HOST" ]]; then
    return 0
  fi

  local host port user db pass
  while IFS= read -r host; do
    while IFS= read -r port; do
      while IFS= read -r user; do
        while IFS= read -r db; do
          while IFS= read -r pass; do
            if MYSQL_PWD="$pass" mysql \
              --connect-timeout=3 \
              --host="$host" \
              --port="$port" \
              --user="$user" \
              "$db" \
              -e "SELECT 1" >/dev/null 2>&1; then
              EFFECTIVE_DB_HOST="$host"
              EFFECTIVE_DB_PORT="$port"
              EFFECTIVE_DB_USER="$user"
              EFFECTIVE_DB_NAME="$db"
              EFFECTIVE_DB_PASSWORD="$pass"
              return 0
            fi
          done < <(db_password_candidates_for_user "$user")
        done < <(db_name_candidates)
      done < <(db_user_candidates)
    done < <(db_port_candidates)
  done < <(db_host_candidates)

  return 1
}

mysqldump_with_fallbacks() {
  local db_extra_opts="$1"
  local out_file="$2"

  if ! resolve_working_db_credentials; then
    return 1
  fi

  # shellcheck disable=SC2086
  MYSQL_PWD="$EFFECTIVE_DB_PASSWORD" mysqldump \
    --single-transaction \
    --quick \
    --lock-tables=false \
    --host="$EFFECTIVE_DB_HOST" \
    --port="$EFFECTIVE_DB_PORT" \
    --user="$EFFECTIVE_DB_USER" \
    $db_extra_opts \
    "$EFFECTIVE_DB_NAME" | gzip > "$out_file"
}

mysql_restore_stream_with_fallbacks() {
  local sql_source_cmd="$1"

  if ! resolve_working_db_credentials; then
    return 1
  fi

  eval "MYSQL_PWD=\"\$EFFECTIVE_DB_PASSWORD\" $sql_source_cmd | mysql --host=\"$EFFECTIVE_DB_HOST\" --port=\"$EFFECTIVE_DB_PORT\" --user=\"$EFFECTIVE_DB_USER\" \"$EFFECTIVE_DB_NAME\""
}

mysql_restore_file_with_fallbacks() {
  local local_file="$1"

  if ! resolve_working_db_credentials; then
    return 1
  fi

  MYSQL_PWD="$EFFECTIVE_DB_PASSWORD" mysql \
    --host="$EFFECTIVE_DB_HOST" \
    --port="$EFFECTIVE_DB_PORT" \
    --user="$EFFECTIVE_DB_USER" \
    "$EFFECTIVE_DB_NAME" < "$local_file"
}

php_json_get() {
  local json="$1"
  local key="$2"
  php -r '$j=json_decode($argv[1], true); $k=$argv[2]; echo isset($j[$k]) ? $j[$k] : "";' "$json" "$key"
}

php_parse_backup_path() {
  php -r '$p=trim($argv[1],"/"); $parts=explode("/",$p,2); echo ($parts[0]??"") . "\n" . ($parts[1]??"");' "$1"
}

urlencode_file_name_header() {
  php -r 'echo str_replace("%2F","/", rawurlencode($argv[1]));' "$1"
}

setup_b2_context() {
  local parsed bucket prefix
  parsed="$(php_parse_backup_path "$BACKUP_PATH")"
  bucket="$(printf '%s\n' "$parsed" | sed -n '1p')"
  prefix="$(printf '%s\n' "$parsed" | sed -n '2p')"

  if [[ -z "$bucket" ]]; then
    echo "Invalid BACKUP_PATH, expected bucket/prefix"
    exit 1
  fi

  B2_BUCKET="$bucket"
  B2_PREFIX="$prefix"

  local auth_json
  auth_json="$(curl -sS -u "$AWS_ACCESS_KEY_ID:$AWS_SECRET_ACCESS_KEY" https://api.backblazeb2.com/b2api/v2/b2_authorize_account)"

  B2_API_URL="$(php_json_get "$auth_json" apiUrl)"
  B2_DOWNLOAD_URL="$(php_json_get "$auth_json" downloadUrl)"
  B2_AUTH_TOKEN="$(php_json_get "$auth_json" authorizationToken)"
  B2_ACCOUNT_ID="$(php_json_get "$auth_json" accountId)"

  if [[ -z "$B2_API_URL" || -z "$B2_AUTH_TOKEN" || -z "$B2_ACCOUNT_ID" ]]; then
    echo "Failed to authorize with Backblaze B2"
    echo "$auth_json"
    exit 1
  fi

  local list_json
  list_json="$(curl -sS "$B2_API_URL/b2api/v2/b2_list_buckets" \
    -H "Authorization: $B2_AUTH_TOKEN" \
    -d "{\"accountId\":\"$B2_ACCOUNT_ID\",\"bucketName\":\"$B2_BUCKET\"}" )"

  B2_BUCKET_ID="$(php -r '$j=json_decode($argv[1], true); $id=""; if (!empty($j["buckets"][0]["bucketId"])) { $id=$j["buckets"][0]["bucketId"]; } echo $id;' "$list_json")"

  if [[ -z "$B2_BUCKET_ID" ]]; then
    echo "Failed to find bucket: $B2_BUCKET"
    echo "$list_json"
    exit 1
  fi
}

b2_upload_file() {
  local local_file="$1"
  local remote_name="$2"

  local upload_json upload_url upload_auth sha1 file_name_encoded
  upload_json="$(curl -sS "$B2_API_URL/b2api/v2/b2_get_upload_url" \
    -H "Authorization: $B2_AUTH_TOKEN" \
    -d "{\"bucketId\":\"$B2_BUCKET_ID\"}" )"

  upload_url="$(php_json_get "$upload_json" uploadUrl)"
  upload_auth="$(php_json_get "$upload_json" authorizationToken)"

  if [[ -z "$upload_url" || -z "$upload_auth" ]]; then
    echo "Failed to get upload URL"
    echo "$upload_json"
    exit 1
  fi

  sha1="$(php -r 'echo sha1_file($argv[1]);' "$local_file")"
  file_name_encoded="$(urlencode_file_name_header "$remote_name")"

  curl -sS "$upload_url" \
    -H "Authorization: $upload_auth" \
    -H "X-Bz-File-Name: $file_name_encoded" \
    -H "Content-Type: b2/x-auto" \
    -H "X-Bz-Content-Sha1: $sha1" \
    --data-binary "@$local_file" >/dev/null

  echo "Uploaded: $remote_name"
}

b2_list_files_json() {
  local start_file_name="${1:-}"
  local data

  if [[ -n "$start_file_name" ]]; then
    data="{\"bucketId\":\"$B2_BUCKET_ID\",\"maxFileCount\":1000,\"prefix\":\"$B2_PREFIX\",\"startFileName\":\"$start_file_name\"}"
  else
    data="{\"bucketId\":\"$B2_BUCKET_ID\",\"maxFileCount\":1000,\"prefix\":\"$B2_PREFIX\"}"
  fi

  curl -sS "$B2_API_URL/b2api/v2/b2_list_file_names" \
    -H "Authorization: $B2_AUTH_TOKEN" \
    -d "$data"
}

b2_list_files_human() {
  local all_json
  all_json="$(b2_list_files_json)"

  php -r '
$j=json_decode($argv[1], true);
if (empty($j["files"])) { echo "No backups found\n"; exit(0); }
foreach ($j["files"] as $f) {
  $ts = isset($f["uploadTimestamp"]) ? date("Y-m-d H:i:s", (int)($f["uploadTimestamp"]/1000)) : "";
  $sz = $f["size"] ?? 0;
  echo ($f["fileName"] ?? "") . "\t" . $ts . "\t" . $sz . "\n";
}
' "$all_json"
}

b2_delete_old_files() {
  local retention_days="$1"
  local list_json
  list_json="$(b2_list_files_json)"

  php -r '
$j=json_decode($argv[1], true);
$ret=(int)$argv[2];
$cutoff=((time()-($ret*86400))*1000);
if (empty($j["files"])) exit(0);
foreach ($j["files"] as $f) {
  $ts=(int)($f["uploadTimestamp"] ?? 0);
  if ($ts < $cutoff) {
    echo ($f["fileName"] ?? "") . "\t" . ($f["fileId"] ?? "") . "\n";
  }
}
' "$list_json" "$retention_days" | while IFS=$'\t' read -r file_name file_id; do
    [[ -z "$file_name" || -z "$file_id" ]] && continue
    curl -sS "$B2_API_URL/b2api/v2/b2_delete_file_version" \
      -H "Authorization: $B2_AUTH_TOKEN" \
      -d "{\"fileName\":\"$file_name\",\"fileId\":\"$file_id\"}" >/dev/null
    echo "Deleted old backup: $file_name"
  done
}

b2_download_file_by_name() {
  local remote_name="$1"
  local out_file="$2"
  local encoded_name
  encoded_name="$(urlencode_file_name_header "$remote_name")"

  curl -f -sS \
    -H "Authorization: $B2_AUTH_TOKEN" \
    "$B2_DOWNLOAD_URL/file/$B2_BUCKET/$encoded_name" \
    -o "$out_file"
}

create_backup_metadata_file() {
  local set_id="$1"
  local components_csv="$2"
  local include_db="$3"
  local out_file="$4"
  local source_url
  source_url="$(normalize_url "${BACKUP_SITE_URL:-}")"

  php -r '
$setId=$argv[1];
$componentsCsv=$argv[2];
$includeDb=$argv[3] === "1";
$sourceUrl=$argv[4];
$components=array_values(array_filter(array_map("trim", explode(",", $componentsCsv))));
$data=[
  "set_id" => $setId,
  "created_at" => date("c"),
  "source_url" => $sourceUrl,
  "include_db" => $includeDb,
  "components" => $components,
];
file_put_contents($argv[5], json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
' "$set_id" "$components_csv" "$include_db" "$source_url" "$out_file"
}

metadata_source_url_from_file() {
  local metadata_file="$1"
  if [[ ! -f "$metadata_file" ]]; then
    return 0
  fi

  php -r '
$f=$argv[1];
$j=json_decode((string)file_get_contents($f), true);
if (!is_array($j)) exit(0);
echo (string)($j["source_url"] ?? "");
' "$metadata_file"
}

apply_db_url_rewrite_if_needed() {
  local source_url_in="${1:-}"

  if [[ "${RESTORE_REWRITE_URLS:-1}" != "1" ]]; then
    return 0
  fi

  local source_url target_url
  source_url="$(normalize_url "${source_url_in:-$RESTORE_SOURCE_URL}")"
  target_url="$(normalize_url "${RESTORE_TARGET_URL:-}")"

  if [[ -z "$source_url" || -z "$target_url" || "$source_url" == "$target_url" ]]; then
    return 0
  fi

  if ! resolve_working_db_credentials; then
    echo "URL rewrite skipped: unable to resolve DB credentials."
    return 1
  fi

  echo "Applying DB URL rewrite: ${source_url} -> ${target_url}"
  php -r '
$host=$argv[1];
$port=(int)$argv[2];
$user=$argv[3];
$pass=$argv[4];
$db=$argv[5];
$from=$argv[6];
$to=$argv[7];
$m=@new mysqli($host,$user,$pass,$db,$port);
if ($m->connect_errno) { fwrite(STDERR, "URL rewrite DB connect failed: ".$m->connect_error.PHP_EOL); exit(1); }
$m->set_charset("utf8mb4");
$fromEsc=$m->real_escape_string($from);
$toEsc=$m->real_escape_string($to);
$fromLikeEsc=$m->real_escape_string($from);
$q="SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND DATA_TYPE IN (\"char\",\"varchar\",\"tinytext\",\"text\",\"mediumtext\",\"longtext\")";
$res=$m->query($q);
if (!$res) { fwrite(STDERR, "URL rewrite metadata query failed".PHP_EOL); exit(1); }
$total=0;
while ($row=$res->fetch_assoc()) {
  $t=str_replace(\"`\",\"``\",$row[\"TABLE_NAME\"]);
  $c=str_replace(\"`\",\"``\",$row[\"COLUMN_NAME\"]);
  $u=\"UPDATE `{$t}` SET `{$c}`=REPLACE(`{$c}`,'{$fromEsc}','{$toEsc}') WHERE `{$c}` LIKE '%{$fromLikeEsc}%'\";
  if ($m->query($u)) {
    $total += (int)$m->affected_rows;
  }
}
echo \"URL rewrite complete. Rows changed: {$total}\".PHP_EOL;
' "$EFFECTIVE_DB_HOST" "$EFFECTIVE_DB_PORT" "$EFFECTIVE_DB_USER" "$EFFECTIVE_DB_PASSWORD" "$EFFECTIVE_DB_NAME" "$source_url" "$target_url"
}

post_restore_finalize() {
  if [[ "${RESTORE_CLEAR_CACHE:-1}" == "1" ]]; then
    rm -rf "$APP_ROOT/tmp/cache/"* 2>/dev/null || true
  fi

  if [[ "${RESTORE_RUN_COMPOSER:-0}" == "1" && -f "$APP_ROOT/composer.json" ]]; then
    if command -v composer >/dev/null 2>&1; then
      (cd "$APP_ROOT" && composer install --no-interaction) || true
    fi
  fi

  if [[ "${RESTORE_RUN_MIGRATIONS:-0}" == "1" && -f "$APP_ROOT/bin/cake" ]]; then
    (cd "$APP_ROOT" && php "$APP_ROOT/bin/cake" migrations migrate) || true
  fi
}

restore_health_check() {
  if [[ "${RESTORE_RUN_HEALTH_CHECK:-1}" != "1" ]]; then
    return 0
  fi

  local failures=0

  echo "Restore health check: start"

  if [[ -f "$APP_ROOT/bin/cake" && -f "$APP_ROOT/vendor/autoload.php" ]]; then
    if (cd "$APP_ROOT" && php "$APP_ROOT/bin/cake" --version >/dev/null 2>&1); then
      echo "[PASS] Cake CLI bootstrap"
    else
      echo "[FAIL] Cake CLI bootstrap"
      failures=$((failures + 1))
    fi
  else
    echo "[FAIL] Cake CLI/bootstrap files missing (bin/cake or vendor/autoload.php)"
    failures=$((failures + 1))
  fi

  if resolve_working_db_credentials; then
    echo "[PASS] Database connectivity (${EFFECTIVE_DB_USER}@${EFFECTIVE_DB_HOST}:${EFFECTIVE_DB_PORT}/${EFFECTIVE_DB_NAME})"
  else
    echo "[FAIL] Database connectivity"
    failures=$((failures + 1))
  fi

  local req_paths=(config src webroot plugins tmp logs)
  local p
  for p in "${req_paths[@]}"; do
    if [[ -e "$APP_ROOT/$p" ]]; then
      echo "[PASS] Path exists: $p"
    else
      echo "[FAIL] Path missing: $p"
      failures=$((failures + 1))
    fi
  done

  local writable_dirs=(tmp logs)
  for p in "${writable_dirs[@]}"; do
    if [[ -d "$APP_ROOT/$p" && -w "$APP_ROOT/$p" ]]; then
      echo "[PASS] Writable directory: $p"
    else
      echo "[FAIL] Writable directory: $p"
      failures=$((failures + 1))
    fi
  done

  if [[ "$failures" -eq 0 ]]; then
    echo "Restore health check: PASS"
    return 0
  fi

  echo "Restore health check: FAIL (${failures} issue(s))"
  return 1
}

is_valid_file_component() {
  case "$1" in
    config|src|templates|webroot|resources|plugins|uploads|www|db_files|root_files|env_files) return 0 ;;
    *) return 1 ;;
  esac
}

normalize_file_components_csv() {
  local input="${1:-$BACKUP_FILE_COMPONENTS}"
  local output=()
  local IFS=','
  read -r -a raw <<< "$input"

  for token in "${raw[@]}"; do
    token="$(echo "$token" | tr '[:upper:]' '[:lower:]' | xargs)"
    [[ -z "$token" ]] && continue
    if is_valid_file_component "$token"; then
      output+=("$token")
    fi
  done

  if [[ ${#output[@]} -eq 0 ]]; then
    echo "$BACKUP_FILE_COMPONENTS_DEFAULT"
    return
  fi

  (IFS=','; echo "${output[*]}")
}

build_component_paths() {
  local component="$1"
  local -n out=$2
  out=()

  case "$component" in
    config) [[ -e "$APP_ROOT/config" ]] && out+=("config") ;;
    src) [[ -e "$APP_ROOT/src" ]] && out+=("src") ;;
    templates) [[ -e "$APP_ROOT/templates" ]] && out+=("templates") ;;
    webroot) [[ -e "$APP_ROOT/webroot" ]] && out+=("webroot") ;;
    resources) [[ -e "$APP_ROOT/resources" ]] && out+=("resources") ;;
    plugins) [[ -e "$APP_ROOT/plugins" ]] && out+=("plugins") ;;
    uploads) [[ -e "$APP_ROOT/uploads" ]] && out+=("uploads") ;;
    www) [[ -e "$APP_ROOT/www" ]] && out+=("www") ;;
    db_files) [[ -e "$APP_ROOT/db" ]] && out+=("db") ;;
    root_files)
      [[ -e "$APP_ROOT/bin" ]] && out+=("bin")
      [[ -f "$APP_ROOT/index.php" ]] && out+=("index.php")
      [[ -f "$APP_ROOT/composer.json" ]] && out+=("composer.json")
      [[ -f "$APP_ROOT/composer.lock" ]] && out+=("composer.lock")
      [[ -f "$APP_ROOT/phinx.php" ]] && out+=("phinx.php")
      ;;
    env_files)
      [[ -f "$APP_ROOT/config/.env" ]] && out+=("config/.env")
      [[ -f "$APP_ROOT/.env" ]] && out+=(".env")
      ;;
    *) ;;
  esac
}

create_component_archive() {
  local component="$1"
  local out_file="$2"
  local paths=()

  build_component_paths "$component" paths
  if [[ ${#paths[@]} -eq 0 ]]; then
    echo "Skipping component '$component' (no paths found)"
    return 1
  fi

  mkdir -p "$STAGING_DIR"
  cd "$APP_ROOT"
  tar -czf "$out_file" "${paths[@]}"
}

create_db_dump() {
  require_cmd mysqldump
  require_db_env

  local db_extra_opts="${DB_EXTRA_OPTS:-}"

  mkdir -p "$STAGING_DIR"

  if ! resolve_working_db_credentials; then
    echo "Could not resolve working DB credentials from provided settings."
    exit 1
  fi

  echo "DB dump target: host=$EFFECTIVE_DB_HOST port=$EFFECTIVE_DB_PORT user=$EFFECTIVE_DB_USER db=$EFFECTIVE_DB_NAME pass_len=${#EFFECTIVE_DB_PASSWORD}"

  # shellcheck disable=SC2086
  if ! mysqldump_with_fallbacks "$db_extra_opts" "$DB_FILE"; then
    echo "mysqldump failed with configured credentials."
    echo "Check DB_* settings (or set DB_*_FALLBACKS)."
    exit 1
  fi
}

backup_native() {
  local mode="$1"
  local prune="$2"
  local components_csv="$3"
  local ts db_remote metadata_remote metadata_file normalized include_db

  ts="$(date +"$BACKUP_FILENAME_DATE_FORMAT")"
  setup_b2_context

  if [[ -n "$B2_PREFIX" ]]; then
    db_remote="$B2_PREFIX/${ts}/${ts}-db.sql.gz"
  else
    db_remote="${ts}/${ts}-db.sql.gz"
  fi

  include_db="0"
  if [[ "$mode" == "db" || "$mode" == "full" ]]; then
    include_db="1"
    create_db_dump
    b2_upload_file "$DB_FILE" "$db_remote"
    rm -f "$DB_FILE" || true
  fi

  normalized="$(normalize_file_components_csv "$components_csv")"
  if [[ "$mode" == "files" || "$mode" == "full" ]]; then
    local component archive remote_name

    IFS=',' read -r -a comps <<< "$normalized"
    for component in "${comps[@]}"; do
      archive="$STAGING_DIR/${component}-${ts}.tar.gz"
      if ! create_component_archive "$component" "$archive"; then
        continue
      fi

      if [[ -n "$B2_PREFIX" ]]; then
        remote_name="$B2_PREFIX/${ts}/${ts}-${component}.tar.gz"
      else
        remote_name="${ts}/${ts}-${component}.tar.gz"
      fi

      b2_upload_file "$archive" "$remote_name"
      rm -f "$archive" || true
    done
  fi

  metadata_file="$STAGING_DIR/meta-${ts}.json"
  create_backup_metadata_file "$ts" "$normalized" "$include_db" "$metadata_file"
  if [[ -n "$B2_PREFIX" ]]; then
    metadata_remote="$B2_PREFIX/${ts}/${ts}-meta.json"
  else
    metadata_remote="${ts}/${ts}-meta.json"
  fi
  b2_upload_file "$metadata_file" "$metadata_remote"
  rm -f "$metadata_file" || true

  if [[ "$prune" == "1" ]]; then
    b2_delete_old_files "$RETENTION_DAYS"
  fi
}

backup_restic() {
  require_cmd restic

  local mode="$1"
  local prune="$2"
  local components_csv="$3"
  local restic_args=()

  if [[ -n "${RESTIC_S3_ENDPOINT:-}" ]]; then
    restic_args+=("-o" "s3.endpoint=${RESTIC_S3_ENDPOINT}")
  fi

  if ! restic "${restic_args[@]}" snapshots >/dev/null 2>&1; then
    restic "${restic_args[@]}" init
  fi

  local paths=()
  case "$mode" in
    full)
      create_db_dump
      paths+=("tmp/backups/db-latest.sql.gz")
      local normalized c p=()
      normalized="$(normalize_file_components_csv "$components_csv")"
      IFS=',' read -r -a comps <<< "$normalized"
      for c in "${comps[@]}"; do
        build_component_paths "$c" p
        paths+=("${p[@]}")
      done
      ;;
    db)
      create_db_dump
      paths=("tmp/backups/db-latest.sql.gz")
      ;;
    files)
      local normalized c p=()
      normalized="$(normalize_file_components_csv "$components_csv")"
      IFS=',' read -r -a comps <<< "$normalized"
      for c in "${comps[@]}"; do
        build_component_paths "$c" p
        paths+=("${p[@]}")
      done
      ;;
    *)
      echo "Invalid mode: $mode"
      usage
      exit 1
      ;;
  esac

  cd "$APP_ROOT"
  restic "${restic_args[@]}" backup "${paths[@]}" --tag "$PROJECT_TAG" --tag "mode:$mode"

  if [[ "$prune" == "1" ]]; then
    restic "${restic_args[@]}" forget --keep-within "${RETENTION_DAYS}d" --prune
  fi
}

backup_mode() {
  local mode="$1"
  local prune="${2:-0}"
  local components_csv="${3:-$BACKUP_FILE_COMPONENTS}"

  mkdir -p "$LOG_DIR" "$STAGING_DIR"
  require_cmd curl
  require_cmd php
  require_common_env

  if [[ -f "$LOCK_FILE" ]]; then
    local existing_pid
    existing_pid="$(cat "$LOCK_FILE" 2>/dev/null || true)"
    if [[ -n "$existing_pid" ]] && ps -p "$existing_pid" >/dev/null 2>&1; then
      echo "Another backup is already running (PID: $existing_pid)"
      exit 1
    fi
    rm -f "$LOCK_FILE" || true
  fi

  echo "$$" > "$LOCK_FILE"
  trap 'rm -f "$LOCK_FILE" || true' EXIT INT TERM

  echo "[$(date -Iseconds)] Backup start: mode=$mode driver=$BACKUP_DRIVER components=$components_csv"

  if [[ "$BACKUP_DRIVER" == "native" ]]; then
    backup_native "$mode" "$prune" "$components_csv"
  else
    backup_restic "$mode" "$prune" "$components_csv"
  fi

  rm -f "$DB_FILE" || true
  echo "[$(date -Iseconds)] Backup complete"
}

list_snapshots() {
  require_cmd curl
  require_cmd php
  require_common_env

  if [[ "$BACKUP_DRIVER" == "native" ]]; then
    setup_b2_context
    b2_list_files_human
    return
  fi

  local restic_args=()
  if [[ -n "${RESTIC_S3_ENDPOINT:-}" ]]; then
    restic_args+=("-o" "s3.endpoint=${RESTIC_S3_ENDPOINT}")
  fi
  restic "${restic_args[@]}" snapshots
}

list_sets() {
  require_cmd curl
  require_cmd php
  require_common_env

  if [[ "$BACKUP_DRIVER" != "native" ]]; then
    echo "Set listing is supported in native mode only"
    exit 1
  fi

  setup_b2_context
  local list_json
  list_json="$(b2_list_files_json)"

  php -r '
$j=json_decode($argv[1], true);
if (empty($j["files"])) exit(0);
$sets=[];
foreach ($j["files"] as $f) {
  $name=(string)($f["fileName"] ?? "");
  if (preg_match("#(?:.*/)?(\d{8}(?:-\d{6})?)-db\.sql\.gz$#", $name, $m)) {
    $id=$m[1];
    if (!isset($sets[$id])) { $sets[$id]=["db"=>0,"components"=>[]]; }
    $sets[$id]["db"]=1;
    continue;
  }
  if (preg_match("#(?:.*/)?(\d{8}(?:-\d{6})?)-([a-z0-9_]+)\.tar\.gz$#", $name, $m)) {
    $id=$m[1];
    $comp=$m[2];
    if (!isset($sets[$id])) { $sets[$id]=["db"=>0,"components"=>[]]; }
    $sets[$id]["components"][$comp]=1;
  }
}
krsort($sets, SORT_STRING);
foreach ($sets as $id => $meta) {
  $components=array_keys($meta["components"]);
  sort($components, SORT_STRING);
  echo $id . "\t" . implode(",", $components) . "\t" . (string)$meta["db"] . "\n";
}
' "$list_json"
}

resolve_remote_name() {
  local set_id="$1"
  local component="$2"

  if [[ "$component" == "database" ]]; then
    if [[ -n "$B2_PREFIX" ]]; then
      echo "$B2_PREFIX/${set_id}/${set_id}-db.sql.gz"
    else
      echo "${set_id}/${set_id}-db.sql.gz"
    fi
  else
    if [[ -n "$B2_PREFIX" ]]; then
      echo "$B2_PREFIX/${set_id}/${set_id}-${component}.tar.gz"
    else
      echo "${set_id}/${set_id}-${component}.tar.gz"
    fi
  fi
}

resolve_remote_name_legacy() {
  local set_id="$1"
  local component="$2"
  local day_id="${set_id:0:8}"
  local path_prefix=""
  if [[ "$day_id" =~ ^[0-9]{8}$ ]]; then
    path_prefix="${day_id}/"
  fi

  if [[ "$component" == "database" ]]; then
    if [[ -n "$B2_PREFIX" ]]; then
      echo "$B2_PREFIX/${path_prefix}${set_id}-db.sql.gz"
    else
      echo "${path_prefix}${set_id}-db.sql.gz"
    fi
  else
    if [[ -n "$B2_PREFIX" ]]; then
      echo "$B2_PREFIX/${path_prefix}${set_id}-${component}.tar.gz"
    else
      echo "${path_prefix}${set_id}-${component}.tar.gz"
    fi
  fi
}

resolve_remote_name_legacy_root() {
  local set_id="$1"
  local component="$2"
  if [[ "$component" == "database" ]]; then
    if [[ -n "$B2_PREFIX" ]]; then
      echo "$B2_PREFIX/${set_id}-db.sql.gz"
    else
      echo "${set_id}-db.sql.gz"
    fi
  else
    if [[ -n "$B2_PREFIX" ]]; then
      echo "$B2_PREFIX/${set_id}-${component}.tar.gz"
    else
      echo "${set_id}-${component}.tar.gz"
    fi
  fi
}

resolve_remote_meta_name() {
  local set_id="$1"
  if [[ -n "$B2_PREFIX" ]]; then
    echo "$B2_PREFIX/${set_id}/${set_id}-meta.json"
  else
    echo "${set_id}/${set_id}-meta.json"
  fi
}

resolve_remote_meta_name_legacy() {
  local set_id="$1"
  local day_id="${set_id:0:8}"
  local path_prefix=""
  if [[ "$day_id" =~ ^[0-9]{8}$ ]]; then
    path_prefix="${day_id}/"
  fi
  if [[ -n "$B2_PREFIX" ]]; then
    echo "$B2_PREFIX/${path_prefix}${set_id}-meta.json"
  else
    echo "${path_prefix}${set_id}-meta.json"
  fi
}

resolve_remote_meta_name_legacy_root() {
  local set_id="$1"
  if [[ -n "$B2_PREFIX" ]]; then
    echo "$B2_PREFIX/${set_id}-meta.json"
  else
    echo "${set_id}-meta.json"
  fi
}

archive_existing_path() {
  local rel="$1"
  local stamp="$2"
  local abs="$APP_ROOT/$rel"

  if [[ ! -e "$abs" ]]; then
    return 0
  fi

  local base parent
  base="$(basename "$abs")"
  parent="$(dirname "$abs")"

  if [[ -d "$abs" ]]; then
    mv "$abs" "$parent/old-${base}-${stamp}"
  else
    mv "$abs" "$abs.old-${stamp}"
  fi
}

restore_component_archive_with_rollover() {
  local component="$1"
  local archive_file="$2"
  local stamp="$3"
  local paths=()

  build_component_paths "$component" paths

  # If the component does not currently exist locally, archive by known defaults too.
  if [[ ${#paths[@]} -eq 0 ]]; then
    case "$component" in
      config) paths=("config") ;;
      src) paths=("src") ;;
      templates) paths=("templates") ;;
      webroot) paths=("webroot") ;;
      resources) paths=("resources") ;;
      plugins) paths=("plugins") ;;
      uploads) paths=("uploads") ;;
      www) paths=("www") ;;
      db_files) paths=("db") ;;
      root_files) paths=("bin" "index.php" "composer.json" "composer.lock" "phinx.php") ;;
      env_files) paths=("config/.env" ".env") ;;
      *) ;;
    esac
  fi

  local p
  for p in "${paths[@]}"; do
    archive_existing_path "$p" "$stamp"
  done

  tar -xzf "$archive_file" -C "$APP_ROOT"
  echo "Restored component: $component"
}

restore_set() {
  local set_id="$1"
  local components_csv="$2"

  require_cmd curl
  require_cmd php
  require_common_env

  if [[ "$BACKUP_DRIVER" != "native" ]]; then
    echo "restore-set is supported in native mode only"
    exit 1
  fi

  setup_b2_context

  if [[ "$set_id" == "latest" ]]; then
    set_id="$(list_sets | awk 'NR==1 {print $1}')"
    if [[ -z "$set_id" ]]; then
      echo "No backup sets available"
      exit 1
    fi
  fi

  mkdir -p "$STAGING_DIR"

  local stamp
  stamp="$(date +%Y%m%d-%H%M%S)"
  local metadata_file metadata_remote metadata_remote_legacy metadata_remote_legacy_root metadata_source_url

  metadata_file="$STAGING_DIR/restore-${set_id}-meta.json"
  metadata_remote="$(resolve_remote_meta_name "$set_id")"
  metadata_remote_legacy="$(resolve_remote_meta_name_legacy "$set_id")"
  metadata_remote_legacy_root="$(resolve_remote_meta_name_legacy_root "$set_id")"
  if ! b2_download_file_by_name "$metadata_remote" "$metadata_file"; then
    if ! b2_download_file_by_name "$metadata_remote_legacy" "$metadata_file"; then
      b2_download_file_by_name "$metadata_remote_legacy_root" "$metadata_file" || true
    fi
  fi
  metadata_source_url="$(metadata_source_url_from_file "$metadata_file")"
  if [[ -n "$metadata_source_url" && -z "${RESTORE_SOURCE_URL:-}" ]]; then
    RESTORE_SOURCE_URL="$metadata_source_url"
  fi

  local IFS=','
  read -r -a selected <<< "$components_csv"

  local comp remote remote_legacy remote_legacy_root local_file
  for comp in "${selected[@]}"; do
    comp="$(echo "$comp" | tr '[:upper:]' '[:lower:]' | xargs)"
    [[ -z "$comp" ]] && continue

    if [[ "$comp" == "database" ]]; then
      remote="$(resolve_remote_name "$set_id" "database")"
      remote_legacy="$(resolve_remote_name_legacy "$set_id" "database")"
      remote_legacy_root="$(resolve_remote_name_legacy_root "$set_id" "database")"
      local_file="$STAGING_DIR/restore-${set_id}-db.sql.gz"
      if ! b2_download_file_by_name "$remote" "$local_file"; then
        if ! b2_download_file_by_name "$remote_legacy" "$local_file"; then
          b2_download_file_by_name "$remote_legacy_root" "$local_file"
        fi
      fi
      restore_db_file "$local_file" "$RESTORE_SOURCE_URL" "0"
      rm -f "$local_file" || true
      continue
    fi

    if ! is_valid_file_component "$comp"; then
      echo "Skipping unsupported component: $comp"
      continue
    fi

    remote="$(resolve_remote_name "$set_id" "$comp")"
    remote_legacy="$(resolve_remote_name_legacy "$set_id" "$comp")"
    remote_legacy_root="$(resolve_remote_name_legacy_root "$set_id" "$comp")"
    local_file="$STAGING_DIR/restore-${set_id}-${comp}.tar.gz"
    if ! b2_download_file_by_name "$remote" "$local_file"; then
      if ! b2_download_file_by_name "$remote_legacy" "$local_file"; then
        b2_download_file_by_name "$remote_legacy_root" "$local_file"
      fi
    fi
    restore_component_archive_with_rollover "$comp" "$local_file" "$stamp"
    rm -f "$local_file" || true
  done

  rm -f "$metadata_file" || true
  post_restore_finalize
  restore_health_check || true

  echo "Restore set complete: $set_id"
}

full_restore_components_csv() {
  local comps="database,config,src,templates,webroot,resources,plugins,uploads,www,db_files,root_files"
  if [[ "${RESTORE_INCLUDE_ENV_FILES:-0}" == "1" ]]; then
    comps="${comps},env_files"
  fi
  echo "$comps"
}

restore_full() {
  local set_id="${1:-latest}"
  local comps
  comps="$(full_restore_components_csv)"
  restore_set "$set_id" "$comps"
}

restore_db() {
  local remote_file="$1"
  require_cmd mysql
  require_cmd gzip
  require_cmd curl
  require_cmd php
  require_common_env
  require_db_env

  if [[ "$BACKUP_DRIVER" != "native" ]]; then
    echo "For restic mode, use the existing restic restore workflow."
    exit 1
  fi

  setup_b2_context
  mkdir -p "$STAGING_DIR"

  local restore_file="$STAGING_DIR/restore-db.sql.gz"
  b2_download_file_by_name "$remote_file" "$restore_file"

  if ! mysql_restore_stream_with_fallbacks "gunzip -c \"$restore_file\""; then
    echo "Database restore failed with configured credentials."
    exit 1
  fi

  apply_db_url_rewrite_if_needed "$RESTORE_SOURCE_URL" || true

  rm -f "$restore_file"
  post_restore_finalize
  restore_health_check || true
  echo "Database restored from remote file: $remote_file"
}

restore_db_file() {
  local local_file="$1"
  local source_url="${2:-}"
  local run_finalize="${3:-1}"
  require_cmd mysql
  require_cmd gzip
  require_db_env

  if [[ ! -f "$local_file" ]]; then
    echo "Restore file not found: $local_file"
    exit 1
  fi

  case "$local_file" in
    *.sql.gz)
      if ! mysql_restore_stream_with_fallbacks "gunzip -c \"$local_file\""; then
        echo "Database restore failed with configured credentials."
        exit 1
      fi
      ;;
    *.sql)
      if ! mysql_restore_file_with_fallbacks "$local_file"; then
        echo "Database restore failed with configured credentials."
        exit 1
      fi
      ;;
    *)
      echo "Unsupported DB restore file type. Use .sql or .sql.gz"
      exit 1
      ;;
  esac

  apply_db_url_rewrite_if_needed "$source_url" || true
  if [[ "$run_finalize" == "1" ]]; then
    post_restore_finalize
    restore_health_check || true
  fi

  echo "Database restored from local file: $local_file"
}

restore_files() {
  local remote_file="$1"
  local target_dir="$2"
  require_cmd tar
  require_cmd curl
  require_cmd php
  require_common_env

  if [[ "$BACKUP_DRIVER" != "native" ]]; then
    echo "For restic mode, use restic restore workflow."
    exit 1
  fi

  setup_b2_context
  mkdir -p "$target_dir" "$STAGING_DIR"

  local restore_file="$STAGING_DIR/restore-files.tar.gz"
  b2_download_file_by_name "$remote_file" "$restore_file"
  tar -xzf "$restore_file" -C "$target_dir"
  rm -f "$restore_file"

  echo "Files restored to: $target_dir"
}

restore_files_file() {
  local local_file="$1"
  local target_dir="$2"
  require_cmd tar

  if [[ ! -f "$local_file" ]]; then
    echo "Restore file not found: $local_file"
    exit 1
  fi

  mkdir -p "$target_dir"

  case "$local_file" in
    *.tar.gz|*.tgz)
      tar -xzf "$local_file" -C "$target_dir"
      ;;
    *.zip)
      if command -v unzip >/dev/null 2>&1; then
        unzip -o "$local_file" -d "$target_dir" >/dev/null
      else
        echo "unzip command not found; use .tar.gz archive for restore"
        exit 1
      fi
      ;;
    *)
      echo "Unsupported files restore archive. Use .tar.gz, .tgz, or .zip"
      exit 1
      ;;
  esac

  echo "Files restored to: $target_dir"
}

schedule_install() {
  local cron_expr="${1:-0 0 * * *}"
  local log_file="$APP_ROOT/logs/backup-manager.log"
  local script_path="$PLUGIN_ROOT/scripts/backup-manager.sh"
  local cron_line="${cron_expr} /bin/bash -lc '$script_path backup full --prune >> \"$log_file\" 2>&1'"

  mkdir -p "$APP_ROOT/logs"

  local existing cleaned
  existing="$(crontab -l 2>/dev/null || true)"
  cleaned="$(printf '%s\n' "$existing" | grep -v "$script_path" || true)"

  {
    printf '%s\n' "$cleaned"
    printf '%s\n' "$cron_line"
  } | crontab -

  echo "Installed schedule:"
  echo "$cron_line"
}

schedule_remove() {
  local script_path="$PLUGIN_ROOT/scripts/backup-manager.sh"
  local existing cleaned

  existing="$(crontab -l 2>/dev/null || true)"
  cleaned="$(printf '%s\n' "$existing" | grep -v "$script_path" || true)"
  printf '%s\n' "$cleaned" | crontab -

  echo "Removed backup-manager schedule entries"
}

schedule_show() {
  local script_path="$PLUGIN_ROOT/scripts/backup-manager.sh"
  (crontab -l 2>/dev/null | grep -F "$script_path") || true
}

main() {
  local cmd="${1:-}"
  shift || true

  load_env

  case "$cmd" in
    backup)
      local mode="${1:-}"
      local prune=0
      local components_csv="$BACKUP_FILE_COMPONENTS"
      shift || true
      while [[ $# -gt 0 ]]; do
        case "$1" in
          --prune) prune=1 ;;
          --components=*) components_csv="${1#*=}" ;;
          --components)
            shift
            components_csv="${1:-$BACKUP_FILE_COMPONENTS}"
            ;;
          *) echo "Unknown option: $1"; usage; exit 1 ;;
        esac
        shift || true
      done
      [[ -z "$mode" ]] && { usage; exit 1; }
      backup_mode "$mode" "$prune" "$components_csv"
      ;;
    list)
      list_snapshots
      ;;
    list-sets)
      list_sets
      ;;
    restore-set)
      [[ $# -lt 2 ]] && { usage; exit 1; }
      restore_set "$1" "$2"
      ;;
    restore-full)
      restore_full "${1:-latest}"
      ;;
    restore-db)
      [[ $# -lt 1 ]] && { usage; exit 1; }
      restore_db "$1"
      ;;
    restore-files)
      [[ $# -lt 2 ]] && { usage; exit 1; }
      restore_files "$1" "$2"
      ;;
    restore-db-file)
      [[ $# -lt 1 ]] && { usage; exit 1; }
      restore_db_file "$1"
      ;;
    restore-files-file)
      [[ $# -lt 2 ]] && { usage; exit 1; }
      restore_files_file "$1" "$2"
      ;;
    schedule-install)
      schedule_install "${1:-0 0 * * *}"
      ;;
    schedule-show)
      schedule_show
      ;;
    schedule-remove)
      schedule_remove
      ;;
    *)
      usage
      exit 1
      ;;
  esac
}

main "$@"
