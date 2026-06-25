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
grep -q "^PAYROLL_INTERNAL_API_BASE_URL=" .env || echo "PAYROLL_INTERNAL_API_BASE_URL=${PAYROLL_INTERNAL_API_BASE_URL:-}" >> .env
grep -q "^PAYROLL_INTERNAL_API_TOKEN=" .env || echo "PAYROLL_INTERNAL_API_TOKEN=${PAYROLL_INTERNAL_API_TOKEN:-}" >> .env

DB_HOST_VALUE="${DB_HOST:-db}"
DB_PORT_VALUE="${DB_PORT:-5432}"
DB_DATABASE_VALUE="${DB_DATABASE:-pdo_db}"
DB_USERNAME_VALUE="${DB_USERNAME:-pdo_user}"
DB_PASSWORD_VALUE="${DB_PASSWORD:-secret}"

# Generate APP_KEY jika belum ada
if [ -z "$APP_KEY" ] || grep -q "^APP_KEY=$" .env 2>/dev/null; then
    echo "[PDO] Generating APP_KEY..."
    php artisan key:generate --no-interaction
fi

# Publish Sanctum config
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider" \
    --tag="sanctum-config" --no-interaction 2>/dev/null || true

echo "[PDO] Menunggu database siap..."
DB_READY=0
for attempt in $(seq 1 30); do
    if DB_HOST="$DB_HOST_VALUE" DB_PORT="$DB_PORT_VALUE" DB_DATABASE="$DB_DATABASE_VALUE" DB_USERNAME="$DB_USERNAME_VALUE" DB_PASSWORD="$DB_PASSWORD_VALUE" php -r '
        try {
            new PDO(
                sprintf("pgsql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT"), getenv("DB_DATABASE")),
                getenv("DB_USERNAME"),
                getenv("DB_PASSWORD"),
                [PDO::ATTR_TIMEOUT => 2]
            );
            exit(0);
        } catch (Throwable $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            exit(1);
        }
    ' >/dev/null 2>&1; then
        DB_READY=1
        break
    fi

    echo "[PDO] Database belum siap, retry ${attempt}/30..."
    sleep 2
done

if [ "$DB_READY" -ne 1 ]; then
    echo "[PDO] Database gagal dihubungi."
    exit 1
fi

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
