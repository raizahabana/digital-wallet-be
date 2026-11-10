#!/bin/sh
set -e
cd /var/www/html

[ -f .env ] || cp .env.example .env
[ -d vendor ] || composer install --no-interaction --prefer-dist
grep -q '^APP_KEY=base64' .env || php artisan key:generate --force

echo "Waiting for the database..."
until php -r 'try { new PDO("mysql:host=".getenv("DB_HOST").";port=3306;dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); } catch (Throwable $e) { exit(1); }'; do
  sleep 2
done

if [ "$RUN_MIGRATIONS" = "true" ]; then
  php artisan migrate --force --seed
else
  # The queue worker waits until the app container has created the tables.
  until php artisan migrate:status >/dev/null 2>&1; do sleep 2; done
fi

exec "$@"