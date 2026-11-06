#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
umask 077
mkdir -p backups
file="backups/kimchibang-$(date -u +%Y%m%dT%H%M%SZ).sql"
# mysqldump has no plaintext password in its command arguments or host logs.
if docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysqldump -u "$MYSQL_USER" --single-transaction --no-tablespaces "$MYSQL_DATABASE"' > "$file.tmp"; then
  mv "$file.tmp" "$file"
  printf '백업 완료: %s\n' "$file"
else
  rm -f "$file.tmp"
  exit 1
fi
