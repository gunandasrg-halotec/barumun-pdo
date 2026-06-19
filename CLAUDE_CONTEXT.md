# CONTEXT — PDO System Development
# Untuk dilanjutkan di Claude Code (Hari 2 — Master Data CRUD)

---

## REPOSITORI

- Remote: https://github.com/gunandasrg-halotec/barumun-pdo
- Branch aktif: `main`
- Local (jika sudah clone): `barumun-pdo/`

---

## STATUS PEKERJAAN

### Hari 1 — SELESAI (sudah di-push ke GitHub)

**File yang sudah ada di repo:**
```
.github/workflows/deploy.yml          ← CI/CD GitHub Actions
README.md
docker-compose.yml                    ← PostgreSQL 15, Redis 7, Mailpit
apps/api/
  Dockerfile.dev
  .env.example
  .env.ci
  bootstrap/app.php                   ← Middleware alias + JSON exception handlers
  routes/api.php                      ← 76 endpoint sesuai API Spec v1.0
  app/Models/
    User.php
    Role.php
    Company.php
    PlantationUnit.php
    PdoHeader.php                     ← Global Scope unit access, generateNumber()
    AuditLog.php                      ← static AuditLog::append()
  app/Http/Controllers/Auth/
    AuthController.php                ← login (rate limit), logout, me, refreshToken
  app/Http/Middleware/
    EnsureUnitAccess.php              ← Row-level security per unit kebun
  app/Http/Requests/Auth/
    LoginRequest.php
  database/migrations/
    2026_06_18_000001_create_pdo_system_tables.php  ← 19 tabel, 47 index, ERD v1.2
  database/seeders/
    DatabaseSeeder.php
    RoleSeeder.php
    SystemSettingSeeder.php
    NotificationTemplateSeeder.php
apps/web/
  Dockerfile.dev
```

---

## STACK TEKNOLOGI (FINAL — tidak boleh diubah)

| Layer | Teknologi |
|---|---|
| Backend | Laravel 13 (PHP 8.4) |
| Frontend | React 19 + Vite + TypeScript |
| Database | PostgreSQL 15 |
| Cache/Queue | Redis 7 |
| File Storage | AWS S3 (ap-southeast-3) |
| Auth | Laravel Sanctum (JWT via HttpOnly cookie) |
| CI/CD | GitHub Actions |

---

## ARSITEKTUR PENTING

### Separation of Concerns
- **Controller**: hanya handle HTTP request/response, tidak ada business logic
- **Service**: semua business logic di sini
- **Model**: relasi dan query sederhana
- **Form Request**: validasi input HTTP
- **Event + Listener**: notifikasi dan audit log (decoupled)

### Row-Level Security
- `EnsureUnitAccess` middleware bind `current_unit_id` ke Laravel container
- `PdoHeader` menggunakan Eloquent Global Scope yang membaca `current_unit_id`
- KERANI dan ASISTEN_KEBUN: hanya akses unit mereka
- Role lain (MANAJER, DIREKTUR, dll.): akses semua unit

### Role Constants (gunakan dari `App\Models\Role`)
```php
Role::ADMIN, Role::KERANI, Role::ASISTEN_KEBUN,
Role::MANAJER_KEBUN, Role::MANAJER_KEUANGAN, Role::STAFF_KEUANGAN,
Role::DIREKTUR_KEUANGAN, Role::STAFF_PURCHASING
```

### Response Format Standar (wajib diikuti semua endpoint)
```php
// Sukses
return response()->json(['success' => true, 'data' => $data, 'message' => '...']);

// Sukses dengan pagination
return response()->json(['success' => true, 'data' => $items, 'meta' => [
    'current_page' => $paginator->currentPage(),
    'per_page'     => $paginator->perPage(),
    'total'        => $paginator->total(),
    'last_page'    => $paginator->lastPage(),
]]);

// Error (sudah dihandle di bootstrap/app.php untuk 401/422/403/404)
// Untuk error bisnis custom:
return response()->json(['success' => false, 'error' => [
    'code'    => 'ERROR_CODE',
    'message' => 'Pesan error',
]], 422);
```

