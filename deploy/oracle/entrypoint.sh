#!/bin/sh
set -eu
umask 027
php artisan config:cache --no-ansi
php artisan revilo:deployment-check --config-only --no-ansi
exec "$@"
