# Panduan Deployment — AWS EC2 (Ubuntu + PostgreSQL + Nginx)

Dokumen ini berisi langkah-langkah lengkap untuk men-deploy aplikasi PDO
ke server AWS EC2 secara manual.

**Stack:** Ubuntu 22.04/24.04 · PHP 8.4 · PostgreSQL 16 · Nginx · Node 22 · Certbot SSL

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
  php8.4-intl php8.4-redis php8.4-gd

# ── Composer ─────────────────────────────────────────────
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# ── Node.js 22 ───────────────────────────────────────────
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs

# ── PostgreSQL 16 ────────────────────────────────────────
sudo apt install -y postgresql postgresql-contrib

# ── Nginx ────────────────────────────────────────────────
sudo apt install -y nginx

# ── Git ──────────────────────────────────────────────────
sudo apt install -y git
```

---

## Tahap 3 — Setup PostgreSQL

```bash
# Masuk ke shell PostgreSQL
sudo -u postgres psql
```

Jalankan perintah berikut di dalam `psql`:

```sql
CREATE USER pdo_user WITH PASSWORD 'GANTI_PASSWORD_KUAT_DISINI';
CREATE DATABASE pdo_db OWNER pdo_user;
GRANT ALL PRIVILEGES ON DATABASE pdo_db TO pdo_user;
\q
```

---

## Tahap 4 — Clone & Setup Backend (Laravel)

```bash
# Buat direktori aplikasi
sudo mkdir -p /var/www/pdo
sudo chown ubuntu:ubuntu /var/www/pdo

# Clone repository
cd /var/www/pdo
git clone https://github.com/gunandasrg-halotec/barumun-pdo.git .

# Masuk ke folder API
cd /var/www/pdo/apps/api

# Install PHP dependencies (tanpa paket dev)
composer install --no-dev --optimize-autoloader

# Salin file konfigurasi
cp .env.example .env
```

### Edit file `.env`

```bash
nano .env
```

Isi nilai-nilai berikut (ganti semua yang dalam `<...>`):

```dotenv
APP_NAME="PDO Barumun"
APP_ENV=production
APP_KEY=                            # akan di-generate di langkah berikut
APP_DEBUG=false
APP_URL=https://<DOMAIN_ANDA>

LOG_CHANNEL=daily
LOG_LEVEL=error

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

WA_GATEWAY_URL=
WA_API_KEY=

SANCTUM_STATEFUL_DOMAINS=<DOMAIN_ANDA>
SESSION_DOMAIN=.<DOMAIN_ANDA>
```

### Inisialisasi Aplikasi

```bash
# Generate APP_KEY
php artisan key:generate

# Jalankan migrasi database dan seeder data awal
php artisan migrate --seed --force

# Optimasi untuk production
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Set permission folder storage dan cache
sudo chown -R www-data:www-data /var/www/pdo/apps/api/storage
sudo chown -R www-data:www-data /var/www/pdo/apps/api/bootstrap/cache
sudo chmod -R 775 /var/www/pdo/apps/api/storage
sudo chmod -R 775 /var/www/pdo/apps/api/bootstrap/cache
```

---

## Tahap 5 — Build Frontend (React)

```bash
cd /var/www/pdo/apps/web

# Install dependencies
npm install

# Build untuk production
npm run build
# Hasil build tersimpan di: /var/www/pdo/apps/web/dist/
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

    # Redirect semua HTTP ke HTTPS
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    server_name <DOMAIN_ANDA> www.<DOMAIN_ANDA>;

    # SSL — akan diisi otomatis oleh Certbot (Tahap 7)
    # ssl_certificate ...
    # ssl_certificate_key ...

    # Frontend React SPA
    root /var/www/pdo/apps/web/dist;
    index index.html;

    location / {
        try_files $uri $uri/ /index.html;
    }

    # Backend API Laravel
    location /api {
        alias /var/www/pdo/apps/api/public;
        try_files $uri $uri/ @laravel;

        location ~ \.php$ {
            fastcgi_pass unix:/run/php/php8.4-fpm.sock;
            fastcgi_param SCRIPT_FILENAME /var/www/pdo/apps/api/public$fastcgi_script_name;
            include fastcgi_params;
        }
    }

    location @laravel {
        rewrite /api/(.*)$ /index.php?/$1 last;
    }

    # Batas ukuran upload (10 MB untuk bukti realisasi)
    client_max_body_size 10M;

    # Kompresi Gzip
    gzip on;
    gzip_types text/plain text/css application/json application/javascript text/xml application/xml;
}
```

```bash
# Aktifkan konfigurasi site
sudo ln -s /etc/nginx/sites-available/pdo /etc/nginx/sites-enabled/

