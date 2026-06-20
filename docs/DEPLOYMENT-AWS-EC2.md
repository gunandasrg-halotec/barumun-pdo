# Panduan Deployment — AWS EC2 (Docker Production)

**Stack:** Ubuntu 22.04/24.04 · Docker 26+ · Docker Compose V2 · PHP-FPM 8.4 · Laravel 13 · PostgreSQL 16 · Redis 7 · Nginx · Certbot SSL

> **Catatan arsitektur:** Deployment menggunakan Docker Compose produksi (`docker-compose.prod.yml`).
> Tidak perlu install PHP, Node, atau PostgreSQL secara manual — semua berjalan dalam container.

---

## Prasyarat

- EC2 instance berjalan (minimal **t3.small** — 2 vCPU, 2 GB RAM; disarankan **t3.medium** untuk produksi)
- Security Group membuka port **22** (SSH), **80** (HTTP), **443** (HTTPS)
- File `.pem` / key pair SSH tersedia di komputer lokal
- Record DNS domain sudah diarahkan ke IP publik EC2
- AWS S3 bucket sudah dibuat untuk upload bukti realisasi
- IAM user dengan akses S3 (butuh `ACCESS_KEY` dan `SECRET_KEY`)

---

## Tahap 1 — Masuk ke Server & Install Docker

```bash
# SSH ke server
ssh -i /path/ke/keypair.pem ubuntu@<IP_PUBLIK_EC2>

# Update sistem
sudo apt update && sudo apt upgrade -y

# Install Docker
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker ubuntu

# Logout lalu login kembali agar grup docker aktif
exit
ssh -i /path/ke/keypair.pem ubuntu@<IP_PUBLIK_EC2>

# Verifikasi
docker --version
docker compose version
```

---

## Tahap 2 — Clone Repository

```bash
cd /opt
sudo git clone https://github.com/gunandasrg-halotec/barumun-pdo.git pdo
sudo chown -R ubuntu:ubuntu /opt/pdo
cd /opt/pdo
```

---

## Tahap 3 — Konfigurasi Environment Produksi

```bash
cp apps/api/.env.example docker/api/.env.production
nano docker/api/.env.production
```

Isi semua nilai yang ditandai **WAJIB**:

```dotenv
APP_NAME="PDO Barumun Palma Nauli"
APP_ENV=production
APP_KEY=                          # WAJIB — generate: lihat langkah di bawah
APP_DEBUG=false
APP_URL=https://<DOMAIN_ANDA>
APP_TIMEZONE=Asia/Jakarta

DB_CONNECTION=pgsql
DB_HOST=db                        # nama service di docker-compose
DB_PORT=5432
DB_DATABASE=pdo_prod
DB_USERNAME=pdo_user
DB_PASSWORD=<PASSWORD_KUAT_32_KARAKTER>   # WAJIB

CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
REDIS_HOST=redis                  # nama service di docker-compose
REDIS_PORT=6379
REDIS_PASSWORD=<REDIS_PASSWORD>   # WAJIB — sama dengan nilai di .env (docker-compose)

SANCTUM_STATEFUL_DOMAINS=<DOMAIN_ANDA>
SESSION_DOMAIN=.<DOMAIN_ANDA>
SESSION_SECURE_COOKIE=true

FILESYSTEM_DISK=s3                # WAJIB untuk upload bukti transaksi
AWS_ACCESS_KEY_ID=<S3_ACCESS_KEY>       # WAJIB
AWS_SECRET_ACCESS_KEY=<S3_SECRET_KEY>   # WAJIB
AWS_DEFAULT_REGION=ap-southeast-3
AWS_BUCKET=pdo-barumunpalma-prod
AWS_URL=

LOG_CHANNEL=stderr
LOG_LEVEL=warning

MAIL_MAILER=smtp
MAIL_HOST=<SMTP_HOST>
MAIL_PORT=587
MAIL_USERNAME=<SMTP_USER>
MAIL_PASSWORD=<SMTP_PASSWORD>
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@<DOMAIN_ANDA>
MAIL_FROM_NAME="PDO Barumun Palma Nauli"

SENTRY_LARAVEL_DSN=               # Opsional: isi dari sentry.io untuk error monitoring
```

Generate `APP_KEY` (jalankan sekali saja, simpan hasilnya):

```bash
docker run --rm php:8.4-cli php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

Salin output ke nilai `APP_KEY=` di `.env.production`.

Buat file `.env` untuk variabel docker-compose:

```bash
cat > /opt/pdo/.env << 'EOF'
DB_DATABASE=pdo_prod
DB_USERNAME=pdo_user
DB_PASSWORD=<PASSWORD_KUAT_SAMA_DENGAN_DIATAS>
REDIS_PASSWORD=<REDIS_PASSWORD_SAMA_DENGAN_DIATAS>
VITE_API_URL=https://<DOMAIN_ANDA>
EOF
```

---

## Tahap 4 — Build & Jalankan Container

```bash
cd /opt/pdo

# Build semua image (pertama kali ±5–10 menit)
docker compose -f docker-compose.prod.yml build

# Jalankan semua container di background
docker compose -f docker-compose.prod.yml up -d

