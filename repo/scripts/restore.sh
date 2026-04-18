#!/bin/bash
# Restore from a backup directory created by ./scripts/backup.sh
#   ./scripts/restore.sh ./backups/2026-04-18T12-00-00Z
set -euo pipefail

if [ "$#" -ne 1 ]; then
  echo "usage: $0 <backup-dir>" >&2
  exit 64
fi

DIR="$1"
[ -d "$DIR" ] || { echo "no such dir: $DIR" >&2; exit 1; }
[ -f "$DIR/SHA256SUMS" ] && ( cd "$DIR" && sha256sum -c SHA256SUMS )

DB_NAME="${MYSQL_DATABASE:-studio_console}"
DB_USER="${MYSQL_USER:-studio}"
DB_PASS="${MYSQL_PASSWORD:-studio_change_me}"

echo "[restore] mysql ← $DIR/db.sql.gz"
gunzip -c "$DIR/db.sql.gz" | docker compose exec -T db sh -c \
  "mysql -u'$DB_USER' -p'$DB_PASS' '$DB_NAME'"

echo "[restore] attachments ← $DIR/attachments.tar.gz"
docker compose exec -T app sh -c "rm -rf /var/www/html/storage/attachments && tar -C /var/www/html/storage -xzf -" \
  < "$DIR/attachments.tar.gz"

echo "[restore] exports ← $DIR/exports.tar.gz"
docker compose exec -T app sh -c "rm -rf /var/www/html/storage/exports && tar -C /var/www/html/storage -xzf -" \
  < "$DIR/exports.tar.gz"

echo "[restore] done"
