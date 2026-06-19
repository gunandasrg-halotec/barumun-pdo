# REST API Specification
# Sistem Dana Operasional Kebun (PDO)
# PT Barumun Palma Nauli

---

| Atribut | Detail |
|---|---|
| Versi | 1.0 |
| Tanggal | 18 Juni 2026 |
| Referensi PRD | v1.7 |
| Referensi BRD | v1.1 |
| Referensi ERD | v1.1 |
| Base URL | `https://api.pdo.barumunpalma.co.id` |
| API Version | `/api/v1` |

---

## Daftar Isi

1. Konvensi Umum
2. Daftar Error Codes
3. Autentikasi
4. Users & Roles
5. Master Data — Kategori
6. Master Data — Sub-Kategori
7. Master Data — Item Biaya
8. PDO Bulanan
9. Transfer Dana
10. Penutupan PDO
11. PDO Tambahan
12. Realisasi Dana
13. Dashboard & Laporan
14. Pengaturan Sistem

---

## 1. Konvensi Umum

### 1.1 Format Response Sukses

```json
{
  "success": true,
  "data": { },
  "message": "Pesan sukses"
}
```

Untuk response dengan list + pagination:
```json
{
  "success": true,
  "data": [ ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 150,
    "last_page": 8
  }
}
```

### 1.2 Format Response Error

```json
{
  "success": false,
  "error": {
    "code": "ERROR_CODE",
    "message": "Deskripsi error dalam Bahasa Indonesia",
    "details": [
      { "field": "nama_field", "message": "Pesan validasi spesifik" }
    ]
  }
}
```

`details` hanya ada untuk error validasi (HTTP 422). Untuk error lain, `details` tidak disertakan.

### 1.3 Autentikasi

Semua endpoint membutuhkan header:
```
Authorization: Bearer <jwt_token>
```

Pengecualian: `POST /api/v1/auth/login`

### 1.4 Format Data

| Tipe | Format | Contoh |
|------|--------|--------|
| Tanggal + waktu | ISO 8601 | `2026-06-18T09:30:00+07:00` |
| Tanggal saja | YYYY-MM-DD | `2026-06-18` |
| Periode PDO | YYYY-MM | `2026-06` |
| Nominal Rupiah | Integer (satuan Rupiah) | `5000000` |
| UUID | RFC 4122 | `550e8400-e29b-41d4-a716-446655440000` |

### 1.5 HTTP Status Codes

| Kode | Makna |
|------|-------|
| 200 | OK — Request berhasil |
| 201 | Created — Resource berhasil dibuat |
| 400 | Bad Request — Format request tidak valid |
| 401 | Unauthorized — Token tidak ada atau tidak valid |
| 403 | Forbidden — Token valid tapi tidak punya izin |
| 404 | Not Found — Resource tidak ditemukan |
| 409 | Conflict — Resource sudah ada (duplikasi) |
| 422 | Unprocessable Entity — Validasi bisnis gagal |
| 429 | Too Many Requests — Rate limit terlampaui |
| 500 | Internal Server Error |

### 1.6 Matriks Akses Role

| Role Code | Binding Unit | Akses Data |
|-----------|:---:|---|
| `ADMIN` | — | Semua unit |
| `KERANI` | Ya | Unit sendiri |
| `ASISTEN_KEBUN` | Ya | Unit sendiri |
| `MANAJER_KEBUN` | — (HO) | Semua unit |
| `MANAJER_KEUANGAN` | — (HO) | Semua unit |
| `STAFF_KEUANGAN` | — (HO) | Semua unit |
| `DIREKTUR_KEUANGAN` | — (HO) | Semua unit |
| `STAFF_PURCHASING` | — (HO) | Semua unit |

---

## 2. Daftar Error Codes

| Code | HTTP | Deskripsi |
|------|------|-----------|
| `AUTHENTICATION_FAILED` | 401 | Email atau password salah |
| `TOKEN_EXPIRED` | 401 | JWT token sudah kedaluwarsa |
| `TOKEN_INVALID` | 401 | JWT token tidak valid atau malformed |
| `FORBIDDEN` | 403 | Role tidak memiliki izin untuk aksi ini |
| `NOT_FOUND` | 404 | Resource tidak ditemukan |
| `VALIDATION_ERROR` | 422 | Satu atau lebih field gagal validasi |
| `PDO_ALREADY_EXISTS` | 409 | PDO Bulanan untuk unit + periode ini sudah ada |
| `PDO_NOT_FINAL` | 422 | PDO belum berstatus Final, tidak bisa dicatat realisasinya |
| `PDO_IS_CLOSED` | 422 | PDO sudah Closed, tidak ada operasi tulis yang diizinkan |
| `PDO_NOT_DRAFT` | 422 | PDO bukan Draft, tidak bisa diedit |
| `APPROVAL_SEQUENCE_VIOLATION` | 422 | Approval tidak sesuai urutan tahap yang berlaku |
| `SELF_APPROVAL_NOT_ALLOWED` | 403 | Tidak bisa approve PDO yang dibuat sendiri |
| `MANAGER_APPROVAL_INCOMPLETE` | 422 | Kedua Manajer belum approve, PDO belum bisa lanjut ke Direktur |
| `REALIZATION_LIMIT_EXCEEDED` | 422 | Total realisasi PDO akan melebihi total transfer PDO |
| `REALIZATION_FUNDING_SOURCE_INVALID` | 403 | Staff Purchasing hanya boleh memilih sumber dana Rekening Utama |
| `ITEM_ALREADY_USED` | 409 | Item biaya sudah dipakai di PDO, tidak bisa dihapus |
| `ITEM_NOT_ACTIVE` | 422 | Item biaya nonaktif tidak bisa digunakan di PDO baru |
| `SUPPLEMENTARY_NO_PARENT` | 422 | PDO Bulanan induk belum Final atau sudah Closed |
| `SUPPLEMENTARY_PARENT_CLOSED` | 422 | PDO Bulanan induk sudah Closed, tidak bisa buat PDO Tambahan |
| `TRANSFER_NOT_ALLOWED` | 422 | Transfer hanya bisa dicatat saat PDO berstatus Final |
| `TRANSFER_CANNOT_DELETE` | 422 | Entri transfer tidak bisa dihapus, gunakan koreksi |
| `PERIOD_IN_FUTURE` | 422 | Periode PDO tidak boleh di masa depan |
| `CATEGORY_HAS_CHILDREN` | 409 | Kategori masih punya sub-kategori aktif, tidak bisa dihapus |
| `SUBCATEGORY_HAS_CHILDREN` | 409 | Sub-kategori masih punya item biaya aktif, tidak bisa dihapus |
| `DUPLICATE_CODE` | 409 | Kode sudah digunakan |
| `FILE_TOO_LARGE` | 422 | Ukuran file melebihi 5 MB |
| `FILE_TYPE_INVALID` | 422 | Tipe file tidak didukung (hanya PDF, JPG, PNG) |
| `RATE_LIMIT_EXCEEDED` | 429 | Terlalu banyak request dalam waktu singkat |

---

## 3. Autentikasi

### POST /api/v1/auth/login

**Deskripsi:** Login dengan email dan password. Mengembalikan JWT access token dan refresh token.

**Authorization:** Tidak diperlukan

**Request Body:**
```json
{
  "email": "rika.aprillia@barumunpalma.co.id",
  "password": "password123"
}
```

**Response 200:**
```json
{
  "success": true,
  "data": {
    "access_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
    "refresh_token": "dGhpcyBpcyBhIHJlZnJlc2ggdG9rZW4...",
    "token_type": "Bearer",
    "expires_in": 3600,
    "user": {
      "id": "550e8400-e29b-41d4-a716-446655440000",
      "full_name": "Rika Aprillia",
      "email": "rika.aprillia@barumunpalma.co.id",
      "role": {
        "id": "role-uuid",
        "name": "Kerani",
        "code": "KERANI"
      },
      "plantation_unit": {
        "id": "unit-uuid",
        "code": "KP",
        "name": "Kebun Kota Pinang"
      }
    }
  },
  "message": "Login berhasil"
}
```

**Response 401:**
```json
{
  "success": false,
  "error": {
    "code": "AUTHENTICATION_FAILED",
    "message": "Email atau password tidak valid"
  }
}
```

**Business Rules:**
- Rate limit: maksimum 5 percobaan gagal per IP per 15 menit
- Login gagal dicatat di audit log
- `plantation_unit` bernilai `null` untuk role lintas unit (Manajer Kebun, Manajer Keuangan, dll.)

---

### POST /api/v1/auth/logout

**Deskripsi:** Invalidasi token aktif.

**Authorization:** Bearer token

**Request Body:** Kosong

**Response 200:**
```json
{
  "success": true,
  "message": "Logout berhasil"
}
```

---

### POST /api/v1/auth/refresh-token

**Deskripsi:** Memperbarui access token menggunakan refresh token.

**Authorization:** Tidak diperlukan (gunakan refresh_token di body)

**Request Body:**
```json
{
  "refresh_token": "dGhpcyBpcyBhIHJlZnJlc2ggdG9rZW4..."
}
```

**Response 200:**
```json
{
  "success": true,
  "data": {
    "access_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
    "expires_in": 3600
  }
}
```

---

### GET /api/v1/auth/me

**Deskripsi:** Mendapatkan profil user yang sedang login.

**Authorization:** Bearer token (semua role)

**Response 200:**
```json
{
  "success": true,
  "data": {
    "id": "550e8400-e29b-41d4-a716-446655440000",
    "full_name": "Rika Aprillia",
    "email": "rika.aprillia@barumunpalma.co.id",
    "whatsapp_number": "628123456789",
    "is_active": true,
    "role": {
      "id": "role-uuid",
      "name": "Kerani",
      "code": "KERANI"
    },
    "plantation_unit": {
      "id": "unit-uuid",
      "code": "KP",
      "name": "Kebun Kota Pinang"
    },
    "last_login_at": "2026-06-18T09:30:00+07:00"
  }
}
```

