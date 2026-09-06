#!/usr/bin/env bash
# Academy LMS Mode A demo web server.
# Raises PHP multipart limits to match the 10 MB application document cap.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

HOST="${DEMO_HOST:-127.0.0.1}"
PORT="${DEMO_PORT:-8080}"

exec php \
  -d upload_max_filesize=10M \
  -d post_max_size=16M \
  -S "${HOST}:${PORT}" \
  -t public
