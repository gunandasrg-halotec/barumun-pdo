#!/bin/sh
set -e

cd /var/www/api

echo "[PDO] Menjalankan migrasi database..."
php artisan migrate --force --no-interaction

echo "[PDO] Caching konfigurasi untuk produksi..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

echo "[PDO] Storage link..."
php artisan storage:link 2>/dev/null || true

echo ""
echo "=========================================="
echo "  PDO API Production Ready"
echo "  PHP-FPM + Nginx + Queue Workers"
echo "=========================================="
echo ""

exec supervisord -c /etc/supervisord.conf
