#!/bin/sh
set -eu
cd "$(dirname "$0")/../.."
umask 077
[ -f deploy/oracle/.env ] || { echo 'Server configuration is missing.' >&2; exit 1; }
compose() { docker compose --env-file deploy/oracle/.env -f deploy/oracle/compose.yaml "$@"; }
backup="deploy/oracle/backups/revilo-$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -p "$backup"
resume() {
    compose exec -T app php artisan up --no-ansi || true
    compose start worker scheduler || true
}
trap resume EXIT
trap 'exit 1' HUP INT TERM
compose exec -T app php artisan down --retry=60 --no-ansi
compose stop worker scheduler
# Write the raw dump first so a failed database export cannot look successful.
compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" exec mysqldump -u"$MYSQL_USER" --single-transaction --quick --no-tablespaces "$MYSQL_DATABASE"' > "$backup/database.sql"
gzip "$backup/database.sql"
compose exec -T app tar -C /var/www/html/storage -czf - app > "$backup/storage.tar.gz"
cp deploy/oracle/.env "$backup/server.env"
compose images > "$backup/images.txt"
echo "Backup saved to $backup. Download it securely to a separate computer."