# Hapus default site
sudo rm /etc/nginx/sites-enabled/default

# Test konfigurasi Nginx
sudo nginx -t

# Restart Nginx
sudo systemctl restart nginx
```

---

## Tahap 7 — SSL Certificate (HTTPS Gratis via Let's Encrypt)

```bash
# Install Certbot
sudo apt install -y certbot python3-certbot-nginx

# Dapatkan dan pasang sertifikat SSL
sudo certbot --nginx -d <DOMAIN_ANDA> -d www.<DOMAIN_ANDA>
# Ikuti instruksi: masukkan email, setujui ToS, pilih opsi redirect ke HTTPS

# Verifikasi auto-renewal berjalan
sudo certbot renew --dry-run
```

Certbot akan otomatis memperbarui sertifikat sebelum kadaluarsa (setiap 90 hari).

---

## Tahap 8 — Setup Cron untuk Scheduler Laravel

Scheduler digunakan untuk pengiriman reminder WhatsApp otomatis.

```bash
sudo crontab -e -u www-data
```

Tambahkan baris ini di akhir file:

```
* * * * * cd /var/www/pdo/apps/api && php artisan schedule:run >> /dev/null 2>&1
```

---

## Tahap 9 — Verifikasi Deployment

```bash
# Cek semua service berjalan
sudo systemctl status nginx
sudo systemctl status php8.4-fpm
sudo systemctl status postgresql

# Test API Laravel merespons
curl https://<DOMAIN_ANDA>/api/ping

# Pantau log jika ada error
tail -f /var/www/pdo/apps/api/storage/logs/laravel.log
tail -f /var/log/nginx/error.log
```

Buka browser dan akses `https://<DOMAIN_ANDA>` — halaman login PDO harus muncul.

**Akun default setelah seeder:**
| Role | Email | Password |
|---|---|---|
| Admin | admin@barumun.com | password |
| Kerani | kerani@barumun.com | password |

> **Ganti semua password default segera setelah pertama login.**

---

## Arsitektur Deployment

```
User Browser
     │ HTTPS (443)
     ▼
  Nginx
  ├── /          → /var/www/pdo/apps/web/dist/   (React SPA)
  └── /api/*     → PHP-FPM 8.4 → Laravel API
                                      │
                               PostgreSQL 16
                               (localhost:5432)
                                      │
                               AWS S3
                               (upload bukti realisasi)
```

---

## Update Aplikasi (Setelah Kode Baru di-Push)

Setiap kali ada perubahan kode, jalankan skrip berikut di server:

```bash
cd /var/www/pdo

# Pull kode terbaru dari GitHub
git pull origin main

# Update backend
cd apps/api
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Update frontend
cd ../web
npm install
npm run build

# Restart service
sudo systemctl restart php8.4-fpm
sudo systemctl reload nginx
```

---

## Troubleshooting Umum

| Gejala | Kemungkinan Penyebab | Solusi |
|---|---|---|
| `502 Bad Gateway` | PHP-FPM tidak berjalan | `sudo systemctl restart php8.4-fpm` |
| `403 Forbidden` | Permission folder salah | Ulangi langkah `chown` dan `chmod` di Tahap 4 |
| `500 Server Error` | Error di Laravel | Cek `storage/logs/laravel.log` |
| API tidak merespons | Konfigurasi Nginx salah | Jalankan `sudo nginx -t` untuk debug |
| Database connection error | Kredensial `.env` salah | Verifikasi isi `.env` bagian `DB_*` |
| Upload bukti gagal | Kredensial S3 salah | Verifikasi `AWS_*` di `.env` |