---

## 4. Users & Roles

### GET /api/v1/users

**Deskripsi:** Daftar semua user.

**Authorization:** `ADMIN`

**Query Parameters:**

| Parameter | Tipe | Wajib | Deskripsi |
|-----------|------|-------|-----------|
| `role_code` | string | Tidak | Filter berdasarkan role (KERANI, ASISTEN_KEBUN, dll.) |
| `plantation_unit_id` | UUID | Tidak | Filter berdasarkan unit kebun |
| `is_active` | boolean | Tidak | Filter status aktif (default: true) |
| `page` | integer | Tidak | Halaman (default: 1) |
| `per_page` | integer | Tidak | Jumlah per halaman (default: 20, max: 100) |

**Response 200:**
```json
{
  "success": true,
  "data": [
    {
      "id": "550e8400-e29b-41d4-a716-446655440000",
      "full_name": "Rika Aprillia",
      "email": "rika.aprillia@barumunpalma.co.id",
      "whatsapp_number": "628123456789",
      "is_active": true,
      "role": { "id": "role-uuid", "name": "Kerani", "code": "KERANI" },
      "plantation_unit": { "id": "unit-uuid", "code": "KP", "name": "Kebun Kota Pinang" },
      "created_at": "2026-01-01T00:00:00+07:00"
    }
  ],
  "meta": { "current_page": 1, "per_page": 20, "total": 45, "last_page": 3 }
}
```

---

### POST /api/v1/users

**Deskripsi:** Membuat user baru.

**Authorization:** `ADMIN`

**Request Body:**
```json
{
  "full_name": "Budi Santoso",
  "email": "budi.santoso@barumunpalma.co.id",
  "password": "SecurePass123!",
  "whatsapp_number": "628234567890",
  "role_id": "role-uuid-asisten",
  "plantation_unit_id": "unit-uuid-kp"
}
```

**Validasi:**
- `email` harus unik di sistem
- `whatsapp_number` wajib diisi, format: 628xxxxxxxxx (tanpa +, tanpa spasi)
- `plantation_unit_id` wajib untuk role KERANI dan ASISTEN_KEBUN
- `plantation_unit_id` harus null untuk role lintas unit (MANAJER_KEBUN, MANAJER_KEUANGAN, dll.)
- `password` minimal 8 karakter

**Response 201:**
```json
{
  "success": true,
  "data": {
    "id": "new-user-uuid",
    "full_name": "Budi Santoso",
    "email": "budi.santoso@barumunpalma.co.id",
    "whatsapp_number": "628234567890",
    "is_active": true,
    "role": { "id": "role-uuid-asisten", "name": "Asisten Kebun", "code": "ASISTEN_KEBUN" },
    "plantation_unit": { "id": "unit-uuid-kp", "code": "KP", "name": "Kebun Kota Pinang" },
    "created_at": "2026-06-18T10:00:00+07:00"
  },
  "message": "User berhasil dibuat"
}
```

---

### GET /api/v1/users/{id}

**Deskripsi:** Detail satu user.

**Authorization:** `ADMIN`

**Path Parameters:** `id` (UUID)

**Response 200:** Struktur sama dengan satu objek di GET /api/v1/users

**Response 404:**
```json
{
  "success": false,
  "error": { "code": "NOT_FOUND", "message": "User tidak ditemukan" }
}
```

---

### PUT /api/v1/users/{id}

**Deskripsi:** Update data user.

**Authorization:** `ADMIN`

**Request Body:** Field yang ingin diubah (partial update diizinkan):
```json
{
  "full_name": "Budi Santoso Baru",
  "whatsapp_number": "628234567891",
  "is_active": false
}
```

**Business Rules:**
- Password tidak bisa diubah via endpoint ini — gunakan endpoint reset password terpisah
- Role user aktif yang sudah approve PDO tidak bisa diubah

**Response 200:**
```json
{
  "success": true,
  "data": { "...user object..." },
  "message": "User berhasil diperbarui"
}
```

---

### DELETE /api/v1/users/{id}

**Deskripsi:** Soft-delete (nonaktifkan) user.

**Authorization:** `ADMIN`

**Business Rules:**
- Mengubah `is_active = false` dan mengisi `deleted_at`
- User yang sudah di-delete tidak bisa login
- Data historis user (approval log, realisasi) tetap tersimpan

**Response 200:**
```json
{
  "success": true,
  "message": "User berhasil dinonaktifkan"
}
```

---

### GET /api/v1/roles

**Deskripsi:** Daftar semua role yang tersedia.

**Authorization:** `ADMIN`

**Response 200:**
```json
{
  "success": true,
  "data": [
    { "id": "uuid-1", "name": "Admin", "code": "ADMIN", "description": "Kelola user dan master data" },
    { "id": "uuid-2", "name": "Kerani", "code": "KERANI", "description": "Buat PDO dan catat realisasi" },
    { "id": "uuid-3", "name": "Asisten Kebun", "code": "ASISTEN_KEBUN", "description": "Approval pertama PDO" },
    { "id": "uuid-4", "name": "Manajer Kebun", "code": "MANAJER_KEBUN", "description": "Review PDO, HO lintas unit" },
    { "id": "uuid-5", "name": "Manajer Keuangan", "code": "MANAJER_KEUANGAN", "description": "Review PDO dan catat transfer" },
    { "id": "uuid-6", "name": "Staff Keuangan", "code": "STAFF_KEUANGAN", "description": "Catat transfer dana" },
    { "id": "uuid-7", "name": "Direktur Keuangan", "code": "DIREKTUR_KEUANGAN", "description": "Final approval PDO" },
    { "id": "uuid-8", "name": "Staff Purchasing", "code": "STAFF_PURCHASING", "description": "Catat realisasi rekening utama" }
  ]
}
```

---

## 5. Master Data — Kategori Biaya

### GET /api/v1/expense-categories

**Deskripsi:** Daftar kategori biaya.

**Authorization:** Semua role

**Query Parameters:**

| Parameter | Tipe | Wajib | Deskripsi |
|-----------|------|-------|-----------|
| `is_active` | boolean | Tidak | Default: true |
| `include_in_recap` | boolean | Tidak | Filter kategori yang masuk rekap |
| `with_children` | boolean | Tidak | Sertakan sub-kategori (default: false) |