### AuditLog
```php
AuditLog::append(
    actor: $request->user(),
    entityType: 'expense_categories',
    entityId: $category->id,
    action: 'INSERT',           // INSERT | UPDATE | DELETE | STATUS_CHANGE
    oldValues: null,
    newValues: $category->toArray()
);
```

---

## HARI 2 — YANG HARUS DIKERJAKAN: Master Data CRUD

### Target: selesai hari ini, push ke GitHub

### Struktur direktori yang harus dibuat:
```
apps/api/app/
├── Models/
│   ├── ExpenseCategory.php
│   ├── ExpenseSubcategory.php
│   └── ExpenseItem.php
├── Http/
│   ├── Controllers/MasterData/
│   │   ├── ExpenseCategoryController.php
│   │   ├── ExpenseSubcategoryController.php
│   │   └── ExpenseItemController.php
│   └── Requests/MasterData/
│       ├── StoreExpenseCategoryRequest.php
│       ├── UpdateExpenseCategoryRequest.php
│       ├── StoreExpenseSubcategoryRequest.php
│       ├── UpdateExpenseSubcategoryRequest.php
│       ├── StoreExpenseItemRequest.php
│       └── UpdateExpenseItemRequest.php
└── Services/MasterData/
    └── MasterDataService.php
```

### Endpoint yang harus diimplementasikan (dari routes/api.php yang sudah ada):

#### Expense Categories
```
GET    /api/v1/expense-categories                     → index()
POST   /api/v1/expense-categories                     → store()
GET    /api/v1/expense-categories/{id}                → show()
PUT    /api/v1/expense-categories/{id}                → update()
DELETE /api/v1/expense-categories/{id}                → destroy()
```

#### Expense Subcategories
```
GET    /api/v1/expense-subcategories?category_id=     → index()
POST   /api/v1/expense-subcategories                  → store()
GET    /api/v1/expense-subcategories/{id}             → show()
PUT    /api/v1/expense-subcategories/{id}             → update()
DELETE /api/v1/expense-subcategories/{id}             → destroy()
```

#### Expense Items
```
GET    /api/v1/expense-items?subcategory_id=&is_routine=&is_active=  → index()
GET    /api/v1/expense-items/routine                                  → routine()
POST   /api/v1/expense-items                                          → store()
GET    /api/v1/expense-items/{id}                                     → show()
PUT    /api/v1/expense-items/{id}                                     → update()
DELETE /api/v1/expense-items/{id}                                     → destroy()
```

---

## BUSINESS RULES MASTER DATA (dari BRD v1.1)

### BR-MASTER-001: Hierarki
- Kategori → Sub-Kategori → Item Biaya (3 level, tidak bisa dibalik)

### BR-MASTER-002: Integritas Referensial
- Hapus Kategori: tolak jika masih ada Sub-Kategori aktif → error `CATEGORY_HAS_CHILDREN`
- Hapus Sub-Kategori: tolak jika masih ada Item Biaya aktif → error `SUBCATEGORY_HAS_CHILDREN`

### BR-MASTER-003: Kode Unik
- Kode Kategori: unik per company
- Kode Sub-Kategori: unik per category_id
- Kode Item: unik per subcategory_id
- Error jika duplikat: `DUPLICATE_CODE`

### BR-MASTER-004: Soft Delete vs Hard Delete
- Jika entitas **belum pernah dipakai** di PDO → hard delete (hapus permanen)
- Jika **sudah pernah dipakai** di PDO → hanya soft delete (set `deleted_at`, `is_active = false`)
- Error jika mencoba hard delete entitas yang sudah dipakai: `ITEM_ALREADY_USED`

### BR-MASTER-005: Field `is_routine`
- Perubahan `is_routine` hanya berlaku untuk PDO yang dibuat SETELAH perubahan
- PDO yang sudah ada tidak terpengaruh (snapshot di `pdo_details`)

### BR-MASTER-006: Field `mode_input`
- Nilai valid: `'manual'` atau `'auto_external'`
- Item dengan `mode_input = 'auto_external'` menampilkan tombol "Ambil Biaya" di form PDO

### Akses per Role:
- ADMIN: full CRUD
- Semua role lain: hanya READ (GET)

---

## SCHEMA DATABASE TERKAIT (dari ERD v1.2)

