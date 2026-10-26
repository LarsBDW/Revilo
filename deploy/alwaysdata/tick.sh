#!/bin/sh
# One finite scheduled task, suitable for the Free plan without a daemon.
set -eu
cd "$(dirname "$0")/../.."
[ -f .env ] || { echo 'Missing server environment.' >&2; exit 1; }
[ -f storage/framework/down ] && exit 0
command -v flock >/dev/null || { echo 'flock is required for scheduled task locking.' >&2; exit 1; }
exec 9>storage/framework/alwaysdata-tick.lock
flock -n 9 || exit 0
php -d memory_limit=128M artisan schedule:run --no-ansi
php -d memory_limit=128M artisan queue:work --stop-when-empty --max-time=40 --sleep=1 --tries=3 --timeout=30 --memory=96 --no-ansi
