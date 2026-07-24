#!/usr/bin/env bash
# UAT backup → restore rehearsal into a separate database (RC-01).
# Usage:
#   export MYSQL_PWD=...
#   ./bin/restore-rehearsal.sh path/to/backup.sql [target_db]
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BACKUP="${1:-}"
TARGET_DB="${2:-academy_lms_restore_rehearsal}"

if [[ -z "$BACKUP" || ! -f "$BACKUP" ]]; then
  echo "[restore-rehearsal] Usage: $0 path/to/backup.sql [target_db]" >&2
  exit 1
fi

: "${DB_HOST:=127.0.0.1}"
: "${DB_PORT:=3306}"
: "${DB_USER:=academy}"

if [[ -f "${BACKUP}.sha256" ]]; then
  echo "[restore-rehearsal] verifying checksum…"
  (cd "$(dirname "$BACKUP")" && shasum -a 256 -c "$(basename "$BACKUP").sha256")
fi

mysql --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" -e \
  "DROP DATABASE IF EXISTS \`${TARGET_DB}\`; CREATE DATABASE \`${TARGET_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "[restore-rehearsal] restoring into ${TARGET_DB}…"
mysql --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" "$TARGET_DB" < "$BACKUP"

echo "[restore-rehearsal] verifying phinxlog…"
MIGS="$(mysql --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" "$TARGET_DB" -N -e "SELECT COUNT(*) FROM phinxlog")"
echo "[restore-rehearsal] phinxlog rows=${MIGS}"
if [[ "$MIGS" -lt 1 ]]; then
  echo "[restore-rehearsal] FAIL: no migrations in restored DB" >&2
  exit 1
fi

echo "[restore-rehearsal] sample invariants…"
mysql --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" "$TARGET_DB" -e \
  "SELECT
     (SELECT COUNT(*) FROM users) AS users,
     (SELECT COUNT(*) FROM applications) AS applications,
     (SELECT COUNT(*) FROM enrolments e
        INNER JOIN applications a ON a.application_id = e.application_id
        WHERE a.status <> 'admitted') AS orphan_enrolments;"

echo "[restore-rehearsal] OK — drop ${TARGET_DB} when finished:"
echo "  mysql -e \"DROP DATABASE \\\`${TARGET_DB}\\\`\""
