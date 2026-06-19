# Panduan Deployment — AWS EC2 (Ubuntu + PostgreSQL + Nginx)

**Stack:** Ubuntu 22.04/24.04 · PHP 8.4 · Laravel 13 · PostgreSQL 16 · Nginx · Node 22 · Certbot SSL

> **Catatan arsitektur repo:** Repository hanya berisi kode custom (Controllers, Models, Services,
> Migrations, Seeders, Routes, Frontend). File scaffold Laravel (composer.json, artisan, config/,
> public/) dibuat langsung di server menggunakan `composer create-project` pada Tahap 4.

---

## Prasyarat

Sebelum memulai, pastikan:

- EC2 instance sudah berjalan (minimal **t3.small** — 2 vCPU, 2 GB RAM)
- Security Group EC2 sudah membuka port **22** (SSH), **80** (HTTP), **443** (HTTPS)
- File `.pem` / key pair SSH tersedia di komputer lokal Anda
- Record DNS domain sudah diarahkan ke IP publik EC2 (butuh 5–30 menit propagasi)
- AWS S3 bucket sudah dibuat untuk upload bukti realisasi
- IAM user dengan akses S3 sudah dibuat (dapat `ACCESS_KEY` dan `SECRET_KEY`)

---

## Tahap 1 — Masuk ke Server & Update

```bash
# SSH ke server
ssh -i /path/ke/keypair.pem ubuntu@<IP_PUBLIK_EC2>

# Update sistem operasi
sudo apt update && sudo apt upgrade -y
```

---

## Tahap 2 — Install Dependensi Server

```bash
# ── PHP 8.4 ──────────────────────────────────────────────
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update
sudo apt install -y php8.4-fpm php8.4-cli php8.4-pgsql php8.4-mbstring \
  php8.4-xml php8.4-curl php8.4-zip php8.4-bcmath php8.4-tokenizer \
  php8.4-intl php8.4-gd

# ── Composer ─────────────────────────────────────────────
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# ── Node.js 22 ───────────────────────────────────────────
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs

# ── PostgreSQL 16 ────────────────────────────────────────
sudo apt install -y postgresql postgresql-contrib

# ── Nginx & Git ──────────────────────────────────────────
sudo apt install -y nginx git
```

---

## Tahap 3 — Setup PostgreSQL

```bash
sudo -u postgres psql
```

```sql
CREATE USER pdo_user WITH PASSWORD 'GANTI_PASSWORD_KUAT_DISINI';
CREATE DATABASE pdo_db OWNER pdo_user;
GRANT ALL PRIVILEGES ON DATABASE pdo_db TO pdo_user;
\q
```

---

## Tahap 4 — Setup Backend Laravel

Karena repo hanya berisi kode custom (tanpa scaffold Laravel), langkah ini
**membuat proyek Laravel baru dulu**, lalu **menimpa dengan kode kita**.

```bash
# Buat direktori kerja
sudo mkdir -p /var/www
cd /var/www

# ── LANGKAH 4a: Buat scaffold Laravel 13 baru ────────────
composer create-project laravel/laravel pdo-api --prefer-dist
# (proses ini ±2–5 menit, mengunduh ~40 MB)

# ── LANGKAH 4b: Clone repository kita ───────────────────
git clone https://github.com/gunandasrg-halotec/barumun-pdo.git /tmp/barumun-pdo

# ── LANGKAH 4c: Overlay kode custom ke scaffold ─────────
REPO=/tmp/barumun-pdo/apps/api
TARGET=/var/www/pdo-api

# Timpa dengan kode custom kita
cp -r $REPO/app/*         $TARGET/app/
cp -r $REPO/database/*    $TARGET/database/
cp    $REPO/routes/api.php $TARGET/routes/api.php
cp -r $REPO/tests/*       $TARGET/tests/
cp    $REPO/.env.example  $TARGET/.env.example
cp    $REPO/bootstrap/app.php $TARGET/bootstrap/app.php

# Tambah package yang dibutuhkan
cd $TARGET
composer require laravel/sanctum

# ── LANGKAH 4d: Konfigurasi .env ────────────────────────
cp .env.example .env
nano .env
```

Isi nilai berikut di `.env` (ganti semua `<...>`):

```dotenv
APP_NAME="PDO Barumun"
APP_ENV=production
APP_KEY=                            # akan di-generate di langkah berikut
APP_DEBUG=false
APP_URL=https://<DOMAIN_ANDA>
APP_TIMEZONE=Asia/Jakarta

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=pdo_db
DB_USERNAME=pdo_user
DB_PASSWORD=GANTI_PASSWORD_KUAT_DISINI

FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=<S3_ACCESS_KEY>
AWS_SECRET_ACCESS_KEY=<S3_SECRET_KEY>
AWS_DEFAULT_REGION=ap-southeast-1
AWS_BUCKET=<NAMA_S3_BUCKET>

SANCTUM_STATEFUL_DOMAINS=<DOMAIN_ANDA>
SESSION_DOMAIN=.<DOMAIN_ANDA>

LOG_CHANNEL=daily
LOG_LEVEL=error
```

```bash
# ── LANGKAH 4e: Inisialisasi aplikasi ───────────────────

# Generate APP_KEY
php artisan key:generate

# Publish Sanctum config
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"

# Jalankan migrasi + seeder
php artisan migrate --seed --force

# Optimasi production
php artisan config:cache
php artisan route:cache
php artisan view:cache

# ── LANGKAH 4f: Permission folder ────────────────────────
sudo chown -R www-data:www-data /var/www/pdo-api/storage
sudo chown -R www-data:www-data /var/www/pdo-api/bootstrap/cache
sudo chmod -R 775 /var/www/pdo-api/storage
sudo chmod -R 775 /var/www/pdo-api/bootstrap/cache
```

