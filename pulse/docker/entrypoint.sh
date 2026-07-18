#!/bin/sh
# Boot the app: install deps if missing, wait for the DB, migrate (app only), cache config.
set -e
cd /var/www/html

# Install PHP dependencies on first boot (bind-mounted code has no vendor/ yet).
if [ ! -f vendor/autoload.php ]; then
    echo "==> Installing composer dependencies…"
    composer install --no-dev --optimize-autoloader --no-interaction
fi

# Ensure writable dirs (bind mount ownership can differ from the container user).
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

# Wait for PostgreSQL to accept connections.
if [ -n "$DB_HOST" ]; then
    echo "==> Waiting for database ${DB_HOST}:${DB_PORT:-5432}…"
    until php -r 'exit(@fsockopen(getenv("DB_HOST"), (int)(getenv("DB_PORT") ?: 5432)) ? 0 : 1);' 2>/dev/null; do
        sleep 2
    done
fi

# Only the app container runs migrations (RUN_MIGRATIONS=true in compose).
if [ "$RUN_MIGRATIONS" = "true" ]; then
    echo "==> Running migrations…"
    php artisan migrate --force
    php artisan storage:link 2>/dev/null || true
fi

# Ensure Filament admin assets exist (safe/idempotent), then cache for production.
php artisan filament:assets 2>/dev/null || true
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