# Cek status
docker compose -f docker-compose.prod.yml ps
```

Output yang diharapkan:

```
NAME            STATUS              PORTS
pdo-db-1        running (healthy)   5432/tcp
pdo-redis-1     running             6379/tcp
pdo-api-1       running             0.0.0.0:8000->80/tcp
pdo-web-1       running             0.0.0.0:80->80/tcp
```

Container `api` menjalankan otomatis saat start:
- `php artisan migrate --force`
- `php artisan config:cache && route:cache && view:cache`
- PHP-FPM + Nginx (port 80)
- 2 queue workers (`exports,notifications,scheduled`)
- Laravel scheduler (loop setiap menit)

---

## Tahap 5 — Setup Nginx Host + SSL

Install Nginx dan Certbot di EC2 sebagai SSL termination:

```bash
sudo apt install -y nginx certbot python3-certbot-nginx
```

```bash
sudo nano /etc/nginx/sites-available/pdo
```

```nginx
server {
    listen 80;
    server_name <DOMAIN_ANDA> www.<DOMAIN_ANDA>;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    server_name <DOMAIN_ANDA> www.<DOMAIN_ANDA>;

    # SSL — diisi otomatis oleh Certbot
    # ssl_certificate     ...
    # ssl_certificate_key ...

    client_max_body_size 20M;

    # Frontend React SPA (container web port 80)
    location / {
        proxy_pass         http://127.0.0.1:80;
        proxy_set_header   Host $host;
        proxy_set_header   X-Real-IP $remote_addr;
        proxy_set_header   X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;
    }

    # Backend API Laravel (container api port 8000)
    location /api/ {
        proxy_pass         http://127.0.0.1:8000/api/;
        proxy_set_header   Host $host;
        proxy_set_header   X-Real-IP $remote_addr;
        proxy_set_header   X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;
        proxy_read_timeout 120s;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/pdo /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl restart nginx

# Pasang SSL certificate gratis
sudo certbot --nginx -d <DOMAIN_ANDA> -d www.<DOMAIN_ANDA>
sudo certbot renew --dry-run
```

---

## Tahap 6 — Verifikasi Deployment

```bash
# Status semua container
docker compose -f docker-compose.prod.yml ps

# Log API (lihat migrasi & startup)
docker compose -f docker-compose.prod.yml logs api --tail=50

# Test API (harus dapat HTTP 401 — bukan 404 atau 502)
curl -s -o /dev/null -w "%{http_code}" \
  -H "Accept: application/json" \
  https://<DOMAIN_ANDA>/api/v1/auth/me

# Test login
curl -s -X POST https://<DOMAIN_ANDA>/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"email":"admin@barumunpalma.co.id","password":"ChangeMe123!"}'
```

Buka browser: `https://<DOMAIN_ANDA>` — halaman login harus muncul.

**Akun default (seeder):**

| Role | Email | Password |
|------|-------|----------|
| Admin | admin@barumunpalma.co.id | ChangeMe123! |

> **Ganti password admin segera setelah pertama login.**

---

## Arsitektur Deployment

```
User Browser
     │ HTTPS (443)
     ▼
Nginx (host EC2) — SSL termination
  ├── /       → Docker pdo-web-1:80   (React SPA via Nginx container)
  └── /api/*  → Docker pdo-api-1:8000 (Laravel PHP-FPM + Nginx container)
                    │
               pdo-db-1:5432    (PostgreSQL 16)
               pdo-redis-1:6379 (Redis 7 — cache, session, queue)
                    │
               AWS S3            (upload bukti realisasi)
```

Dalam container `api` dikelola Supervisor:

| Process | Fungsi |
|---------|--------|
| `nginx` | Reverse proxy ke PHP-FPM |
| `php-fpm` | Memproses request Laravel (4–20 workers) |
| `queue-worker` × 2 | Memproses export Excel/PDF dan notifikasi |
| `scheduler` | Menjalankan `pdo:auto-close` setiap menit |

---

## Update Aplikasi (Deploy Kode Baru)

```bash
cd /opt/pdo

# Pull kode terbaru
git pull origin main

# Rebuild image dan restart container (migrasi berjalan otomatis)
docker compose -f docker-compose.prod.yml up -d --build api web

# Pantau log startup
docker compose -f docker-compose.prod.yml logs api -f
```

---

## Troubleshooting

| Gejala | Kemungkinan Penyebab | Solusi |
|--------|----------------------|--------|
| Container `api` restart loop | `APP_KEY` kosong atau `.env.production` tidak ada | `docker compose -f docker-compose.prod.yml logs api` |
| `502 Bad Gateway` | Container belum siap / crash | `docker compose -f docker-compose.prod.yml ps` — cek status |
| Upload bukti gagal | Kredensial AWS S3 salah | Cek `AWS_*` di `.env.production`, pastikan bucket sudah ada |
| Export Excel/PDF tidak diproses | Queue worker mati | `docker compose -f docker-compose.prod.yml restart api` |
| Login berhasil tapi data kosong | Seeder belum jalan | `docker compose -f docker-compose.prod.yml exec api php artisan db:seed --force` |
| Migration error saat startup | Konflik migration | `docker compose -f docker-compose.prod.yml exec api php artisan migrate:status` |
| `SQLSTATE` error saat startup | DB belum healthy saat api start | `docker compose -f docker-compose.prod.yml restart api` |
