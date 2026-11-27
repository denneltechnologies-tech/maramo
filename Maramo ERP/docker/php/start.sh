#!/bin/sh
# Container entrypoint: install, migrate + seed (first run), serve.
set -e

cd /var/www/app

[ -f .env ] || cp .env.example .env

# Ensure MySQL and Ghanaian presets are always configured in .env
sed -i 's/^DB_CONNECTION=.*/DB_CONNECTION=mysql/' .env
sed -i 's/^#\? \?DB_HOST=.*/DB_HOST=mysql/' .env
sed -i 's/^#\? \?DB_PORT=.*/DB_PORT=3306/' .env
sed -i 's/^#\? \?DB_DATABASE=.*/DB_DATABASE=classivo/' .env
sed -i 's/^#\? \?DB_USERNAME=.*/DB_USERNAME=classivo/' .env
sed -i 's/^#\? \?DB_PASSWORD=.*/DB_PASSWORD=classivo/' .env
sed -i 's/^SESSION_DRIVER=.*/SESSION_DRIVER=file/' .env
sed -i 's/^CACHE_STORE=.*/CACHE_STORE=file/' .env
sed -i 's/^QUEUE_CONNECTION=.*/QUEUE_CONNECTION=sync/' .env

grep -q '^CLASSIVO_PRESET=' .env && sed -i 's/^CLASSIVO_PRESET=.*/CLASSIVO_PRESET=ghana/' .env || echo "CLASSIVO_PRESET=ghana" >> .env
grep -q '^FEES_CURRENCY=' .env && sed -i 's/^FEES_CURRENCY=.*/FEES_CURRENCY=GHS/' .env || echo "FEES_CURRENCY=GHS" >> .env
grep -q '^FEES_CURRENCY_SYMBOL=' .env && sed -i 's/^FEES_CURRENCY_SYMBOL=.*/FEES_CURRENCY_SYMBOL="GH₵"/' .env || echo 'FEES_CURRENCY_SYMBOL="GH₵"' >> .env

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

php artisan optimize:clear

exec php artisan serve --host=0.0.0.0 --port=8000
