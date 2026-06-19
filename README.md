# PDO System — Dana Operasional Kebun
## PT Barumun Palma Nauli

---

## Stack

| Layer | Teknologi |
|---|---|
| Backend | Laravel 13 (PHP 8.4) |
| Frontend | React 19 + Vite + TypeScript |
| Database | PostgreSQL 15 (AWS RDS) |
| Cache / Queue | Redis 7 (AWS ElastiCache) |
| File Storage | AWS S3 |
| CI/CD | GitHub Actions |

---

## Setup Development (Local)

### Prerequisites

- Docker Desktop
- Git

### Langkah 1 — Clone & Config

```bash
git clone https://github.com/barumunpalma/pdo-system.git
cd pdo-system

# Salin env files
cp apps/api/.env.example apps/api/.env
```

Edit `apps/api/.env` dan isi:
- `APP_KEY` — akan digenerate di langkah berikutnya
- `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_BUCKET` — untuk S3 (opsional di local, bisa pakai disk local)

### Langkah 2 — Jalankan Docker

```bash
docker compose up -d
```

Tunggu hingga semua container running:
```bash
docker compose ps
```

### Langkah 3 — Setup Backend

```bash
# Masuk ke container API
docker compose exec api bash

# Di dalam container:
composer install
php artisan key:generate
php artisan migrate
php artisan db:seed

# Keluar dari container
exit
```

### Langkah 4 — Setup Frontend

```bash
cd apps/web
npm install
```

### Akses Aplikasi

| Service | URL |
|---|---|
| Frontend (React) | http://localhost:5173 |
| Backend API | http://localhost:8000/api/v1 |
| Mailpit (email test) | http://localhost:8025 |
| PostgreSQL | localhost:5432 |

### Login Default

```
Email:    admin@barumunpalma.co.id
Password: ChangeMe123!
```

⚠️ **Ganti password admin segera setelah login pertama!**

---

## Struktur Monorepo

```
pdo-system/
├── apps/
│   ├── api/          ← Laravel 13 Backend
│   └── web/          ← React 19 Frontend
├── docs/             ← Dokumentasi teknis (PRD, BRD, ERD, API Spec, TAD, UI Spec)
├── docker-compose.yml
└── .github/
    └── workflows/
        └── deploy.yml
```

---

## Git Workflow

```
main        ← Production (deploy otomatis setelah manual approval)
develop     ← Staging (deploy otomatis setelah test pass)
feature/*   ← Feature branches (PR ke develop)
fix/*       ← Bug fix branches
hotfix/*    ← Critical fix (PR langsung ke main + develop)
```

### Commit Convention

```
feat(pdo): tambah template otomatis item rutin
fix(realization): perbaiki validasi kumulatif
test(approval): tambah test concurrent approval
chore: update dependencies
docs: update ERD v1.2
```

---

## Menjalankan Tests

```bash
# Backend tests
docker compose exec api php artisan test --parallel

# Dengan coverage
docker compose exec api php artisan test --coverage --min=70
```

---

## Deployment

Deployment dilakukan otomatis via GitHub Actions:

- **Push ke `develop`** → deploy ke Staging (otomatis setelah test pass)
- **Push ke `main`** → deploy ke Production (butuh manual approval di GitHub)

Sebelum deploy production, pastikan:
1. Semua test pass di Staging
2. UAT sudah dilakukan
3. RDS snapshot akan dibuat otomatis sebelum migrate

---

## Referensi Dokumen

| Dokumen | Versi | Deskripsi |
|---|---|---|
| PRD | v1.7 | Product Requirements Document |
| BRD | v1.1 | Business Rules Document |
| ERD | v1.2 | Database Schema + SQL DDL |
| API Spec | v1.0 | REST API Specification (76 endpoints) |
| TAD | v1.1 | Technical Architecture Document |
| UI Spec | v1.1 | UI/UX Specification |
