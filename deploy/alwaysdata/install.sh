#!/bin/sh
# First installation. For updates, follow the maintenance steps in the guide.
set -eu
case "${1:-}" in
    ''|--demo) ;;
    *) echo 'Usage: sh deploy/alwaysdata/install.sh [--demo]' >&2; exit 1 ;;
esac
cd "$(dirname "$0")/../.."
command -v php >/dev/null || { echo 'Select PHP in Environment > PHP first.' >&2; exit 1; }
command -v composer >/dev/null || { echo 'Composer 2 is required.' >&2; exit 1; }
[ -f .env ] || { echo 'Create the private server .env using the guide first.' >&2; exit 1; }
[ -f public/build/manifest.json ] || { echo 'Upload the built alwaysdata package, including public/build.' >&2; exit 1; }
mkdir -p bootstrap/cache storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
chmod 600 .env
chmod -R u+rwX bootstrap/cache storage
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
composer check-platform-reqs --no-dev
php artisan config:clear --no-ansi
# Preserve an existing encryption key, including when transferring real data.
if php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); exit(filled(config("app.key")) ? 0 : 1);'; then
    echo 'Existing application encryption key preserved.'
else
    key_status=$?
    [ "$key_status" = 1 ] || { echo 'Could not read the application key; fix the configuration without replacing existing keys.' >&2; exit 1; }
    php artisan key:generate --force --no-ansi
fi
php artisan config:cache --no-ansi
php artisan revilo:deployment-check --config-only --no-ansi
php artisan migrate --force --no-ansi
if [ "${1:-}" = --demo ]; then
    php artisan revilo:seed-preview --no-ansi
fi
if [ ! -L public/storage ]; then
    php artisan storage:link --no-ansi
fi
php artisan view:cache --no-ansi
php artisan revilo:deployment-check --no-ansi
echo 'Installed. Follow the guide to create an administrator, set the public web root, enable HTTPS and add the scheduled task.'
