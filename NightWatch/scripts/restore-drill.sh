#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="${NIGHTWATCH_PROJECT_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
DB_HOST="${NIGHTWATCH_DB_HOST:-localhost}"
RESTORE_USER="${NIGHTWATCH_RESTORE_DB_USER:-${NIGHTWATCH_DB_USER:-root}}"
RESTORE_PASSWORD="${NIGHTWATCH_RESTORE_DB_PASSWORD:-${NIGHTWATCH_DB_PASSWORD:-}}"
RESTORE_DB="${NIGHTWATCH_RESTORE_DRILL_DB:-NightWatchDB_restore_drill_$(date -u +%Y%m%dT%H%M%SZ)}"
KEEP_RESTORE_DB="${NIGHTWATCH_KEEP_RESTORE_DB:-0}"
DUMP_FILE="${1:-}"

if [[ -z "$DUMP_FILE" || ! -f "$DUMP_FILE" ]]; then
  echo "Usage: $0 /path/to/NightWatchDB.sql.gz" >&2
  exit 1
fi

if [[ ! "$RESTORE_DB" =~ ^[A-Za-z0-9_]+$ ]]; then
  echo "NIGHTWATCH_RESTORE_DRILL_DB may contain only letters, numbers, and underscores." >&2
  exit 1
fi

if [[ -z "$RESTORE_PASSWORD" ]]; then
  echo "NIGHTWATCH_RESTORE_DB_PASSWORD or NIGHTWATCH_DB_PASSWORD is required." >&2
  exit 1
fi

command -v mysql >/dev/null 2>&1 || { echo "mysql client is required." >&2; exit 1; }
command -v gzip >/dev/null 2>&1 || { echo "gzip is required." >&2; exit 1; }

cleanup() {
  if [[ "$KEEP_RESTORE_DB" != "1" ]]; then
    MYSQL_PWD="$RESTORE_PASSWORD" mysql --host="$DB_HOST" --user="$RESTORE_USER" -e "DROP DATABASE IF EXISTS \`$RESTORE_DB\`;" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

MYSQL_PWD="$RESTORE_PASSWORD" mysql --host="$DB_HOST" --user="$RESTORE_USER" -e "CREATE DATABASE \`$RESTORE_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
gzip -dc "$DUMP_FILE" | MYSQL_PWD="$RESTORE_PASSWORD" mysql --host="$DB_HOST" --user="$RESTORE_USER" "$RESTORE_DB"

TABLE_COUNT="$(MYSQL_PWD="$RESTORE_PASSWORD" mysql --batch --skip-column-names --host="$DB_HOST" --user="$RESTORE_USER" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$RESTORE_DB';")"
if [[ "$TABLE_COUNT" -lt 1 ]]; then
  echo "Restore drill failed: no tables found in $RESTORE_DB." >&2
  exit 1
fi

if [[ -f "$PROJECT_DIR/DB/migrate.php" ]]; then
  NIGHTWATCH_DB_HOST="$DB_HOST" \
  NIGHTWATCH_DB_USER="$RESTORE_USER" \
  NIGHTWATCH_DB_PASSWORD="$RESTORE_PASSWORD" \
  NIGHTWATCH_DB_NAME="$RESTORE_DB" \
  php "$PROJECT_DIR/DB/migrate.php" >/dev/null
fi

echo "Restore drill succeeded against temporary database $RESTORE_DB with $TABLE_COUNT tables."
if [[ "$KEEP_RESTORE_DB" == "1" ]]; then
  echo "Temporary database kept because NIGHTWATCH_KEEP_RESTORE_DB=1."
fi
