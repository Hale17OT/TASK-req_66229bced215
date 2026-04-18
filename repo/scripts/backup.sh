#!/bin/bash
# Backup MySQL + storage/attachments to ./backups/<timestamp>/
# Intended to run on the host while `docker compose` is up.
set -euo pipefail

STAMP="$(date -u +%Y-%m-%dT%H-%M-%SZ)"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/backups/$STAMP"
mkdir -p "$OUT"

DB_NAME="${MYSQL_DATABASE:-studio_console}"
DB_USER="${MYSQL_USER:-studio}"
DB_PASS="${MYSQL_PASSWORD:-studio_change_me}"

echo "[backup] mysql dump → $OUT/db.sql.gz"
docker compose exec -T db sh -c \
  "mysqldump --single-transaction --routines --triggers --set-gtid-purged=OFF \
   -u'$DB_USER' -p'$DB_PASS' '$DB_NAME'" \
  | gzip -9 > "$OUT/db.sql.gz"

echo "[backup] attachments → $OUT/attachments.tar.gz"
docker compose exec -T app sh -c "tar -C /var/www/html/storage -czf - attachments" \
  > "$OUT/attachments.tar.gz"

echo "[backup] exports → $OUT/exports.tar.gz"
docker compose exec -T app sh -c "tar -C /var/www/html/storage -czf - exports" \
  > "$OUT/exports.tar.gz"

# checksum manifest
( cd "$OUT" && sha256sum * > SHA256SUMS )

echo "[backup] done: $OUT"
