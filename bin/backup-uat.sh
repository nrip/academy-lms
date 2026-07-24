#!/usr/bin/env bash
# UAT MySQL backup helper (RC-01). Does not embed credentials.
# Usage:
#   export MYSQL_PWD=...   # preferred over -p in argv
#   ./bin/backup-uat.sh [output_dir]
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT_DIR="${1:-$ROOT/storage/backups}"
mkdir -p "$OUT_DIR"

: "${DB_HOST:=127.0.0.1}"
: "${DB_PORT:=3306}"
: "${DB_NAME:=academy_lms}"
: "${DB_USER:=academy}"

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
FILE="$OUT_DIR/${DB_NAME}_${STAMP}.sql"
CHECKSUM="$FILE.sha256"

echo "[backup] dumping ${DB_NAME} @ ${DB_HOST}:${DB_PORT} → ${FILE}"
mysqldump \
  --host="$DB_HOST" \
  --port="$DB_PORT" \
  --user="$DB_USER" \
  --single-transaction \
  --routines \
  --triggers \
  --set-gtid-purged=OFF \
  "$DB_NAME" > "$FILE"

(cd "$OUT_DIR" && shasum -a 256 "$(basename "$FILE")" > "$(basename "$CHECKSUM")")
echo "[backup] checksum written to ${CHECKSUM}"
echo "[backup] recommend encrypting at rest before off-host transfer."
echo "[backup] OK"
