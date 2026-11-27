#!/bin/sh
# Container entrypoint for the demo: install, migrate + seed (first run), serve.
set -e

cd /var/www/app

[ -f .env ] || cp .env.example .env

composer install --no-interaction --prefer-dist

grep -q '^APP_KEY=base64' .env || php artisan key:generate --force

until php artisan db:show >/dev/null 2>&1; do
  echo "Waiting for MySQL..."
  sleep 3
done

php artisan migrate --force

if [ "$(php artisan tinker --execute='echo \Classivo\Core\Models\School::count();' 2>/dev/null | tail -n1)" = "0" ]; then
  php artisan db:seed --force
  php artisan db:seed --class=MaramoFeeSeeder --force
  php artisan db:seed --class=MaramoCertificateSeeder --force
fi

exec php artisan serve --host=0.0.0.0 --port=8000