### expense_categories
```sql
id UUID PK, company_id UUID FK, code VARCHAR(20),
name VARCHAR(255), display_order INT DEFAULT 0,
include_in_recap BOOLEAN DEFAULT TRUE, is_active BOOLEAN DEFAULT TRUE,
notes TEXT, created_at, updated_at, deleted_at
UNIQUE(company_id, code)
```

### expense_subcategories
```sql
id UUID PK, category_id UUID FK, code VARCHAR(20),
name VARCHAR(255), display_order INT DEFAULT 0,
is_active BOOLEAN DEFAULT TRUE, notes TEXT,
created_at, updated_at, deleted_at
UNIQUE(category_id, code)
```

### expense_items
```sql
id UUID PK, subcategory_id UUID FK, code VARCHAR(30),
name VARCHAR(255), default_account_number VARCHAR(50),
default_unit VARCHAR(50), default_rate BIGINT CHECK >= 0,
mode_input VARCHAR(20) CHECK IN ('manual','auto_external') DEFAULT 'manual',
is_routine BOOLEAN DEFAULT FALSE, is_active BOOLEAN DEFAULT TRUE,
notes TEXT, created_at, updated_at, deleted_at
UNIQUE(subcategory_id, code)
```

---

## ERROR CODES YANG RELEVAN (dari API Spec v1.0)

```
DUPLICATE_CODE          → 409  Kode sudah digunakan
CATEGORY_HAS_CHILDREN   → 409  Kategori masih punya sub-kategori aktif
SUBCATEGORY_HAS_CHILDREN→ 409  Sub-kategori masih punya item aktif
ITEM_ALREADY_USED       → 409  Item sudah dipakai di PDO, tidak bisa dihapus
FORBIDDEN               → 403  Role tidak punya izin
NOT_FOUND               → 404  Resource tidak ditemukan
VALIDATION_ERROR        → 422  Validasi field gagal
```

---

## CONTOH RESPONSE YANG DIHARAPKAN

### GET /api/v1/expense-categories
```json
{
  "success": true,
  "data": [
    {
      "id": "uuid",
      "code": "A",
      "name": "Gaji Staff & Pegawai Kantor",
      "display_order": 1,
      "include_in_recap": true,
      "is_active": true,
      "notes": null
    }
  ]
}
```

### GET /api/v1/expense-items/routine
```json
{
  "success": true,
  "data": [
    {
      "id": "uuid",
      "subcategory": {
        "id": "uuid",
        "code": "A1",
        "name": "Staff Kebun",
        "category": { "id": "uuid", "code": "A", "name": "Gaji Staff", "display_order": 1 }
      },
      "code": "A1-001",
      "name": "Gaji Manager",
      "default_account_number": "5101001",
      "default_unit": "Orang",
      "default_rate": 15000000,
      "mode_input": "manual",
      "is_routine": true,
      "display_order": 1
    }
  ]
}
```

---

## CATATAN PENTING UNTUK CLAUDE CODE

1. **Selalu jalankan `git add . && git commit -m "..." && git push` setelah setiap kelompok file selesai**
2. **Gunakan PAT baru untuk push** (minta ke user jika diperlukan — PAT lama sudah di-revoke)
3. **Inline comment wajib** untuk business logic yang merujuk ke BRD: contoh `// BR-MASTER-004`
4. **Tidak ada hardcoded company_id** — selalu ambil dari `$request->user()->company_id` atau relasi
5. **Soft delete menggunakan Laravel SoftDeletes trait** (`deleted_at` sudah ada di migration)
6. **Test wajib dibuat** untuk setiap Service method (minimum: positive case + negative case)

---

## HARI SELANJUTNYA SETELAH MASTER DATA

| Hari | Fitur |
|---|---|
| Hari 2 (sekarang) | Master Data CRUD |
| Hari 2-3 | User Management CRUD |
| Hari 3-5 | PDO Bulanan: buat + template otomatis + submit |
| Hari 3-5 | Approval workflow |
| Hari 5-7 | Transfer + Realisasi + upload S3 |
| Hari 7 | WhatsApp notification + reminder bulanan |
| Hari 7-8 | Dashboard + PDO Tambahan |
| Hari 9-11 | QA + UAT |