---

## Tahap 5 — Build Frontend (React)

```bash
# Buat folder frontend
sudo mkdir -p /var/www/pdo-web
sudo chown ubuntu:ubuntu /var/www/pdo-web

# Salin kode frontend dari repo yang sudah di-clone
cp -r /tmp/barumun-pdo/apps/web/. /var/www/pdo-web/

cd /var/www/pdo-web

# Install dependencies
npm install

# Build production
npm run build
# Hasil ada di: /var/www/pdo-web/dist/
```

---

## Tahap 6 — Konfigurasi Nginx

```bash
sudo nano /etc/nginx/sites-available/pdo
```

Paste konfigurasi berikut (ganti `<DOMAIN_ANDA>`):

```nginx
server {
    listen 80;
    server_name <DOMAIN_ANDA> www.<DOMAIN_ANDA>;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    server_name <DOMAIN_ANDA> www.<DOMAIN_ANDA>;

    # SSL — diisi otomatis oleh Certbot (Tahap 7)
    # ssl_certificate ...
    # ssl_certificate_key ...

    # Frontend React SPA
    root /var/www/pdo-web/dist;
    index index.html;

    location / {
        try_files $uri $uri/ /index.html;
    }

    # Backend API Laravel
    location /api/ {
        proxy_pass http://127.0.0.1:8000/;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    client_max_body_size 10M;

    gzip on;
    gzip_types text/plain text/css application/json application/javascript;
}
```

```bash
# Aktifkan site
sudo ln -s /etc/nginx/sites-available/pdo /etc/nginx/sites-enabled/
sudo rm /etc/nginx/sites-enabled/default

# Test konfigurasi
sudo nginx -t

# Restart
sudo systemctl restart nginx
```

### Setup Laravel untuk berjalan di port 8000

Buat systemd service agar Laravel Octane / artisan serve berjalan otomatis:

```bash
sudo nano /etc/systemd/system/pdo-api.service
```

```ini
[Unit]
Description=PDO Laravel API
After=network.target

[Service]
User=www-data
WorkingDirectory=/var/www/pdo-api
ExecStart=/usr/bin/php artisan serve --host=127.0.0.1 --port=8000
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable pdo-api
sudo systemctl start pdo-api
sudo systemctl status pdo-api
```

---

## Tahap 7 — SSL Certificate (HTTPS Gratis)

```bash
sudo apt install -y certbot python3-certbot-nginx

sudo certbot --nginx -d <DOMAIN_ANDA> -d www.<DOMAIN_ANDA>

# Verifikasi auto-renewal
sudo certbot renew --dry-run
```

---

## Tahap 8 — Setup Cron untuk Scheduler

```bash
sudo crontab -e -u www-data
```

Tambahkan:
```
* * * * * cd /var/www/pdo-api && php artisan schedule:run >> /dev/null 2>&1
```

---

## Tahap 9 — Verifikasi Deployment

```bash
# Cek semua service
sudo systemctl status nginx
sudo systemctl status php8.4-fpm
sudo systemctl status postgresql
sudo systemctl status pdo-api

# Test API
curl http://127.0.0.1:8000/api/ping
curl https://<DOMAIN_ANDA>/api/ping

# Pantau log
tail -f /var/www/pdo-api/storage/logs/laravel.log
tail -f /var/log/nginx/error.log
```

Buka browser: `https://<DOMAIN_ANDA>` — halaman login harus muncul.

**Akun default (seeder):**
| Role | Email | Password |
|---|---|---|
| Admin | admin@barumunpalma.co.id | ChangeMe123! |

> **Ganti password admin segera setelah pertama login via halaman Pengaturan.**

---

## Arsitektur Deployment

```
User Browser
     │ HTTPS (443)
     ▼
  Nginx
  ├── /         → /var/www/pdo-web/dist/    (React SPA)
  └── /api/*    → 127.0.0.1:8000           (Laravel php artisan serve)
                        │
                 PostgreSQL 16
                 (localhost:5432)
                        │
                 AWS S3
                 (upload bukti realisasi)
```

---

## Update Aplikasi (Setelah Kode Baru di-Push)

```bash
# Pull kode terbaru
cd /tmp/barumun-pdo && git pull origin main

# Update backend — overlay ulang kode custom
REPO=/tmp/barumun-pdo/apps/api
TARGET=/var/www/pdo-api

cp -r $REPO/app/*          $TARGET/app/
cp -r $REPO/database/*     $TARGET/database/
cp    $REPO/routes/api.php $TARGET/routes/api.php
cp    $REPO/bootstrap/app.php $TARGET/bootstrap/app.php

cd $TARGET
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo systemctl restart pdo-api

# Update frontend
cp -r /tmp/barumun-pdo/apps/web/. /var/www/pdo-web/
cd /var/www/pdo-web && npm install && npm run build

sudo systemctl reload nginx
```

---

## Troubleshooting Umum

| Gejala | Kemungkinan Penyebab | Solusi |
|---|---|---|
| `502 Bad Gateway` | Laravel service tidak jalan | `sudo systemctl restart pdo-api` |
| `404 pada /api/*` | Nginx proxy salah konfigurasi | Cek `location /api/` di nginx config |
| `500 Server Error` | Error PHP / env salah | `tail -f /var/www/pdo-api/storage/logs/laravel.log` |
| `composer not found` | Composer belum diinstall | Ulangi Tahap 2 bagian Composer |
| Database error | Password salah di `.env` | Cek `DB_*` di `.env`, restart service |
| Upload bukti gagal | Kredensial S3 salah | Cek `AWS_*` di `.env` |
| `npm run build` error | Node version terlalu lama | Pastikan Node 22: `node --version` |
