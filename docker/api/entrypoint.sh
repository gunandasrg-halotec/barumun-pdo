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
