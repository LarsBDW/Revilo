#!/bin/sh
set -eu
case "${1:-}" in
    ''|--demo) ;;
    *) echo 'Usage: sh deploy/oracle/deploy.sh [--demo]' >&2; exit 1 ;;
esac
cd "$(dirname "$0")/../.."
[ -f deploy/oracle/.env ] || { echo 'Run init-env.sh first.' >&2; exit 1; }
compose() { docker compose --env-file deploy/oracle/.env -f deploy/oracle/compose.yaml "$@"; }
maintenance=0
finish() {
    if [ "$maintenance" = 1 ]; then
        echo 'Deployment did not finish. Maintenance mode remains enabled; fix the reported error and rerun deployment, or restore your backup.' >&2
    fi
}
trap finish EXIT
trap 'exit 1' HUP INT TERM
compose config --quiet
compose build --pull app web
compose up -d --wait --wait-timeout 360 db
if [ -n "$(compose ps -q --status running app)" ]; then
    compose exec -T app php artisan down --retry=60 --no-ansi
    maintenance=1
    compose stop worker scheduler
fi
compose run --rm app php artisan migrate --force --no-ansi
if [ "${1:-}" = --demo ]; then
    compose run --rm app php artisan revilo:seed-preview --no-ansi
fi
compose run --rm app php artisan revilo:deployment-check --no-ansi
compose up -d --wait --wait-timeout 180 app
compose exec -T app php artisan up --no-ansi
maintenance=0
compose up -d --wait --wait-timeout 180 web worker scheduler
echo 'Revilo is running. Create your new server administrator using the guide.'