**Response 200:**
```json
{
  "success": true,
  "data": [
    {
      "id": "cat-uuid-a",
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

---

### POST /api/v1/expense-categories

**Deskripsi:** Tambah kategori biaya baru.

**Authorization:** `ADMIN`

**Request Body:**
```json
{
  "code": "J",
  "name": "Biaya Keamanan",
  "display_order": 10,
  "include_in_recap": true,
  "is_active": true,
  "notes": "Biaya satpam dan CCTV"
}
```

**Validasi:**
- `code` harus unik per perusahaan
- `code` dan `name` wajib diisi

**Response 201:**
```json
{
  "success": true,
  "data": {
    "id": "cat-uuid-j",
    "code": "J",
    "name": "Biaya Keamanan",
    "display_order": 10,
    "include_in_recap": true,
    "is_active": true,
    "notes": "Biaya satpam dan CCTV",
    "created_at": "2026-06-18T10:00:00+07:00"
  },
  "message": "Kategori berhasil ditambahkan"
}
```

**Response 409 (duplikat kode):**
```json
{
  "success": false,
  "error": {
    "code": "DUPLICATE_CODE",
    "message": "Kode kategori 'J' sudah digunakan"
  }
}
```

---

### GET /api/v1/expense-categories/{id}

**Authorization:** Semua role

**Response 200:** Objek kategori lengkap + array `subcategories` jika `with_children=true`

---

### PUT /api/v1/expense-categories/{id}

**Authorization:** `ADMIN`

**Request Body:** Field yang ingin diubah

**Business Rules:**
- `code` tidak bisa diubah jika kategori sudah pernah dipakai di PDO

---

### DELETE /api/v1/expense-categories/{id}

**Authorization:** `ADMIN`

**Business Rules:**
- Jika masih ada sub-kategori aktif → error `CATEGORY_HAS_CHILDREN`
- Jika pernah dipakai di PDO → hanya soft delete (`is_active = false`), tidak bisa hard delete
- Jika belum pernah dipakai → hard delete

**Response 409:**
```json
{
  "success": false,
  "error": {
    "code": "CATEGORY_HAS_CHILDREN",
    "message": "Kategori tidak bisa dihapus karena masih memiliki sub-kategori aktif"
  }
}
```

---

## 6. Master Data — Sub-Kategori Biaya

### GET /api/v1/expense-subcategories

**Authorization:** Semua role

**Query Parameters:**

| Parameter | Tipe | Wajib | Deskripsi |
|-----------|------|-------|-----------|
| `category_id` | UUID | Tidak | Filter berdasarkan kategori induk |
| `is_active` | boolean | Tidak | Default: true |
| `with_items` | boolean | Tidak | Sertakan item biaya (default: false) |

**Response 200:**
```json
{
  "success": true,
  "data": [
    {
      "id": "subcat-uuid",
      "category_id": "cat-uuid-a",
      "category": { "id": "cat-uuid-a", "code": "A", "name": "Gaji Staff & Pegawai Kantor" },
      "code": "A1",
      "name": "Staff Kebun",
      "display_order": 1,
      "is_active": true,
      "notes": null
    }
  ]
}
```

---

### POST /api/v1/expense-subcategories

**Authorization:** `ADMIN`

**Request Body:**
```json
{
  "category_id": "cat-uuid-a",
  "code": "A3",
  "name": "Tenaga Kontrak",
  "display_order": 3,
  "is_active": true,
  "notes": null
}
```

**Validasi:** `code` unik per `category_id`

---

### PUT /api/v1/expense-subcategories/{id}

**Authorization:** `ADMIN`

---

### DELETE /api/v1/expense-subcategories/{id}

**Authorization:** `ADMIN`

**Business Rules:**
- Jika masih ada item biaya aktif → error `SUBCATEGORY_HAS_CHILDREN`
- Jika pernah dipakai di PDO → soft delete saja

---

## 7. Master Data — Item Biaya

### GET /api/v1/expense-items

**Authorization:** Semua role

**Query Parameters:**

| Parameter | Tipe | Wajib | Deskripsi |
|-----------|------|-------|-----------|
| `subcategory_id` | UUID | Tidak | Filter berdasarkan sub-kategori |
| `category_id` | UUID | Tidak | Filter berdasarkan kategori |
| `is_routine` | boolean | Tidak | Filter item rutin saja |
| `is_active` | boolean | Tidak | Default: true |
| `mode_input` | string | Tidak | `manual` atau `auto_external` |

**Response 200:**
```json
{
  "success": true,
  "data": [
    {
      "id": "item-uuid",
      "subcategory": { "id": "subcat-uuid", "code": "A1", "name": "Staff Kebun" },
      "code": "A1-001",
      "name": "Gaji Manager",
      "default_account_number": "5101001",
      "default_unit": "Orang",
      "default_rate": 15000000,
      "mode_input": "manual",
      "is_routine": true,
      "is_active": true,
      "notes": null
    }
  ]
}
```

---

### GET /api/v1/expense-items/routine

**Deskripsi:** Daftar item biaya rutin aktif — digunakan backend untuk mengisi template PDO Bulanan baru. Diurutkan berdasarkan hierarki Kategori → Sub-Kategori → Item.

**Authorization:** `KERANI`, `ADMIN`

**Query Parameters:** Tidak ada (selalu mengembalikan semua item rutin aktif)

**Response 200:**
```json
{
  "success": true,
  "data": [
    {
      "id": "item-uuid-a1001",
      "subcategory": {
        "id": "subcat-uuid-a1",
        "code": "A1",
        "name": "Staff Kebun",
        "category": { "id": "cat-uuid-a", "code": "A", "name": "Gaji Staff & Pegawai Kantor", "display_order": 1 }
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

### POST /api/v1/expense-items

**Authorization:** `ADMIN`

**Request Body:**
```json
{
  "subcategory_id": "subcat-uuid-e1",
  "code": "E1-005",
  "name": "Pelumas Mesin",
  "default_account_number": "5105005",
  "default_unit": "Liter",
  "default_rate": 45000,
  "mode_input": "manual",
  "is_routine": false,
  "is_active": true,
  "notes": "Oli dan pelumas untuk kendaraan operasional"
}
```

**Validasi:**
- `code` unik per `subcategory_id`
- `mode_input` hanya menerima `manual` atau `auto_external`
- `default_rate` harus >= 0

---

### PUT /api/v1/expense-items/{id}

**Authorization:** `ADMIN`

**Business Rules:**
- Perubahan `is_routine` hanya berlaku untuk PDO yang dibuat setelah perubahan ini
- Perubahan `default_rate` tidak memengaruhi `pdo_details.rate` yang sudah tersimpan

---

### DELETE /api/v1/expense-items/{id}

**Authorization:** `ADMIN`

**Business Rules:**
- Jika item sudah pernah dipakai di PDO → error `ITEM_ALREADY_USED`, hanya bisa soft delete via `PUT` (ubah `is_active = false`)
- Jika belum pernah dipakai → hard delete

**Response 409:**
```json
{
  "success": false,
  "error": {
    "code": "ITEM_ALREADY_USED",
    "message": "Item biaya sudah pernah digunakan dalam PDO dan tidak dapat dihapus. Gunakan nonaktifkan untuk menyembunyikannya."
  }
}
```

## 8. PDO Bulanan

### GET /api/v1/pdo

**Deskripsi:** Daftar PDO Bulanan dengan filter dan pagination.

**Authorization:** Semua role. Kerani dan Asisten Kebun hanya melihat PDO unit mereka sendiri. Role lintas unit melihat semua.

**Query Parameters:**

| Parameter | Tipe | Wajib | Deskripsi |
|-----------|------|-------|-----------|
| `unit_id` | UUID | Tidak | Filter berdasarkan unit kebun |
| `period` | string | Tidak | Filter periode (format: YYYY-MM, contoh: 2026-06) |
| `period_year` | integer | Tidak | Filter tahun |
| `period_month` | integer | Tidak | Filter bulan (1-12) |
| `status` | string | Tidak | Filter status: `draft`, `submitted`, `reviewed_asisten`, `in_review_manager`, `in_review_direktur`, `final`, `closed` |
| `page` | integer | Tidak | Default: 1 |
| `per_page` | integer | Tidak | Default: 20, max: 100 |
| `sort` | string | Tidak | `created_at_desc` (default), `period_desc`, `period_asc` |

**Response 200:**
```json
{
  "success": true,
  "data": [
    {
      "id": "pdo-uuid-001",
      "pdo_number": "PDO-2026-06-KP-001",
      "plantation_unit": { "id": "unit-uuid-kp", "code": "KP", "name": "Kebun Kota Pinang" },
      "period_month": 6,
      "period_year": 2026,
      "period_label": "Juni 2026",
      "submission_date": "2026-06-01",
      "status": "final",
      "total_amount": 125000000,
      "total_transfer": 120000000,
      "total_realization": 95000000,
      "saldo": 25000000,
      "created_by": { "id": "user-uuid", "full_name": "Rika Aprillia" },
      "created_at": "2026-06-01T08:00:00+07:00",
      "closed_at": null
    }
  ],
  "meta": { "current_page": 1, "per_page": 20, "total": 12, "last_page": 1 }
}
```

---

### POST /api/v1/pdo ⭐

**Deskripsi:** Membuat PDO Bulanan baru. Sistem otomatis mengisi template dengan item biaya rutin aktif.

**Authorization:** `KERANI`

**Request Body:**
```json
{
  "period_month": 6,
  "period_year": 2026,
  "submission_date": "2026-06-01",
  "notes": null
}
```

**Business Rules:**
1. Sistem memeriksa keberadaan PDO untuk `plantation_unit_id` user + periode. Jika ada → error `PDO_ALREADY_EXISTS`
2. Periode tidak boleh di masa depan (> bulan berjalan) → error `PERIOD_IN_FUTURE`
3. Sistem mengambil semua `expense_items` dengan `is_routine = true` dan `is_active = true`, diurutkan berdasarkan hierarki, lalu membuat baris `pdo_details` secara otomatis dengan snapshot `account_number`, `unit`, `rate` dari master
4. Nomor PDO dibuat otomatis: `PDO-{YYYY}-{MM}-{KODE_UNIT}-{NOMOR_URUT}`
5. `plantation_unit_id` diambil dari profil user yang login (tidak perlu di-pass di body)

**Response 201 (dengan template terisi):**
```json
{
  "success": true,
  "data": {
    "id": "pdo-uuid-baru",
    "pdo_number": "PDO-2026-06-KP-001",
    "plantation_unit": { "id": "unit-uuid-kp", "code": "KP", "name": "Kebun Kota Pinang" },
    "period_month": 6,
    "period_year": 2026,
    "period_label": "Juni 2026",
    "submission_date": "2026-06-01",
    "status": "draft",
    "total_amount": 0,
    "created_by": { "id": "user-uuid", "full_name": "Rika Aprillia" },
    "created_at": "2026-06-01T08:00:00+07:00",
    "details": [
      {
        "id": "detail-uuid-001",
        "expense_item": {
          "id": "item-uuid-a1001",
          "code": "A1-001",
          "name": "Gaji Manager",
          "mode_input": "manual"
        },
        "account_number": "5101001",
        "description": "",
        "quantity": null,
        "unit": "Orang",
        "rate": 15000000,
        "amount": 0,
        "notes": null,
        "display_order": 1,
        "source_pdo_supplementary_id": null
      },
      {
        "id": "detail-uuid-002",
        "expense_item": {
          "id": "item-uuid-e1001",
          "code": "E1-001",
          "name": "BBM Solar",
          "mode_input": "auto_external"
        },
        "account_number": "5105001",
        "description": "",
        "quantity": null,
        "unit": "Liter",
        "rate": 0,
        "amount": 0,
        "notes": null,
        "display_order": 8
      }
    ]
  },
  "message": "PDO Bulanan berhasil dibuat dengan 12 item template"
}
```

**Response 409:**
```json
{
  "success": false,
  "error": {
    "code": "PDO_ALREADY_EXISTS",
    "message": "PDO Bulanan untuk unit Kebun Kota Pinang periode Juni 2026 sudah ada: PDO-2026-06-KP-001"
  }
}
```

---

### GET /api/v1/pdo/{id}

**Deskripsi:** Detail lengkap PDO beserta semua baris item dan agregasi transfer/realisasi.

**Authorization:** Semua role (dengan pembatasan unit untuk KERANI dan ASISTEN)

**Response 200:**
```json
{
  "success": true,
  "data": {
    "id": "pdo-uuid-001",
    "pdo_number": "PDO-2026-06-KP-001",
    "plantation_unit": { "id": "unit-uuid-kp", "code": "KP", "name": "Kebun Kota Pinang" },
    "period_month": 6,
    "period_year": 2026,
    "period_label": "Juni 2026",
    "submission_date": "2026-06-01",
    "status": "final",
    "notes": null,
    "total_amount": 125000000,
    "total_transfer": 120000000,
    "total_realization": 95000000,
    "saldo_pdo": 25000000,
    "realization_percentage": 79.17,
    "closed_at": null,
    "closure_type": null,
    "created_by": { "id": "user-uuid", "full_name": "Rika Aprillia" },
    "created_at": "2026-06-01T08:00:00+07:00",
    "updated_at": "2026-06-05T10:30:00+07:00",
    "details": [
      {
        "id": "detail-uuid-001",
        "expense_item": {
          "id": "item-uuid-a1001",
          "code": "A1-001",
          "name": "Gaji Manager",
          "mode_input": "manual",
          "subcategory": {
            "id": "subcat-uuid-a1",
            "code": "A1",
            "name": "Staff Kebun",
            "category": { "id": "cat-uuid-a", "code": "A", "name": "Gaji Staff & Pegawai Kantor" }
          }
        },
        "account_number": "5101001",
        "description": "Gaji Manager Kebun bulan Juni",
        "quantity": 1,
        "unit": "Orang",
        "rate": 15000000,
        "amount": 15000000,
        "notes": null,
        "display_order": 1,
        "source_pdo_supplementary_id": null,
        "total_transfer": 15000000,
        "total_realization": 15000000,
        "saldo_item": 0,
        "realization_status": "sesuai"
      }
    ]
  }
}
```

**Nilai `realization_status` per item:** `belum_realisasi`, `partial`, `sesuai`, `over_budget`, `butuh_bukti`, `butuh_penjelasan`

---

### PUT /api/v1/pdo/{id}

**Deskripsi:** Edit header PDO (hanya field header, bukan baris detail).

**Authorization:** `KERANI` (hanya pembuat PDO)

**Business Rules:** Hanya bisa diedit jika status = `draft`

**Request Body:**
```json
{
  "submission_date": "2026-06-02",
  "notes": "Revisi setelah reject dari Asisten"
}
```

---

### DELETE /api/v1/pdo/{id}

**Deskripsi:** Hapus PDO (hanya saat masih Draft).

**Authorization:** `KERANI` (hanya pembuat PDO)

**Business Rules:** Status harus `draft`. Jika sudah submitted atau lebih → error `PDO_NOT_DRAFT`

---

### GET /api/v1/pdo/{id}/details

**Deskripsi:** Daftar semua baris item biaya dalam satu PDO.

**Authorization:** Semua role (dengan pembatasan unit)

**Response 200:** Array dari objek detail seperti pada `GET /api/v1/pdo/{id}`

---

### POST /api/v1/pdo/{id}/details

**Deskripsi:** Tambah baris item biaya non-rutin ke PDO Draft.

**Authorization:** `KERANI` (hanya pembuat PDO)

**Business Rules:** PDO harus status `draft`

**Request Body:**
```json
{
  "expense_item_id": "item-uuid-h1001",
  "description": "Sewa excavator untuk normalisasi parit",
  "quantity": 3,
  "unit": "Hari",
  "rate": 2500000,
  "amount": 7500000,
  "notes": null
}
```

Saat item dipilih, backend mengisi snapshot `account_number`, `unit`, `rate` dari master. Frontend boleh override `unit`, `rate`, `account_number` via body.

**Response 201:**
```json
{
  "success": true,
  "data": {
    "id": "detail-uuid-new",
    "expense_item": {
      "id": "item-uuid-h1001",
      "code": "H1-003",
      "name": "Sewa Peralatan",
      "mode_input": "manual"
    },
    "account_number": "5108003",
    "description": "Sewa excavator untuk normalisasi parit",
    "quantity": 3,
    "unit": "Hari",
    "rate": 2500000,
    "amount": 7500000,
    "display_order": 15
  },
  "message": "Item biaya berhasil ditambahkan"
}
```

---

### PUT /api/v1/pdo/{id}/details/{detailId}

**Deskripsi:** Edit satu baris item biaya.

**Authorization:** `KERANI` (hanya pembuat PDO)

**Business Rules:** PDO harus status `draft`

**Request Body:**
```json
{
  "description": "Sewa excavator 4 hari",
  "quantity": 4,
  "amount": 10000000,
  "notes": "Tambah 1 hari karena medan sulit"
}
```

---

### DELETE /api/v1/pdo/{id}/details/{detailId}

**Deskripsi:** Hapus satu baris item dari PDO.

**Authorization:** `KERANI` (hanya pembuat PDO)

**Business Rules:** PDO harus status `draft`

---

### POST /api/v1/pdo/{id}/submit

**Deskripsi:** Kerani submit PDO ke Asisten untuk mulai proses approval.

**Authorization:** `KERANI` (hanya pembuat PDO)

**Business Rules:**
1. PDO harus status `draft`
2. Semua baris harus valid: `description` tidak kosong DAN `amount > 0`
3. Semua item `mode_input = auto_external` harus sudah memiliki `amount > 0`
4. Setelah submit: status berubah ke `submitted`, notifikasi dikirim ke Asisten Kebun unit terkait

**Request Body:** Kosong

**Response 200:**
```json
{
  "success": true,
  "data": {
    "pdo_number": "PDO-2026-06-KP-001",
    "status": "submitted",
    "submitted_at": "2026-06-01T09:00:00+07:00"
  },
  "message": "PDO berhasil disubmit. Notifikasi telah dikirim ke Asisten Kebun."
}
```

**Response 422 (baris invalid):**
```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "PDO tidak bisa disubmit karena ada baris yang tidak lengkap",
    "details": [
      { "field": "details[2].description", "message": "Keterangan wajib diisi" },
      { "field": "details[5].amount", "message": "Jumlah harus lebih dari 0" }
    ]
  }
}
```

---

### POST /api/v1/pdo/{id}/approve ⭐

**Deskripsi:** Approve PDO sesuai tahap approval yang sedang berjalan.

**Authorization:** `ASISTEN_KEBUN`, `MANAJER_KEBUN`, `MANAJER_KEUANGAN`, `DIREKTUR_KEUANGAN`

**Business Rules:**
1. User tidak bisa approve PDO yang dibuat sendiri → error `SELF_APPROVAL_NOT_ALLOWED`
2. Hanya user dengan role yang sesuai tahap saat ini yang bisa approve → error `APPROVAL_SEQUENCE_VIOLATION`
3. Untuk tahap manajer: kedua Manajer (Kebun + Keuangan) harus approve. Saat salah satu approve, sistem mencatat dan menunggu yang lain
4. Jika Direktur approve → status berubah ke `final`, notifikasi ke Kerani

**Request Body:**
```json
{
  "notes": "Disetujui. Nominal wajar sesuai kebutuhan operasional bulan Juni."
}
```

**Response 200 (Manajer Kebun approve, masih menunggu Manajer Keuangan):**
```json
{
  "success": true,
  "data": {
    "pdo_number": "PDO-2026-06-KP-001",
    "status": "in_review_manager",
    "approval_stage": "manajer_kebun",
    "action": "approve",
    "waiting_for": ["MANAJER_KEUANGAN"],
    "notes": "Disetujui. Nominal wajar sesuai kebutuhan operasional bulan Juni."
  },
  "message": "PDO disetujui. Menunggu persetujuan Manajer Keuangan."
}
```

**Response 200 (Direktur approve → Final):**
```json
{
  "success": true,
  "data": {
    "pdo_number": "PDO-2026-06-KP-001",
    "status": "final",
    "approved_at": "2026-06-05T14:00:00+07:00"
  },
  "message": "PDO disetujui Direktur dan kini berstatus Final. Realisasi dapat dicatat."
}
```

**Response 403 (self-approval):**
```json
{
  "success": false,
  "error": {
    "code": "SELF_APPROVAL_NOT_ALLOWED",
    "message": "Anda tidak dapat menyetujui PDO yang Anda buat sendiri"
  }
}
```

---

### POST /api/v1/pdo/{id}/reject ⭐

**Deskripsi:** Reject PDO dengan alasan wajib. PDO kembali ke Draft.

**Authorization:** `ASISTEN_KEBUN`, `MANAJER_KEBUN`, `MANAJER_KEUANGAN`, `DIREKTUR_KEUANGAN`

**Business Rules:**
1. User harus berada di tahap approval yang relevan
2. `reason` wajib diisi
3. Setelah reject: status kembali ke `draft`, semua approval sebelumnya hangus, notifikasi + alasan dikirim ke Kerani

**Request Body:**
```json
{
  "reason": "Nominal BBM Solar terlalu besar, harap sesuaikan dengan konsumsi aktual bulan lalu (±850 liter). Gaji kontrak perlu dilengkapi dengan daftar nama."
}
```

**Response 200:**
```json
{
  "success": true,
  "data": {
    "pdo_number": "PDO-2026-06-KP-001",
    "status": "draft",
    "rejected_by": {
      "id": "user-uuid-mgr",
      "full_name": "Andi Wirawan",
      "role": "Manajer Kebun"
    },
    "reason": "Nominal BBM Solar terlalu besar...",
    "rejected_at": "2026-06-04T10:30:00+07:00"
  },
  "message": "PDO ditolak dan dikembalikan ke Kerani. Notifikasi beserta alasan telah dikirim."
}
```

---

### GET /api/v1/pdo/{id}/approval-history

**Deskripsi:** Timeline lengkap approval PDO secara kronologis.

**Authorization:** Semua role (dengan pembatasan unit)

**Response 200:**
```json
{
  "success": true,
  "data": [
    {
      "sequence_number": 1,
      "approval_stage": "kerani",
      "action": "submit",
      "actor": { "id": "user-uuid", "full_name": "Rika Aprillia", "role": "Kerani" },
      "reason": null,
      "created_at": "2026-06-01T09:00:00+07:00"
    },
    {
      "sequence_number": 2,
      "approval_stage": "asisten",
      "action": "approve",
      "actor": { "id": "user-uuid-asst", "full_name": "Budi Santoso", "role": "Asisten Kebun" },
      "reason": null,
      "created_at": "2026-06-02T10:00:00+07:00"
    },
    {
      "sequence_number": 3,
      "approval_stage": "manajer_kebun",
      "action": "reject",
      "actor": { "id": "user-uuid-mgr", "full_name": "Andi Wirawan", "role": "Manajer Kebun" },
      "reason": "Nominal BBM Solar terlalu besar...",
      "created_at": "2026-06-04T10:30:00+07:00"
    },
    {
      "sequence_number": 4,
      "approval_stage": "kerani",
      "action": "resubmit",
      "actor": { "id": "user-uuid", "full_name": "Rika Aprillia", "role": "Kerani" },
      "reason": null,
      "created_at": "2026-06-04T13:00:00+07:00"
    }
  ]
}
```

---

## 9. Transfer Dana

### GET /api/v1/pdo/{id}/transfers

**Deskripsi:** Ringkasan transfer per item PDO beserta total kumulatif.

**Authorization:** Semua role

**Response 200:**
```json
{
  "success": true,
  "data": {
    "pdo_id": "pdo-uuid-001",
    "pdo_number": "PDO-2026-06-KP-001",
    "total_amount": 125000000,
    "total_transfer": 120000000,
    "transfer_vs_amount_diff": -5000000,
    "items": [
      {
        "pdo_detail_id": "detail-uuid-001",
        "expense_item_name": "Gaji Manager",
        "amount": 15000000,
        "total_transfer": 15000000,
        "transfer_count": 1,
        "has_diff": false
      },
      {
        "pdo_detail_id": "detail-uuid-005",
        "expense_item_name": "BBM Solar",
        "amount": 10000000,
        "total_transfer": 8000000,
        "transfer_count": 2,
        "has_diff": true,
        "diff_amount": -2000000
      }
    ]
  }
}
```

---

### GET /api/v1/pdo-details/{detailId}/transfers

**Deskripsi:** Riwayat semua entri transfer untuk satu baris item PDO.

**Authorization:** `MANAJER_KEUANGAN`, `STAFF_KEUANGAN`, `ADMIN`

**Response 200:**
```json
{
  "success": true,
  "data": [
    {
      "id": "transfer-uuid-001",
      "transfer_date": "2026-06-10",
      "amount": 5000000,
      "reference_number": "TF/2026/06/001",
      "notes": "Transfer pertama untuk BBM Solar",
      "recorded_by": { "id": "user-uuid-mgrfin", "full_name": "Dewi Rahayu", "role": "Manajer Keuangan" },
      "created_at": "2026-06-10T14:00:00+07:00",
      "updated_at": "2026-06-10T14:00:00+07:00"
    },
    {
      "id": "transfer-uuid-002",
      "transfer_date": "2026-06-20",
      "amount": 3000000,
      "reference_number": "TF/2026/06/015",
      "notes": "Transfer kedua",
      "recorded_by": { "id": "user-uuid-mgrfin", "full_name": "Dewi Rahayu", "role": "Manajer Keuangan" },
      "created_at": "2026-06-20T09:00:00+07:00",
      "updated_at": "2026-06-20T09:00:00+07:00"
    }
  ]
}
```

---

### POST /api/v1/pdo-details/{detailId}/transfers

**Deskripsi:** Catat entri transfer baru untuk satu baris item PDO.

**Authorization:** `MANAJER_KEUANGAN`, `STAFF_KEUANGAN`

**Business Rules:**
1. PDO induk harus berstatus `final` → error `TRANSFER_NOT_ALLOWED`
2. PDO induk tidak boleh berstatus `closed` → error `PDO_IS_CLOSED`
3. `amount` harus > 0

**Request Body:**
```json
{
  "transfer_date": "2026-06-10",
  "amount": 5000000,
  "reference_number": "TF/2026/06/001",
  "notes": "Transfer pertama untuk BBM Solar"
}
```

**Response 201:**
```json
{
  "success": true,
  "data": {
    "id": "transfer-uuid-new",
    "pdo_detail_id": "detail-uuid-005",
    "transfer_date": "2026-06-10",
    "amount": 5000000,
    "reference_number": "TF/2026/06/001",
    "notes": "Transfer pertama untuk BBM Solar",
    "recorded_by": { "id": "user-uuid-mgrfin", "full_name": "Dewi Rahayu" },
    "created_at": "2026-06-10T14:00:00+07:00",
    "item_total_transfer_after": 5000000
  },
  "message": "Transfer berhasil dicatat"
}
```

---

### PUT /api/v1/transfer-entries/{id}

**Deskripsi:** Koreksi entri transfer yang sudah disimpan (tidak bisa dihapus).

**Authorization:** `MANAJER_KEUANGAN`, `STAFF_KEUANGAN`

**Business Rules:**
1. PDO tidak boleh Closed → error `PDO_IS_CLOSED`
2. Koreksi wajib dicatat di audit log: nilai lama, nilai baru, siapa, kapan

**Request Body:**
```json
{
  "transfer_date": "2026-06-11",
  "amount": 5500000,
  "reference_number": "TF/2026/06/001-REV",
  "notes": "Koreksi nominal — ada biaya admin bank Rp 500.000"
}
```

**Response 200:**
```json
{
  "success": true,
  "data": {
    "id": "transfer-uuid-001",
    "amount": 5500000,
    "reference_number": "TF/2026/06/001-REV",
    "updated_at": "2026-06-11T10:00:00+07:00"
  },
  "message": "Entri transfer berhasil dikoreksi. Perubahan dicatat di audit log."
}
```

---

## 10. Penutupan PDO

### POST /api/v1/pdo/{id}/close

**Deskripsi:** Menutup PDO secara manual sebelum jadwal otomatis.

**Authorization:** `MANAJER_KEUANGAN`

**Business Rules:**
1. PDO harus berstatus `final`
2. `closed_date` tidak boleh lebih awal dari hari ini
3. `closed_date` tidak boleh lebih lambat dari hari terakhir bulan periode PDO
4. Setelah closed: semua operasi tulis diblokir (transfer, realisasi)

**Request Body:**
```json
{
  "closed_date": "2026-06-25",
  "closure_notes": "Semua pengeluaran sudah diverifikasi. PDO ditutup lebih awal."
}
```

**Response 200:**
```json
{
  "success": true,
  "data": {
    "pdo_number": "PDO-2026-06-KP-001",
    "status": "closed",
    "closure_type": "manual",
    "closed_at": "2026-06-25",
    "closed_by": { "id": "user-uuid-mgrfin", "full_name": "Dewi Rahayu" }
  },
  "message": "PDO berhasil ditutup secara manual."
}
```

---

## 11. PDO Tambahan

### GET /api/v1/pdo-supplementary

**Deskripsi:** Daftar PDO Tambahan yang masih dalam proses (belum merged).

**Authorization:** Semua role (dengan pembatasan unit)

**Query Parameters:**

| Parameter | Tipe | Wajib | Deskripsi |
|-----------|------|-------|-----------|
| `parent_pdo_id` | UUID | Tidak | Filter berdasarkan PDO Bulanan induk |
| `unit_id` | UUID | Tidak | Filter berdasarkan unit kebun |
| `status` | string | Tidak | Filter status (kecuali `final_merged` — tidak muncul di sini) |
| `page` | integer | Tidak | Default: 1 |

**Response 200:**
```json
{
  "success": true,
  "data": [
    {
      "id": "pdot-uuid-001",
      "pdo_number": "PDOT-2026-06-KP-001",
      "parent_pdo": { "id": "pdo-uuid-001", "pdo_number": "PDO-2026-06-KP-001" },
      "plantation_unit": { "id": "unit-uuid-kp", "code": "KP", "name": "Kebun Kota Pinang" },
      "period_month": 6,
      "period_year": 2026,
      "status": "submitted",
      "total_amount": 15000000,
      "created_by": { "id": "user-uuid", "full_name": "Rika Aprillia" },
      "created_at": "2026-06-15T10:00:00+07:00"
    }
  ],
  "meta": { "current_page": 1, "per_page": 20, "total": 2, "last_page": 1 }
}
```

---

### POST /api/v1/pdo-supplementary

**Deskripsi:** Membuat PDO Tambahan baru.

**Authorization:** `KERANI`

**Business Rules:**
1. PDO Bulanan induk untuk unit + periode yang sama harus berstatus `final`
2. PDO Bulanan induk tidak boleh berstatus `closed` → error `SUPPLEMENTARY_PARENT_CLOSED`
3. PDO Tambahan tidak memiliki template — tabel rincian kosong

**Request Body:**
```json
{
  "parent_pdo_id": "pdo-uuid-001",
  "submission_date": "2026-06-15",
  "notes": "Kebutuhan perbaikan jalan akibat banjir mendadak"
}
```

**Response 201:**
```json
{
  "success": true,
  "data": {
    "id": "pdot-uuid-001",
    "pdo_number": "PDOT-2026-06-KP-001",
    "parent_pdo": { "id": "pdo-uuid-001", "pdo_number": "PDO-2026-06-KP-001" },
    "status": "draft",
    "total_amount": 0,
    "details": []
  },
  "message": "PDO Tambahan berhasil dibuat. Silakan tambahkan item biaya."
}
```

**Response 422:**
```json
{
  "success": false,
  "error": {
    "code": "SUPPLEMENTARY_NO_PARENT",
    "message": "PDO Bulanan untuk unit Kebun Kota Pinang periode Juni 2026 belum berstatus Final. PDO Tambahan hanya bisa dibuat setelah PDO Bulanan disetujui Direktur."
  }
}
```

---

### POST /api/v1/pdo-supplementary/{id}/details

**Deskripsi:** Tambah item biaya ke PDO Tambahan (tidak ada template — semua manual).

**Authorization:** `KERANI` (hanya pembuat)

**Request Body:** Sama dengan `POST /api/v1/pdo/{id}/details`

---

### PUT /api/v1/pdo-supplementary/{id}/details/{detailId}

**Authorization:** `KERANI` (hanya pembuat, PDO harus Draft)

---

### DELETE /api/v1/pdo-supplementary/{id}/details/{detailId}

**Authorization:** `KERANI` (hanya pembuat, PDO harus Draft)

---

### POST /api/v1/pdo-supplementary/{id}/submit

**Authorization:** `KERANI`

**Business Rules:** Sama dengan `POST /api/v1/pdo/{id}/submit`

---

### POST /api/v1/pdo-supplementary/{id}/approve

**Authorization:** `ASISTEN_KEBUN`, `MANAJER_KEBUN`, `MANAJER_KEUANGAN`, `DIREKTUR_KEUANGAN`

**Business Rules:** Sama dengan PDO Bulanan. Direktur approve → trigger merger otomatis ke PDO Bulanan.

---

### POST /api/v1/pdo-supplementary/{id}/reject

**Authorization:** `ASISTEN_KEBUN`, `MANAJER_KEBUN`, `MANAJER_KEUANGAN`, `DIREKTUR_KEUANGAN`

---

### POST /api/v1/pdo-supplementary/{id}/merge ⭐

**Deskripsi:** Merger PDO Tambahan yang sudah disetujui Direktur ke PDO Bulanan induknya. Endpoint ini **dipanggil otomatis oleh sistem** saat Direktur approve — tidak perlu dipanggil manual oleh frontend. Disediakan sebagai endpoint terpisah untuk keperluan retry jika merger gagal.

**Authorization:** `ADMIN`, `DIREKTUR_KEUANGAN`

**Business Rules:**
1. PDO Tambahan harus berstatus `in_review_direktur` dan sudah di-approve Direktur
2. PDO Bulanan induk harus berstatus `final` (bukan `closed`)
3. Semua item dari `pdo_supplementary_details` disalin ke `pdo_details` PDO Bulanan dengan `source_pdo_supplementary_id` = ID PDO Tambahan ini
4. Header pemisah dicatat dalam `notes` pada `pdo_details` pertama yang di-merge
5. Status PDO Tambahan berubah ke `final_merged`

**Request Body:** Kosong

**Response 200:**
```json
{
  "success": true,
  "data": {
    "pdo_supplementary_number": "PDOT-2026-06-KP-001",
    "parent_pdo_number": "PDO-2026-06-KP-001",
    "status": "final_merged",
    "merged_at": "2026-06-18T14:00:00+07:00",
    "items_merged": 3,
    "message_for_ui": "▶ PDO Tambahan: PDOT-2026-06-KP-001 — disetujui 18 Jun 2026"
  },
  "message": "PDO Tambahan berhasil digabungkan. 3 item baru ditambahkan ke PDO-2026-06-KP-001."
}
```

---

## 12. Realisasi Dana

### GET /api/v1/pdo/{id}/realizations

**Deskripsi:** Ringkasan realisasi dan transfer untuk seluruh PDO (level PDO).

**Authorization:** Semua role

**Response 200:**
```json
{
  "success": true,
  "data": {
    "pdo_id": "pdo-uuid-001",
    "pdo_number": "PDO-2026-06-KP-001",
    "status": "final",
    "total_amount": 125000000,
    "total_transfer": 120000000,
    "total_realization": 95000000,
    "saldo_pdo": 25000000,
    "realization_percentage": 79.17,
    "items_count": 15,
    "items_belum_realisasi": 3,
    "items_over_budget": 1,
    "items_butuh_bukti": 2,
    "items_butuh_penjelasan": 1
  }
}
```

---

### GET /api/v1/pdo/{id}/realizations/items

**Deskripsi:** Realisasi per item biaya dalam satu PDO.

**Authorization:** Semua role

**Query Parameters:**

| Parameter | Tipe | Deskripsi |
|-----------|------|-----------|
| `status` | string | Filter: `belum_realisasi`, `partial`, `sesuai`, `over_budget`, `butuh_bukti`, `butuh_penjelasan` |

**Response 200:**
```json
{
  "success": true,
  "data": [
    {
      "pdo_detail_id": "detail-uuid-001",
      "expense_item": { "id": "item-uuid", "code": "A1-001", "name": "Gaji Manager" },
      "category": { "code": "A", "name": "Gaji Staff & Pegawai Kantor" },
      "subcategory": { "code": "A1", "name": "Staff Kebun" },
      "description": "Gaji Manager Kebun bulan Juni",
      "amount": 15000000,
      "total_transfer": 15000000,
      "total_realization": 15000000,
      "saldo_item": 0,
      "realization_percentage": 100.0,
      "realization_status": "sesuai",
      "realization_count": 1,
      "source_pdo_supplementary_id": null
    }
  ]
}
```

---

### GET /api/v1/realization-entries

**Deskripsi:** Semua entri realisasi untuk satu baris item PDO (history).

**Authorization:** Semua role

**Query Parameters:**

| Parameter | Tipe | Wajib | Deskripsi |
|-----------|------|-------|-----------|
| `pdo_detail_id` | UUID | **Ya** | ID baris item PDO |

**Response 200:**
```json
{
  "success": true,
  "data": [
    {
      "id": "real-uuid-001",
      "pdo_detail_id": "detail-uuid-001",
      "transaction_date": "2026-06-05",
      "amount": 15000000,
      "payment_method": "transfer",
      "reference_number": "KW/2026/06/001",
      "funding_source": "rekening_kebun",
      "explanation": null,
      "recorded_by": { "id": "user-uuid", "full_name": "Rika Aprillia", "role": "Kerani" },
      "attachments": [
        {
          "id": "attach-uuid-001",
          "file_name": "bukti_gaji_manager_juni.pdf",
          "mime_type": "application/pdf",
          "file_size_bytes": 204800,
          "preview_url": "https://storage.pdo.barumunpalma.co.id/presigned/...",
          "created_at": "2026-06-05T10:00:00+07:00"
        }
      ],
      "created_at": "2026-06-05T10:00:00+07:00",
      "updated_at": "2026-06-05T10:00:00+07:00"
    }
  ]
}
```

---

### POST /api/v1/realization-entries ⭐

**Deskripsi:** Catat entri realisasi baru.

**Authorization:** `KERANI`, `STAFF_PURCHASING`

**Business Rules:**
1. PDO induk harus berstatus `final` → error `PDO_NOT_FINAL`
2. PDO induk tidak boleh berstatus `closed` → error `PDO_IS_CLOSED`
3. Validasi kumulatif PDO: `Total Realisasi PDO setelah entri baru ≤ Total Transfer PDO` → error `REALIZATION_LIMIT_EXCEEDED`
4. Jika `amount > threshold_proof_amount` (default: 1.000.000) → upload bukti wajib (dapat diupload setelah create via `POST /realization-entries/{id}/attachments`)
5. Jika `|total_transfer_item - total_realization_item_after| > threshold_explanation_amount` (default: 500.000) → `explanation` wajib
6. **Staff Purchasing**: `funding_source` harus `rekening_utama` → error `REALIZATION_FUNDING_SOURCE_INVALID` jika bukan

**Request Body:**
```json
{
  "pdo_detail_id": "detail-uuid-005",
  "transaction_date": "2026-06-10",
  "amount": 2000000,
  "payment_method": "transfer",
  "reference_number": "KW/2026/06/010",
  "funding_source": "kas_kebun",
  "explanation": null
}
```

**Response 201:**
```json
{
  "success": true,
  "data": {
    "id": "real-uuid-new",
    "pdo_detail_id": "detail-uuid-005",
    "transaction_date": "2026-06-10",
    "amount": 2000000,
    "payment_method": "transfer",
    "reference_number": "KW/2026/06/010",
    "funding_source": "kas_kebun",
    "explanation": null,
    "recorded_by": { "id": "user-uuid", "full_name": "Rika Aprillia" },
    "attachments": [],
    "requires_proof": false,
    "pdo_summary_after": {
      "total_transfer_pdo": 120000000,
      "total_realization_pdo": 97000000,
      "saldo_pdo": 23000000,
      "realization_percentage_pdo": 80.83
    }
  },
  "message": "Realisasi berhasil dicatat"
}
```

**Response 422 (limit kumulatif PDO terlampaui):**
```json
{
  "success": false,
  "error": {
    "code": "REALIZATION_LIMIT_EXCEEDED",
    "message": "Total realisasi PDO akan melebihi total dana yang ditransfer (Rp 120.000.000). Sisa saldo PDO: Rp 5.000.000. Kurangi nominal atau tambah transfer terlebih dahulu.",
    "details": [
      {
        "field": "amount",
        "message": "Nominal maksimum yang bisa direalisasi saat ini: Rp 5.000.000"
      }
    ]
  }
}
```

---

### PUT /api/v1/realization-entries/{id}

**Deskripsi:** Edit entri realisasi yang sudah dicatat.

**Authorization:** `KERANI` (hanya yang mencatat)

**Business Rules:**
1. PDO tidak boleh Closed
2. Edit tidak boleh menyebabkan Total Realisasi PDO > Total Transfer PDO
3. Semua perubahan dicatat di audit log

**Request Body:**
```json
{
  "transaction_date": "2026-06-11",
  "amount": 1800000,
  "reference_number": "KW/2026/06/010-REV",
  "explanation": "Koreksi nominal: ada kembalian Rp 200.000"
}
```

---

### DELETE /api/v1/realization-entries/{id}

**Deskripsi:** Hapus entri realisasi.

**Authorization:** `KERANI` (hanya yang mencatat)

**Business Rules:**
1. PDO tidak boleh Closed
2. Hapus entri juga menghapus semua attachment terkait (cascade)
3. Dicatat di audit log

**Response 200:**
```json
{
  "success": true,
  "message": "Entri realisasi berhasil dihapus"
}
```

---

### POST /api/v1/realization-entries/{id}/attachments

**Deskripsi:** Upload bukti transaksi untuk entri realisasi.

**Authorization:** `KERANI`, `STAFF_PURCHASING`

**Request:** `multipart/form-data`

| Field | Tipe | Wajib | Deskripsi |
|-------|------|-------|-----------|
| `file` | file | Ya | File bukti. Format: PDF, JPG, PNG. Maks 5 MB |

**Business Rules:**
1. Maksimum ukuran file: 5 MB → error `FILE_TOO_LARGE`
2. Format yang diterima: `application/pdf`, `image/jpeg`, `image/png` → error `FILE_TYPE_INVALID`
3. File disimpan di AWS S3; path disimpan di `realization_attachments.file_path`

**Response 201:**
```json
{
  "success": true,
  "data": {
    "id": "attach-uuid-new",
    "realization_entry_id": "real-uuid-001",
    "file_name": "bukti_bbm_solar_10juni.jpg",
    "mime_type": "image/jpeg",
    "file_size_bytes": 512000,
    "preview_url": "https://storage.pdo.barumunpalma.co.id/presigned/...",
    "uploaded_by": { "id": "user-uuid", "full_name": "Rika Aprillia" },
    "created_at": "2026-06-10T15:00:00+07:00"
  },
  "message": "Bukti transaksi berhasil diunggah"
}
```

**Response 422 (file terlalu besar):**
```json
{
  "success": false,
  "error": {
    "code": "FILE_TOO_LARGE",
    "message": "Ukuran file melebihi batas maksimum 5 MB. Ukuran file Anda: 6.2 MB"
  }
}
```

---

### DELETE /api/v1/realization-entries/{id}/attachments/{attachmentId}

**Deskripsi:** Hapus satu file bukti.

**Authorization:** `KERANI` (hanya yang mengupload, PDO tidak boleh Closed)

**Response 200:**
```json
{
  "success": true,
  "message": "Bukti transaksi berhasil dihapus"
}
```

---

## 13. Dashboard & Laporan

### GET /api/v1/dashboard ⭐

**Deskripsi:** Data lengkap untuk halaman Dashboard: KPI cards dan data grafik.

**Authorization:** Semua role. KERANI dan ASISTEN hanya melihat data unit mereka.

**Query Parameters:**

| Parameter | Tipe | Wajib | Deskripsi |
|-----------|------|-------|-----------|
| `unit_id` | UUID | Tidak | Filter unit (untuk role lintas unit) |
| `period_year` | integer | Tidak | Default: tahun berjalan |
| `period_month` | integer | Tidak | Default: bulan berjalan |
| `category_id` | UUID | Tidak | Filter kategori biaya |
| `subcategory_id` | UUID | Tidak | Filter sub-kategori |

**Response 200:**
```json
{
  "success": true,
  "data": {
    "period": { "year": 2026, "month": 6, "label": "Juni 2026" },
    "filters_applied": {
      "unit_id": "unit-uuid-kp",
      "unit_name": "Kebun Kota Pinang"
    },
    "kpi": {
      "total_amount": 125000000,
      "total_transfer": 120000000,
      "total_realization": 95000000,
      "saldo": 25000000,
      "realization_percentage": 79.17,
      "items_without_proof": 3,
      "active_pdo_count": 1,
      "pdo_status": "final"
    },
    "chart_category_breakdown": [
      {
        "category_code": "A",
        "category_name": "Gaji Staff & Pegawai Kantor",
        "total_amount": 45000000,
        "total_transfer": 45000000,
        "total_realization": 42000000,
        "realization_percentage": 93.33
      },
      {
        "category_code": "E",
        "category_name": "Divisi Traksi",
        "total_amount": 35000000,
        "total_transfer": 30000000,
        "total_realization": 20000000,
        "realization_percentage": 66.67
      }
    ],
    "chart_donut": [
      { "category_code": "A", "category_name": "Gaji Staff", "proportion_percentage": 36.0 },
      { "category_code": "E", "category_name": "Divisi Traksi", "proportion_percentage": 28.0 }
    ]
  }
}
```

---

### GET /api/v1/dashboard/category-summary

**Deskripsi:** Breakdown detail per kategori dan sub-kategori.

**Authorization:** Semua role

**Query Parameters:** Sama dengan `/api/v1/dashboard`

**Response 200:**
```json
{
  "success": true,
  "data": [
    {
      "category": { "code": "A", "name": "Gaji Staff & Pegawai Kantor" },
      "subtotal_amount": 45000000,
      "subtotal_transfer": 45000000,
      "subtotal_realization": 42000000,
      "subcategories": [
        {
          "subcategory": { "code": "A1", "name": "Staff Kebun" },
          "subtotal_amount": 30000000,
          "subtotal_transfer": 30000000,
          "subtotal_realization": 30000000,
          "items": [
            {
              "pdo_detail_id": "detail-uuid-001",
              "item_name": "Gaji Manager",
              "amount": 15000000,
              "total_transfer": 15000000,
              "total_realization": 15000000,
              "saldo": 0,
              "realization_status": "sesuai"
            }
          ]
        }
      ]
    }
  ]
}
```

---

### GET /api/v1/reports/realization

**Deskripsi:** Laporan realisasi dana per item biaya.

**Authorization:** Semua role

**Query Parameters:**

| Parameter | Tipe | Deskripsi |
|-----------|------|-----------|
| `unit_id` | UUID | Filter unit |
| `period_year` | integer | Tahun |
| `period_month` | integer | Bulan |
| `category_id` | UUID | Filter kategori |
| `status` | string | Filter status PDO |
| `page` | integer | Pagination |

---

### GET /api/v1/reports/over-budget

**Deskripsi:** Laporan item biaya yang realisasinya melebihi transfer (per item).

**Authorization:** Semua role

**Query Parameters:** Sama dengan `/reports/realization`

---

### GET /api/v1/reports/missing-proof

**Deskripsi:** Laporan item yang belum ada bukti transaksinya.

**Authorization:** Semua role

---

### GET /api/v1/reports/recap

**Deskripsi:** Rekapitulasi digital per Kategori → Sub-Kategori dengan subtotal.

**Authorization:** Semua role

**Query Parameters:**

| Parameter | Tipe | Deskripsi |
|-----------|------|-----------|
| `unit_id` | UUID | Filter unit |
| `period_year` | integer | Tahun |
| `period_month` | integer | Bulan |
| `category_id` | UUID | Filter kategori |

**Response 200:**
```json
{
  "success": true,
  "data": {
    "period_label": "Juni 2026",
    "unit": { "code": "KP", "name": "Kebun Kota Pinang" },
    "grand_total_amount": 125000000,
    "grand_total_transfer": 120000000,
    "grand_total_realization": 95000000,
    "grand_total_saldo": 25000000,
    "categories": [
      {
        "no": 1,
        "category_code": "A",
        "category_name": "Gaji Staff & Pegawai Kantor",
        "subtotal_amount": 45000000,
        "subtotal_transfer": 45000000,
        "subtotal_realization": 42000000,
        "subtotal_saldo": 3000000,
        "subcategories": [
          {
            "subcategory_code": "A1",
            "subcategory_name": "Staff Kebun",
            "subtotal_amount": 30000000,
            "subtotal_transfer": 30000000,
            "subtotal_realization": 30000000,
            "subtotal_saldo": 0,
            "items": [
              {
                "no": 1,
                "item_code": "A1-001",
                "item_name": "Gaji Manager",
                "account_number": "5101001",
                "amount": 15000000,
                "total_transfer": 15000000,
                "total_realization": 15000000,
                "saldo": 0
              }
            ]
          }
        ]
      }
    ]
  }
}
```

---

### POST /api/v1/reports/export

**Deskripsi:** Memicu export laporan ke PDF atau Excel. Export diproses secara asinkron via background job.

**Authorization:** Semua role

**Request Body:**
```json
{
  "report_type": "recap",
  "format": "xlsx",
  "filters": {
    "unit_id": "unit-uuid-kp",
    "period_year": 2026,
    "period_month": 6
  }
}
```

**Nilai `report_type`:** `recap`, `realization`, `over_budget`, `missing_proof`
**Nilai `format`:** `xlsx`, `pdf`

**Response 202 (diterima, diproses):**
```json
{
  "success": true,
  "data": {
    "job_id": "export-job-uuid",
    "status": "processing",
    "estimated_seconds": 10
  },
  "message": "Export sedang diproses. Gunakan job_id untuk mengecek status."
}
```

### GET /api/v1/reports/export/{jobId}

**Deskripsi:** Cek status dan ambil URL download hasil export.

**Response 200 (selesai):**
```json
{
  "success": true,
  "data": {
    "job_id": "export-job-uuid",
    "status": "done",
    "download_url": "https://storage.pdo.barumunpalma.co.id/exports/...",
    "expires_at": "2026-06-18T11:00:00+07:00",
    "file_name": "rekap-pdo-KP-2026-06.xlsx"
  }
}
```

---

## 14. Pengaturan Sistem

### GET /api/v1/settings

**Deskripsi:** Daftar semua konfigurasi sistem.

**Authorization:** `ADMIN`

**Response 200:**
```json
{
  "success": true,
  "data": [
    { "key": "threshold_proof_amount", "value": "1000000", "description": "Nominal minimum wajib upload bukti (Rupiah)" },
    { "key": "threshold_explanation_amount", "value": "500000", "description": "Batas selisih transfer-realisasi yang wajib penjelasan (Rupiah)" },
    { "key": "wa_gateway_url", "value": "https://wa.gateway.barumunpalma.co.id/send", "description": "URL endpoint WhatsApp gateway" },
    { "key": "wa_gateway_api_key", "value": "***REDACTED***", "description": "API Key WhatsApp gateway (terenkripsi)" },
    { "key": "reminder_day_of_month", "value": "1", "description": "Tanggal pengiriman reminder PDO bulanan" },
    { "key": "reminder_hour", "value": "8", "description": "Jam pengiriman reminder (WIB)" }
  ]
}
```

---

### PUT /api/v1/settings

**Deskripsi:** Update satu atau beberapa konfigurasi sistem.

**Authorization:** `ADMIN`

**Request Body:**
```json
{
  "settings": [
    { "key": "threshold_proof_amount", "value": "2000000" },
    { "key": "reminder_day_of_month", "value": "2" }
  ]
}
```

**Response 200:**
```json
{
  "success": true,
  "message": "2 konfigurasi berhasil diperbarui"
}
```

---

### POST /api/v1/settings/wa-test

**Deskripsi:** Test koneksi WhatsApp gateway dengan mengirim pesan ke nomor Admin yang login.

**Authorization:** `ADMIN`

**Response 200:**
```json
{
  "success": true,
  "data": {
    "status": "sent",
    "recipient": "628123456789",
    "message": "Test koneksi WhatsApp gateway berhasil"
  }
}
```

**Response 422 (gateway error):**
```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Koneksi ke WhatsApp gateway gagal. Periksa URL dan API Key.",
    "details": [
      { "field": "wa_gateway_url", "message": "Connection refused: https://wa.gateway.barumunpalma.co.id/send" }
    ]
  }
}
```

---

### GET /api/v1/notification-templates

**Deskripsi:** Daftar template pesan WhatsApp per event.

**Authorization:** `ADMIN`

**Response 200:**
```json
{
  "success": true,
  "data": [
    {
      "id": "tmpl-uuid-001",
      "event_type": "pdo_submitted",
      "channel": "whatsapp",
      "template_body": "Halo {{nama_user}}, PDO *{{nomor_pdo}}* untuk periode {{periode}} (unit {{unit_kebun}}) telah disubmit dan menunggu persetujuan Anda.",
      "is_active": true
    },
    {
      "id": "tmpl-uuid-002",
      "event_type": "pdo_rejected",
      "channel": "whatsapp",
      "template_body": "Halo {{nama_user}}, PDO *{{nomor_pdo}}* Anda telah ditolak.\n\nAlasan: {{alasan_reject}}\n\nSilakan lakukan revisi dan submit kembali.",
      "is_active": true
    },
    {
      "id": "tmpl-uuid-003",
      "event_type": "monthly_reminder",
      "channel": "whatsapp",
      "template_body": "Pengingat: PDO Bulanan untuk unit {{unit_kebun}} periode {{periode}} belum disubmit. Harap segera ajukan sebelum akhir bulan.",
      "is_active": true
    }
  ]
}
```

---

### PUT /api/v1/notification-templates/{id}

**Deskripsi:** Update template notifikasi.

**Authorization:** `ADMIN`

**Request Body:**
```json
{
  "template_body": "Halo {{nama_user}}, PDO *{{nomor_pdo}}* menunggu persetujuan Anda. Harap diselesaikan sebelum {{deadline}}.",
  "is_active": true
}
```

---

## Lampiran: Rangkuman Endpoint

| # | Method | Path | Role | Deskripsi Singkat |
|---|--------|------|------|-------------------|
| 1 | POST | /api/v1/auth/login | — | Login |
| 2 | POST | /api/v1/auth/logout | Semua | Logout |
| 3 | POST | /api/v1/auth/refresh-token | — | Refresh token |
| 4 | GET | /api/v1/auth/me | Semua | Profil user aktif |
| 5 | GET | /api/v1/users | ADMIN | Daftar user |
| 6 | POST | /api/v1/users | ADMIN | Buat user baru |
| 7 | GET | /api/v1/users/{id} | ADMIN | Detail user |
| 8 | PUT | /api/v1/users/{id} | ADMIN | Edit user |
| 9 | DELETE | /api/v1/users/{id} | ADMIN | Nonaktifkan user |
| 10 | GET | /api/v1/roles | ADMIN | Daftar role |
| 11 | GET | /api/v1/expense-categories | Semua | Daftar kategori |
| 12 | POST | /api/v1/expense-categories | ADMIN | Tambah kategori |
| 13 | GET | /api/v1/expense-categories/{id} | Semua | Detail kategori |
| 14 | PUT | /api/v1/expense-categories/{id} | ADMIN | Edit kategori |
| 15 | DELETE | /api/v1/expense-categories/{id} | ADMIN | Hapus/nonaktifkan kategori |
| 16 | GET | /api/v1/expense-subcategories | Semua | Daftar sub-kategori |
| 17 | POST | /api/v1/expense-subcategories | ADMIN | Tambah sub-kategori |
| 18 | GET | /api/v1/expense-subcategories/{id} | Semua | Detail sub-kategori |
| 19 | PUT | /api/v1/expense-subcategories/{id} | ADMIN | Edit sub-kategori |
| 20 | DELETE | /api/v1/expense-subcategories/{id} | ADMIN | Hapus/nonaktifkan sub-kategori |
| 21 | GET | /api/v1/expense-items | Semua | Daftar item biaya |
| 22 | GET | /api/v1/expense-items/routine | KERANI, ADMIN | Item rutin untuk template PDO |
| 23 | POST | /api/v1/expense-items | ADMIN | Tambah item biaya |
| 24 | GET | /api/v1/expense-items/{id} | Semua | Detail item biaya |
| 25 | PUT | /api/v1/expense-items/{id} | ADMIN | Edit item biaya |
| 26 | DELETE | /api/v1/expense-items/{id} | ADMIN | Hapus/nonaktifkan item |
| 27 | GET | /api/v1/pdo | Semua | Daftar PDO Bulanan |
| 28 | POST | /api/v1/pdo | KERANI | Buat PDO Bulanan + template otomatis |
| 29 | GET | /api/v1/pdo/{id} | Semua | Detail PDO lengkap |
| 30 | PUT | /api/v1/pdo/{id} | KERANI | Edit header PDO |
| 31 | DELETE | /api/v1/pdo/{id} | KERANI | Hapus PDO (hanya Draft) |
| 32 | GET | /api/v1/pdo/{id}/details | Semua | Baris item PDO |
| 33 | POST | /api/v1/pdo/{id}/details | KERANI | Tambah item non-rutin |
| 34 | PUT | /api/v1/pdo/{id}/details/{detailId} | KERANI | Edit baris item |
| 35 | DELETE | /api/v1/pdo/{id}/details/{detailId} | KERANI | Hapus baris item |
| 36 | POST | /api/v1/pdo/{id}/submit | KERANI | Submit PDO ke Asisten |
| 37 | POST | /api/v1/pdo/{id}/approve | ASISTEN, MGR, DIR | Approve PDO |
| 38 | POST | /api/v1/pdo/{id}/reject | ASISTEN, MGR, DIR | Reject PDO dengan alasan |
| 39 | GET | /api/v1/pdo/{id}/approval-history | Semua | Timeline approval |
| 40 | GET | /api/v1/pdo/{id}/transfers | Semua | Ringkasan transfer per PDO |
| 41 | GET | /api/v1/pdo-details/{detailId}/transfers | MGR_KEU, STAFF_KEU | Riwayat transfer per item |
| 42 | POST | /api/v1/pdo-details/{detailId}/transfers | MGR_KEU, STAFF_KEU | Catat transfer baru |
| 43 | PUT | /api/v1/transfer-entries/{id} | MGR_KEU, STAFF_KEU | Koreksi transfer |
| 44 | POST | /api/v1/pdo/{id}/close | MGR_KEU | Tutup PDO manual |
| 45 | GET | /api/v1/pdo-supplementary | Semua | Daftar PDO Tambahan aktif |
| 46 | POST | /api/v1/pdo-supplementary | KERANI | Buat PDO Tambahan |
| 47 | GET | /api/v1/pdo-supplementary/{id} | Semua | Detail PDO Tambahan |
| 48 | PUT | /api/v1/pdo-supplementary/{id} | KERANI | Edit header PDO Tambahan |
| 49 | POST | /api/v1/pdo-supplementary/{id}/details | KERANI | Tambah item ke PDO Tambahan |
| 50 | PUT | /api/v1/pdo-supplementary/{id}/details/{detailId} | KERANI | Edit item PDO Tambahan |
| 51 | DELETE | /api/v1/pdo-supplementary/{id}/details/{detailId} | KERANI | Hapus item PDO Tambahan |
| 52 | POST | /api/v1/pdo-supplementary/{id}/submit | KERANI | Submit PDO Tambahan |
| 53 | POST | /api/v1/pdo-supplementary/{id}/approve | ASISTEN, MGR, DIR | Approve PDO Tambahan |
| 54 | POST | /api/v1/pdo-supplementary/{id}/reject | ASISTEN, MGR, DIR | Reject PDO Tambahan |
| 55 | POST | /api/v1/pdo-supplementary/{id}/merge | ADMIN, DIR | Merge PDO Tambahan ke Bulanan |
| 56 | GET | /api/v1/pdo/{id}/realizations | Semua | Ringkasan realisasi PDO |
| 57 | GET | /api/v1/pdo/{id}/realizations/items | Semua | Realisasi per item |
| 58 | GET | /api/v1/realization-entries | Semua | History entri realisasi satu item |
| 59 | POST | /api/v1/realization-entries | KERANI, STAFF_PURCHASING | Catat realisasi baru |
| 60 | PUT | /api/v1/realization-entries/{id} | KERANI | Edit realisasi |
| 61 | DELETE | /api/v1/realization-entries/{id} | KERANI | Hapus realisasi |
| 62 | POST | /api/v1/realization-entries/{id}/attachments | KERANI, STAFF_PURCHASING | Upload bukti |
| 63 | DELETE | /api/v1/realization-entries/{id}/attachments/{id} | KERANI | Hapus bukti |
| 64 | GET | /api/v1/dashboard | Semua | KPI + grafik dashboard |
| 65 | GET | /api/v1/dashboard/category-summary | Semua | Breakdown per kategori |
| 66 | GET | /api/v1/reports/realization | Semua | Laporan realisasi |
| 67 | GET | /api/v1/reports/over-budget | Semua | Laporan over budget |
| 68 | GET | /api/v1/reports/missing-proof | Semua | Laporan bukti belum lengkap |
| 69 | GET | /api/v1/reports/recap | Semua | Rekapitulasi digital |
| 70 | POST | /api/v1/reports/export | Semua | Trigger export PDF/Excel |
| 71 | GET | /api/v1/reports/export/{jobId} | Semua | Cek status & download export |
| 72 | GET | /api/v1/settings | ADMIN | Daftar konfigurasi sistem |
| 73 | PUT | /api/v1/settings | ADMIN | Update konfigurasi |
| 74 | POST | /api/v1/settings/wa-test | ADMIN | Test koneksi WhatsApp gateway |
| 75 | GET | /api/v1/notification-templates | ADMIN | Daftar template notifikasi |
| 76 | PUT | /api/v1/notification-templates/{id} | ADMIN | Update template notifikasi |

**Total: 76 endpoint**

