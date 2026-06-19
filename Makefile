# ── PDO Local Development ──────────────────────────────────────────────────────

.PHONY: up down build fresh logs shell-api shell-db

## Jalankan semua container (build otomatis jika belum ada)
up:
	docker compose up -d --build
	@echo ""
	@echo "✅ Aplikasi berjalan:"
	@echo "   Frontend : http://localhost:5173"
	@echo "   API      : http://localhost:8000"
	@echo ""
	@echo "Lihat log: make logs"

## Hentikan semua container (data tetap tersimpan)
down:
	docker compose down

## Build ulang image dari awal
build:
	docker compose build --no-cache

## Reset total: hapus semua container, image, dan data database
fresh:
	docker compose down -v --rmi local
	docker compose up -d --build

## Lihat log semua container (Ctrl+C untuk keluar)
logs:
	docker compose logs -f

## Masuk ke shell container API (untuk artisan, dll)
shell-api:
	docker compose exec api sh

## Masuk ke shell PostgreSQL
shell-db:
	docker compose exec db psql -U pdo_user -d pdo_db
