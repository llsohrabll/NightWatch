#!/usr/bin/env bash
set -euo pipefail

umask 077

PROJECT_DIR="${NIGHTWATCH_PROJECT_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
BACKUP_DIR="${NIGHTWATCH_BACKUP_DIR:-$PROJECT_DIR/backups}"
DB_HOST="${NIGHTWATCH_DB_HOST:-localhost}"
DB_USER="${NIGHTWATCH_DB_USER:-nightwatch_app}"
DB_PASSWORD="${NIGHTWATCH_DB_PASSWORD:-}"
DB_NAME="${NIGHTWATCH_DB_NAME:-NightWatchDB}"
PHOTO_DIR="${NIGHTWATCH_PHOTO_STORAGE_DIR:-$PROJECT_DIR/storage/photos}"
LOG_DIR="${NIGHTWATCH_LOG_DIR:-$PROJECT_DIR/logs}"
RETENTION_DAYS="${NIGHTWATCH_BACKUP_RETENTION_DAYS:-14}"
TIMESTAMP="$(date -u +%Y%m%dT%H%M%SZ)"
RUN_DIR="$BACKUP_DIR/$TIMESTAMP"

if [[ -z "$DB_PASSWORD" ]]; then
  echo "NIGHTWATCH_DB_PASSWORD is required for database backup." >&2
  exit 1
fi

command -v mysqldump >/dev/null 2>&1 || { echo "mysqldump is required." >&2; exit 1; }
command -v gzip >/dev/null 2>&1 || { echo "gzip is required." >&2; exit 1; }
command -v tar >/dev/null 2>&1 || { echo "tar is required." >&2; exit 1; }

mkdir -p "$RUN_DIR"

DB_DUMP="$RUN_DIR/${DB_NAME}.sql.gz"
FILES_ARCHIVE="$RUN_DIR/nightwatch-private-files.tar.gz"
MANIFEST="$RUN_DIR/MANIFEST.sha256"

MYSQL_PWD="$DB_PASSWORD" mysqldump \
  --host="$DB_HOST" \
  --user="$DB_USER" \
  --single-transaction \
  --routines \
  --triggers \
  --events \
  --hex-blob \
  --default-character-set=utf8mb4 \
  "$DB_NAME" | gzip -9 > "$DB_DUMP"

# Store private runtime files separately from source code. Missing directories are tolerated.
tar_inputs=()
[[ -d "$PHOTO_DIR" ]] && tar_inputs+=("$PHOTO_DIR")
[[ -d "$LOG_DIR" ]] && tar_inputs+=("$LOG_DIR")
if (( ${#tar_inputs[@]} > 0 )); then
  tar --absolute-names --warning=no-file-changed -czf "$FILES_ARCHIVE" "${tar_inputs[@]}" || {
    status=$?
    [[ "$status" -eq 1 ]] || exit "$status"
  }
else
  tar -czf "$FILES_ARCHIVE" --files-from /dev/null
fi

(
  cd "$RUN_DIR"
  sha256sum "$(basename "$DB_DUMP")" "$(basename "$FILES_ARCHIVE")" > "$(basename "$MANIFEST")"
)

if [[ "$RETENTION_DAYS" =~ ^[0-9]+$ ]] && (( RETENTION_DAYS > 0 )); then
  find "$BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -mtime "+$RETENTION_DAYS" -exec rm -rf {} +
fi

echo "Backup completed: $RUN_DIR"
