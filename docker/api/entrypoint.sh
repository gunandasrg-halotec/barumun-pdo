#!/bin/sh
set -e

cd /var/www/api

echo "[PDO] Memeriksa vendor..."
if [ ! -f vendor/autoload.php ]; then
    echo "[PDO] Menjalankan composer install..."
    composer install --no-interaction --quiet
fi

# Regenerasi autoload agar map ke file yang di-mount dari host (bukan dari image build)
echo "[PDO] Regenerasi autoload map..."
composer dump-autoload --optimize --quiet

# Setup .env
if [ ! -f .env ]; then
    cp .env.example .env
fi

# Timpa nilai default scaffold agar sesuai Docker environment
sed -i "s|^CACHE_STORE=.*|CACHE_STORE=file|" .env
sed -i "s|^SESSION_DRIVER=.*|SESSION_DRIVER=file|" .env
sed -i "s|^DB_CONNECTION=.*|DB_CONNECTION=pgsql|" .env

# Append DB vars jika belum ada (scaffold sqlite tidak include host/port/user/pass)
grep -q "^DB_HOST=" .env     || echo "DB_HOST=${DB_HOST:-db}"             >> .env
grep -q "^DB_PORT=" .env     || echo "DB_PORT=${DB_PORT:-5432}"           >> .env
grep -q "^DB_DATABASE=" .env || echo "DB_DATABASE=${DB_DATABASE:-pdo_db}" >> .env
grep -q "^DB_USERNAME=" .env || echo "DB_USERNAME=${DB_USERNAME:-pdo_user}" >> .env
grep -q "^DB_PASSWORD=" .env || echo "DB_PASSWORD=${DB_PASSWORD:-secret}"  >> .env

# Generate APP_KEY jika belum ada
if [ -z "$APP_KEY" ] || grep -q "^APP_KEY=$" .env 2>/dev/null; then
    echo "[PDO] Generating APP_KEY..."
    php artisan key:generate --no-interaction
fi

# Publish Sanctum config
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider" \
    --tag="sanctum-config" --no-interaction 2>/dev/null || true

echo "[PDO] Menjalankan migrasi database..."
php artisan migrate --force --no-interaction

echo "[PDO] Menjalankan seeder (hanya jika tabel kosong)..."
ROW_COUNT=$(php artisan tinker --execute="echo \DB::table('companies')->count();" 2>/dev/null | tail -1)
if [ "$ROW_COUNT" = "0" ] || [ -z "$ROW_COUNT" ]; then
    php artisan db:seed --force --no-interaction
    echo "[PDO] Seeder selesai."
else
    echo "[PDO] Data sudah ada, seeder dilewati."
fi

echo "[PDO] Membersihkan cache..."
php artisan config:clear
php artisan route:clear

echo ""
echo "=========================================="
echo "  PDO API siap di http://localhost:8000"
echo "=========================================="
echo ""

exec php artisan serve --host=0.0.0.0 --port=8000
