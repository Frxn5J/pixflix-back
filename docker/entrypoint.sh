#!/bin/sh
set -eu

cd /var/www/html

# Migrations are opt-in. This avoids several horizontally scaled web
# containers racing during a release; run them once as Coolify's pre-deploy
# command or set RUN_MIGRATIONS=true on a one-off migration job.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
fi

# These commands are safe to run on every container start and make sure a
# newly deployed image never uses stale framework metadata.
php artisan config:cache
php artisan route:cache
php artisan view:cache

if [ "${RUN_STORAGE_LINK:-false}" = "true" ]; then
    php artisan storage:link || true
fi

exec "$@"
