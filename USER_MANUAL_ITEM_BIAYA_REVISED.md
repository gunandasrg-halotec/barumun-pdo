# User Manual: Membuat Item Biaya Baru di PDO

**Versi:** 2.0 (Revised)  
**Update:** 24 Juni 2026

---

## Panduan Cepat

Untuk membuat item biaya baru di sistem PDO (Dana Operasional Kebun), ikuti langkah-langkah berikut:

### 1. Akses Halaman Form
- Buka aplikasi PDO dan navigasikan ke **Master Data > Item Biaya > Tambah Item Biaya**
- URL: `https://pdo.barumun-plantation.com/master/item/buat`

---

## Penjelasan Field Form

### Field Wajib Diisi (Mandatory)

#### **Sub-Kategori Induk** ⭐
- Pilih kategori induk untuk item biaya yang akan dibuat
- Contoh: Pupuk, Pestisida, Biaya Tenaga Kerja, Biaya Operasional, Jasa Profesional, dll
- **Validasi:** Harus memilih salah satu dari daftar yang tersedia

#### **Kode Item** ⭐
- Kode unik untuk identifikasi item
- Format: 3-4 karakter + nomor urut (contoh: `TK.001`, `P1.003`, `O2.005`)
- **Ketentuan:** Harus unik, tidak boleh sama dengan item lain
- **Saran:** Gunakan kode konsisten per kategori
  - `TK` = Tenaga Kerja
  - `P` = Pupuk
  - `O` = Operasional
  - `JP` = Jasa Profesional
- **Validasi:** Wajib diisi, minimal 1 karakter

#### **Nama Item** ⭐
- Nama deskriptif dan jelas untuk item biaya
- Contoh: "Upah Tenaga Kerja Harian", "Pupuk Urea 46%", "Konsultasi Teknis"
- **Validasi:** Wajib diisi, minimal 1 karakter

---

### Field Opsional (Optional)

#### **No. Akun**
- Nomor rekening/akun untuk keperluan pencatatan akuntansi
- Format: Biasanya mengikuti standar COA (Chart of Accounts)
- Contoh: `6-1001` (Biaya Operasional), `5-2001` (Biaya Tenaga Kerja)
- **Catatan:** Biarkan kosong jika belum ada integrasi akuntansi

#### **Satuan**
- Unit pengukuran untuk item ini
- Contoh: `kg`, `liter`, `jam`, `orang`, `orang-hari`, `ton`, dll
- **Fungsi:** Membantu dalam pencatatan dan estimasi biaya
- **Catatan:** Opsional, tapi recommended untuk akurasi

#### **Tarif (Rate)**
- Nilai standar/default biaya per satuan
- Contoh: Jika satuan `orang-hari`, tarif bisa `150000` (Rp 150.000/orang-hari)
- **Tipe:** Angka desimal
- **Catatan:** Dapat diubah saat melakukan entry biaya aktual

#### **Mode Input**
- Pilihan metode bagaimana data biaya diinput ke sistem
- **Default:** Manual

#### **Catatan**
- Deskripsi tambahan atau keterangan spesial tentang item
- Contoh: "Untuk pengolahan tanah awal musim", "Supplier preferensi dari PT XYZ"
- **Format:** Text area, bisa multiple line

---

## 🔧 FIELD PENTING #1: MODE INPUT

### **Apa itu Mode Input?**
**Mode Input** = **Metode bagaimana data biaya diinput/dicatat ke sistem PDO**

Mode input menentukan **siapa yang input data** dan **kapan data tersedia** di form PDO.

---

### **Opsi 1: MANUAL** ✍️

#### Pengertian
- Data biaya **diinput manual oleh user** setiap kali membuat PDO
- User **melihat dan mengisi nilai** secara langsung di form PDO
- Sistem **tidak otomatis menarik** data dari sistem lain

#### Kapan Menggunakan
- ✅ Biaya yang **bervariasi** setiap periode (tidak fixed/pasti)
- ✅ Biaya yang **tergantung realisasi actual** (harian, project-based)
- ✅ Biaya yang **belum ada integrasi** dengan sistem lain (HR, payroll, dll)
- ✅ Biaya yang memerlukan **judgment/estimasi** dari user

#### Contoh Kasus
```
UPAH TENAGA KERJA HARIAN

Mode: MANUAL

Saat buat PDO:
┌────────────────────────────────┐
│ Item: Upah Tenaga Kerja Harian │
│ Jumlah: ___________ Rp         │  ← User input manual setiap bulan
│         (user isi nilai)       │
└────────────────────────────────┘

User entry berdasarkan data dari:
- Absensi tenaga kerja
- Laporan HR
- Realisasi jumlah pekerja per hari
- Kalkulasi manual: 100 orang × Rp 150.000 = Rp 15.000.000
```

---

### **Opsi 2: AUTO EXTERNAL** 🔄

#### Pengertian
- Data biaya **otomatis ditarik/sync** dari sistem eksternal (HR, Payroll, Timesheet, dll)
- Data **langsung tampil** di form PDO **tanpa user input manual**
- Sistem PDO **terintegrasi** dengan sistem sumber data

#### Kapan Menggunakan
- ✅ Ada sistem eksternal yang sudah terintegrasi (HR, Payroll, Timesheet)
- ✅ Data **sudah dihitung/ditetapkan** di sistem eksternal
- ✅ Ingin **minimize manual entry** dan reduce error
- ✅ Data adalah **real-time dari source of truth** (sistem payroll)

#### Contoh Kasus
```
GAJI KARYAWAN TETAP

Mode: AUTO EXTERNAL

Sistem Payroll mencatat:
├─ Gaji Pokok: Rp 5.000.000
├─ Tunjangan Transport: Rp 500.000
├─ Tunjangan Makan: Rp 500.000
└─ Total: Rp 6.000.000

Saat buat PDO:
┌────────────────────────────────┐
│ Item: Gaji Karyawan Tetap      │
│ Jumlah: Rp 6.000.000           │  ← Auto-tampil dari Payroll
│         (auto dari Payroll)    │     Tidak perlu user input
└────────────────────────────────┘

User hanya perlu VERIFIKASI data, bukan input ulang
```

---

### **PERBANDINGAN MODE INPUT:**

| Aspek | MANUAL | AUTO EXTERNAL |
|---|---|---|
| **Siapa input data** | User (manual di PDO form) | Sistem (auto dari HR/Payroll/Timesheet) |
| **Kapan data tampil** | User input di setiap PDO | Auto tampil saat PDO dibuka |
| **Kolom di form** | Text field kosong (perlu diisi) | Pre-filled (read-only atau editable) |
| **Akurasi** | Tergantung ketelitian user | Lebih akurat (dari sistem sumber) |
| **Waktu input** | Lebih lama (manual entry) | Lebih cepat (tinggal verifikasi) |
| **Risiko** | Lupa input, salah hitung | Minimal (auto sync dari sistem) |
| **Kapan pakai** | Biaya bervariasi, belum integrasi | Biaya fixed/terstruktur, ada integrasi |
| **Contoh USE CASE** | Upah harian variable, material cost | Gaji karyawan, asuransi, sewa rutin |

---

## 🔄 FIELD PENTING #2: RUTIN (ITEM RUTIN)

### **Apa itu Item Rutin?**
**Item Rutin** = **Apakah item biaya ini adalah biaya periodik/berulang yang PASTI ada setiap PDO?**

Item rutin akan **otomatis muncul** di form PDO tanpa perlu user menambahkan secara manual.

---

### **Jika TIDAK Dicentang (❌ NOT ROUTINE):**

```
Saat user membuka form PDO:

Step 1: Form PDO terbuka
Step 2: Daftar item yang tersedia:
        ├─ Pupuk Urea (Rutin)
        ├─ Gaji Karyawan (Rutin)
        └─ ❌ Upah Tenaga Kerja TIDAK muncul (karena not routine)

Step 3: User harus MANUAL menambahkan item
        "Klik tombol: Tambah Item Biaya"
        
Step 4: Pilih item dari dropdown, baru bisa entry

⚠️ RISIKO: User lupa menambahkan item penting!
```

---

### **Jika Dicentang (✅ ROUTINE):**

```
Saat user membuka form PDO:

Step 1: Form PDO terbuka
Step 2: Daftar item yang tersedia:
        ├─ Pupuk Urea (Rutin) ✅
        ├─ Upah Tenaga Kerja (Rutin) ✅ ← AUTO-MUNCUL!
        └─ Gaji Karyawan (Rutin) ✅

Step 3: User LANGSUNG bisa entry nilai jumlah
        "Upah Tenaga Kerja: _________ Rp"

Step 4: Tidak perlu manual tambah item, langsung entry & simpan

✅ BENEFIT: Jaminan item penting tidak terlupakan!
```

---

### **Kapan Item Harus RUTIN (✅ DICENTANG)?**

Centang "Item Rutin" untuk biaya-biaya yang:
- ✅ **Periodik/berulang** setiap periode (bulanan, mingguan, dll)
- ✅ **Pasti ada** (walaupun jumlahnya bervariasi)
- ✅ **Critical/penting** untuk dilacak
- ✅ **Wajib dicatat** untuk akuntansi & laporan keuangan

**Contoh yang HARUS RUTIN:**
- ✅ Upah tenaga kerja (ada atau tidak panen, upah harus dibayar)
- ✅ Gaji karyawan tetap (fixed setiap bulan)
- ✅ Sewa peralatan kontrak (sudah agreement)
- ✅ Pemeliharaan fasilitas rutin (maintenance berkala)
- ✅ Biaya operasional standar (listrik, air, komunikasi)

**Contoh yang TIDAK RUTIN:**
- ❌ Biaya perbaikan mesin (hanya saat ada kerusakan)
- ❌ Biaya tambahan project (hanya saat ada project khusus)
- ❌ Konsultasi expert (only when needed)
- ❌ Biaya impor barang (seasonal, tidak setiap bulan)

---

### **Opsi Kebun (Jika Rutin = ✅ YES):**

Setelah centang "Item Rutin", muncul section untuk memilih kebun mana yang berlaku:

```
☑ Item Rutin
└─ Berlaku Untuk:
   
   Option A: Semua Kebun (default)
   ☑ Semua Kebun
   
   Option B: Kebun Tertentu
   ☐ Kebun Utama (KU)
   ☐ Kebun Utara (KT)
   ☐ Kebun Barat (KB)
   ☐ Kebun Timur (KR)
```

#### Penjelasan Opsi:

**A. Semua Kebun:**
```
Item "Upah Tenaga Kerja" akan auto-muncul untuk SEMUA kebun:
├─ Kebun Utama → Upah Tenaga Kerja (auto)
├─ Kebun Utara → Upah Tenaga Kerja (auto)
├─ Kebun Barat → Upah Tenaga Kerja (auto)
└─ Kebun Timur → Upah Tenaga Kerja (auto)

Setiap kebun punya upah sendiri dengan jumlah berbeda
```

**B. Kebun Tertentu:**
```
Item "Gaji Manajer Kebun" hanya untuk 2 kebun (karena manajer tidak semua kebun):
├─ Kebun Utama → Gaji Manajer (auto)
├─ Kebun Utara → Gaji Manajer (auto)
├─ Kebun Barat → ❌ TIDAK ada (tidak ada manajer khusus)
└─ Kebun Timur → ❌ TIDAK ada (dikelola manajer Kebun Utara)
```

---

## 💱 FIELD PENTING #3: SPLIT TRANSFER

### **Apa itu Split Transfer?**
**Split Transfer** = **Saat melakukan transfer dana item ini, apakah bisa dialokasikan/dibagi ke 2+ tujuan/rekening berbeda?**

Split transfer menentukan **kemampuan user untuk split/membagi dana ke multiple destination** saat melakukan transfer di halaman transfer dana.

---

### **Jika TIDAK Dicentang (❌ NO SPLIT):**

```
HALAMAN TRANSFER DANA:

Item: Upah Tenaga Kerja Harian
Jumlah: Rp 50.000.000

Pilihan Transfer (SINGLE DESTINATION):
┌──────────────────────────────────────┐
│ Tujuan Transfer:                     │
│ ☑ Rekening Kebun    (HANYA INI)      │
│ ☐ Rekening Pribadi                   │
│ ☐ Rekening Lainnya                   │
│                                      │
│ Hasil:                               │
│ Rp 50.000.000 → Rekening Kebun       │
│                (100%, satu tujuan)    │
└──────────────────────────────────────┘

⚠️ Tidak bisa split ke multiple tujuan
   Harus semua ke 1 rekening saja
```

---

### **Jika Dicentang (✅ YES SPLIT):**

```
HALAMAN TRANSFER DANA:

Item: Upah Mandor + Komisi
Jumlah: Rp 5.000.000

Pilihan Transfer (MULTIPLE DESTINATION / SPLIT):
┌──────────────────────────────────────┐
│ Tujuan Transfer (SPLIT):             │
│ ☑ Rekening Kebun      Rp 3.000.000   │
│ ☑ Rekening Pribadi    Rp 2.000.000   │
│ ☐ Rekening Lainnya                   │
│                                      │
│ Hasil:                               │
│ Rp 3.000.000 → Rekening Kebun        │
│ Rp 2.000.000 → Rekening Pribadi      │
│ (Total Rp 5.000.000, 2 tujuan)       │
└──────────────────────────────────────┘

✅ Bisa split ke multiple rekening sesuai kebutuhan
   Dana otomatis didistribusikan sesuai alokasi
```

---

### **Kapan Item Perlu SPLIT TRANSFER (✅ DICENTANG)?**

Centang "Split Transfer" untuk biaya yang perlu dialokasikan ke **multiple destination/rekening**:

| Skenario | Split? | Alasan |
|---|---|---|
| **Upah tenaga kerja regular** | ❌ NO | Transfer langsung ke 1 rekening saja (Rekening Kebun) |
| **Upah mandor + komisi pribadi** | ✅ YES | Perlu split: gaji pokok → Rekening Kebun, komisi → Rekening Pribadi |
| **Upah supervisor dengan insentif** | ✅ YES | Gaji pokok → Rekening Kebun, bonus/insentif → Rekening Pribadi |
| **Upah training fee** | ✅ YES | Sebagian → Rekening Trainer Pribadi, sebagian → Rekening Kebun |
| **Biaya import barang** | ❌ NO | Transfer ke supplier saja, 1 rekening |
| **Biaya listrik (multi-department)** | ✅ YES | Bisa split ke Rekening Dept A, Dept B, Dept C |
| **Gaji karyawan tetap** | ❌ NO | Langsung ke Rekening Karyawan saja |

---

### **Contoh Kasus Detail: UPAH MANDOR**

```
═══════════════════════════════════════════════════════════
KASUS: Upah Mandor Kebun Utama
═══════════════════════════════════════════════════════════

Breakdown Upah:
├─ Upah Pokok Mandor: Rp 3.000.000
│  (Biaya operasional kebun, harus tetap di rekening kebun)
│
└─ Komisi/Bonus Mandor: Rp 2.000.000
   (Insentif pribadi, diberikan langsung ke mandor)

Total Upah: Rp 5.000.000

Setting Item:
┌─────────────────────────────────────┐
│ Nama: Upah Mandor Kebun Utama       │
│ Kode: TK.002                        │
│ Rutin: ✅ YES (tetap setiap bulan)  │
│ Split Transfer: ✅ YES (ke 2 tujuan)│
└─────────────────────────────────────┘

SAAT TRANSFER (Split = YES):

┌─────────────────────────────────────────────┐
│ HALAMAN TRANSFER DANA                       │
│                                             │
│ Item: Upah Mandor Kebun Utama               │
│ Jumlah Total: Rp 5.000.000                  │
│                                             │
│ Alokasi Transfer:                           │
│ ☑ Rekening Kebun      Rp 3.000.000 (60%)   │
│ ☑ Rekening Pribadi    Rp 2.000.000 (40%)   │
│ ☐ Rekening Lainnya                         │
│                                             │
│ Total: Rp 5.000.000 ✓                       │
│                                             │
│ [TRANSFER BUTTON]                           │
└─────────────────────────────────────────────┘

Hasil:
✅ Rp 3.000.000 → Rekening Kebun (tetap operasional)
✅ Rp 2.000.000 → Rekening Pribadi Mandor (insentif)
✅ Kedua transfer sekaligus dalam 1 entry (efficient!)

═══════════════════════════════════════════════════════════
```

---

### **Opsi Kebun (Jika Split Transfer = ✅ YES):**

Setelah centang "Split Transfer", muncul section untuk memilih kebun mana yang bisa menggunakan fitur split:

```
☑ Split Transfer
└─ Berlaku Untuk:
   
   Option A: Semua Kebun (default)
   ☑ Semua Kebun
   
   Option B: Kebun Tertentu
   ☐ Kebun Utama (KU)
   ☐ Kebun Utara (KT)
   ☐ Kebun Barat (KB)
   ☐ Kebun Timur (KR)
```

#### Penjelasan:

**A. Semua Kebun:**
```
Item ini bisa di-SPLIT untuk SEMUA kebun:
├─ Kebun Utama → Saat transfer, bisa split ke multiple rekening
├─ Kebun Utara → Saat transfer, bisa split ke multiple rekening
├─ Kebun Barat → Saat transfer, bisa split ke multiple rekening
└─ Kebun Timur → Saat transfer, bisa split ke multiple rekening

Setiap kebun punya opsi split untuk item ini
```

**B. Kebun Tertentu:**
```
Item ini hanya bisa di-SPLIT untuk beberapa kebun:
├─ Kebun Utama → Bisa split ✅
├─ Kebun Utara → Bisa split ✅
├─ Kebun Barat → Transfer normal (tidak split) ❌
└─ Kebun Timur → Transfer normal (tidak split) ❌
```

---

## ✅ Checkbox Lainnya

### **Aktif (is_active)**
- **Fungsi:** Menentukan apakah item ini bisa digunakan atau tidak
- **Checked (✅):** Item aktif dan bisa dipilih saat input PDO/Transfer
- **Unchecked (☐):** Item tidak aktif (tersembunyi dari list)
- **Default:** Checked (✅)
- **Catatan:** 
  - Gunakan uncheck jika item sudah tidak digunakan lagi
  - Jangan dihapus agar history/laporan tetap valid
  - Lebih baik ubah tarif ke 0 jika masih ragu

---

## 📊 Workflow Lengkap: Contoh Praktis

### **Contoh 1: UPAH TENAGA KERJA REGULAR**

```
═════════════════════════════════════════════════════════════════
UPAH TENAGA KERJA HARIAN (Regular)
═════════════════════════════════════════════════════════════════

SETTING ITEM:
├─ Nama: Upah Tenaga Kerja Harian
├─ Kode: TK.001
├─ Sub-Kategori: Biaya Tenaga Kerja
├─ Satuan: orang-hari
├─ Tarif: 150000
├─ Mode Input: ✍️ MANUAL
│  └─ Alasan: Jumlah pekerja bervariasi setiap hari
│
├─ Item Rutin: ✅ YES
│  └─ Berlaku Untuk: Semua Kebun
│     └─ Alasan: Upah pasti ada setiap bulan
│
├─ Split Transfer: ❌ NO
│  └─ Alasan: Upah langsung ke Rekening Kebun saja
│
├─ Aktif: ✅ YES
└─ Catatan: "Upah harian pekerja lepas, dibayar akhir bulan"

SAAT GUNAKAN:

1️⃣ Buka PDO Bulan Juni:
   ✅ "Upah Tenaga Kerja Harian" LANGSUNG muncul
      (karena Rutin = YES)

2️⃣ User entry jumlah:
   Upah Tenaga Kerja Harian: Rp 45.000.000

3️⃣ Saat Transfer Dana:
   • Mode: NORMAL (tidak ada split)
   • Transfer: Rp 45.000.000 → Rekening Kebun (100%)
   
4️⃣ Selesai!

═════════════════════════════════════════════════════════════════
```

---

### **Contoh 2: UPAH MANDOR + KOMISI**

```
═════════════════════════════════════════════════════════════════
UPAH MANDOR + KOMISI PRIBADI
═════════════════════════════════════════════════════════════════

SETTING ITEM:
├─ Nama: Upah Mandor Kebun Utama
├─ Kode: TK.002
├─ Sub-Kategori: Biaya Tenaga Kerja
├─ Satuan: bulan
├─ Tarif: 5000000
├─ Mode Input: ✍️ MANUAL
│  └─ Alasan: Komisi bervariasi tergantung performa
│
├─ Item Rutin: ✅ YES
│  └─ Berlaku Untuk: Hanya Kebun Utama
│     └─ Alasan: Mandor tetap untuk Kebun Utama setiap bulan
│
├─ Split Transfer: ✅ YES
│  └─ Berlaku Untuk: Semua Kebun (semua bisa pakai split jika ada mandor)
│     └─ Alasan: Perlu split gaji pokok & komisi pribadi
│
├─ Aktif: ✅ YES
└─ Catatan: "Rp 3jt gaji pokok + Rp 2jt komisi, tergantung target"

SAAT GUNAKAN:

1️⃣ Buka PDO Bulan Juni (untuk Kebun Utama):
   ✅ "Upah Mandor Kebun Utama" LANGSUNG muncul
      (karena Rutin = YES untuk Kebun Utama)

2️⃣ User entry jumlah:
   Upah Mandor: Rp 5.000.000 (3jt pokok + 2jt komisi)

3️⃣ Saat Transfer Dana:
   • Mode: SPLIT (karena Split Transfer = YES)
   • Alokasi:
     ├─ Rp 3.000.000 → Rekening Kebun (gaji pokok)
     └─ Rp 2.000.000 → Rekening Pribadi Mandor (komisi)
   
4️⃣ Selesai!

═════════════════════════════════════════════════════════════════
```

---

### **Contoh 3: KONSULTASI AGRONOMIS (Rutin + Split)**

```
═════════════════════════════════════════════════════════════════
KONSULTASI TEKNIS AGRONOMIS
═════════════════════════════════════════════════════════════════

SETTING ITEM:
├─ Nama: Konsultasi Teknis Agronomis
├─ Kode: JP.001
├─ Sub-Kategori: Jasa Profesional
├─ Satuan: session
├─ Tarif: 5000000
├─ Mode Input: ✍️ MANUAL
│  └─ Alasan: Jadwal konsultasi bervariasi
│
├─ Item Rutin: ✅ YES
│  └─ Berlaku Untuk: Semua Kebun
│     └─ Alasan: Konsultasi rutin setiap bulan
│
├─ Split Transfer: ✅ YES
│  └─ Berlaku Untuk: Hanya Kebun Utama & Kebun Utara
│     └─ Alasan: Karena hanya dua kebun yang pakai consultant ini
│
├─ Aktif: ✅ YES
└─ Catatan: "Konsultasi dengan Dr. Agro Specialist, fee Rp 5jt/session"

SAAT GUNAKAN:

1️⃣ Buka PDO Bulan Juni:
   ✅ "Konsultasi Teknis Agronomis" LANGSUNG muncul untuk semua kebun
      (karena Rutin = YES untuk Semua Kebun)

2️⃣ User entry jumlah:
   Konsultasi Teknis: Rp 5.000.000

3️⃣ Saat Transfer Dana (untuk Kebun Utama):
   • Mode: SPLIT (bisa split, bisa tidak)
   
   Scenario A - Transfer Normal:
   └─ Rp 5.000.000 → Rekening Consultant

   Scenario B - Transfer Split (jika dipakai bersama):
   ├─ Rp 3.000.000 → Rekening Consultant
   └─ Rp 2.000.000 → Rekening Kebun (retain untuk expenses)

4️⃣ Untuk Kebun Barat & Kebun Timur:
   • Konsultasi masih muncul di PDO (karena Rutin = YES untuk Semua)
   • Tapi saat transfer, HANYA bisa normal (tidak split)
     └─ Karena Split berlaku hanya untuk KU & KT

═════════════════════════════════════════════════════════════════
```

---

## 📋 Validasi & Error Handling

### Pesan Error Umum:

| Pesan Error | Penyebab | Solusi |
|---|---|---|
| "Pilih sub-kategori" | Belum memilih sub-kategori | Pilih sub-kategori dari dropdown |
| "Kode wajib diisi" | Field Kode kosong | Isi kode item (contoh: TK.001) |
| "Nama wajib diisi" | Field Nama kosong | Isi nama item yang deskriptif |
| "Kode sudah digunakan" | Kode duplikat | Ubah kode menjadi unique |
| "Item gagal disimpan" | Error umum server | Cek koneksi, atau hubungi admin |

---

## 💡 Tips & Best Practices

✅ **Lakukan:**
- Gunakan kode yang **konsisten dan terstruktur**:
  - `TK` = Tenaga Kerja
  - `P` = Pupuk & Bahan Kimia
  - `O` = Operasional
  - `JP` = Jasa Profesional
  - `PS` = Pestisida
- **Isi Satuan dan Tarif** untuk memudahkan estimasi biaya
- **Centang "Item Rutin"** untuk biaya periodik yang pasti setiap bulan
- **Centang "Split Transfer"** jika biaya perlu dialokasikan ke multiple rekening
- Gunakan **Catatan** untuk keterangan penting atau peringatan
- **Jangan lupa verifikasi**: ringkasan sebelum klik Simpan

❌ **Jangan Lakukan:**
- Jangan buat item dengan **kode duplikat**
- Jangan gunakan fitur **"Split Transfer" jika tidak perlu** (hanya untuk multiple destination)
- Jangan lupa **isi nama item** yang jelas dan deskriptif
- Jangan uncheck **"Aktif"** jika masih ragu, ubah tarif ke 0 dulu
- Jangan bingung antara "Rutin" (periodik) dan "Split" (multiple destination)

---

## Urutan Prioritas: Kapan Centang Apa?

### **BAGAN KEPUTUSAN:**

```
MULAI: Membuat Item Biaya Baru
│
├─ "Apakah biaya ini PASTI ada setiap periode?"
│  ├─ YES → Centang ✅ RUTIN
│  └─ NO → Jangan centang RUTIN
│
└─ "Apakah biaya ini perlu dibagi ke MULTIPLE REKENING?"
   ├─ YES → Centang ✅ SPLIT TRANSFER
   │        (Contoh: gaji + komisi, fee + retention)
   │
   └─ NO → Jangan centang SPLIT TRANSFER
          (Biaya ke 1 rekening saja)

CONTOH:
├─ Upah Rutin: RUTIN=YES, SPLIT=NO
├─ Upah Mandor (ada komisi): RUTIN=YES, SPLIT=YES
├─ Biaya Perbaikan (tidak rutin): RUTIN=NO, SPLIT=NO
└─ Biaya Training Shared: RUTIN=YES, SPLIT=YES
```

---

## FAQ: Pertanyaan yang Sering Diajukan

**Q: Apa bedanya "Rutin" dan "Split Transfer"?**

A: 
- **Rutin**: Tentang **kapan item muncul** di PDO (auto-muncul atau tidak)
- **Split**: Tentang **kemampuan membagi ke multiple rekening** saat transfer

Contoh:
- Upah tenaga kerja → Rutin=YES (pasti ada), Split=NO (ke 1 rekening)
- Upah mandor → Rutin=YES (pasti ada), Split=YES (gaji+komisi ke 2 rekening)

---

**Q: Bisakah satu item memiliki BOTH "Rutin=YES" DAN "Split=YES"?**

A: Ya, bisa! Contoh: Upah Mandor dengan komisi (rutin setiap bulan, tapi perlu split ke gaji+komisi pribadi)

---

**Q: Jika unchecked "Aktif", apakah item dihapus?**

A: TIDAK. Item tetap tersimpan di database untuk keperluan history/laporan. Item hanya tidak muncul di dropdown/list untuk dipilih.

---

**Q: Jika hanya select kebun tertentu di "Rutin", apakah kebun lain tidak bisa pakai item ini?**

A: Benar. Item hanya auto-muncul untuk kebun yang dipilih. Kebun lain bisa pakai item ini **hanya dengan manual tambahkan** (klik tombol "Tambah Item").

---

**Q: Mode "Auto External" butuh apa?**

A: Butuh sistem eksternal yang terintegrasi dengan PDO (HR system, payroll, timesheet, dll). Data otomatis sync dari sistem tersebut ke PDO.

---

**Q: Berapa jumlah rekening maksimal untuk split transfer?**

A: Unlimited. User bisa split ke 2, 3, 4, atau lebih banyak rekening sesuai kebutuhan.

---

## Workflow Setelah Simpan

Setelah klik tombol **"Simpan"**, sistem akan:
1. ✅ Validasi data yang diinput
2. ✅ Simpan ke database
3. ✅ Tampilkan notifikasi "Item berhasil dibuat"
4. ✅ Redirect ke halaman Master Data > Item Biaya

**Item baru sekarang siap digunakan di:**
- ✅ Form PDO (jika Rutin=YES)
- ✅ Halaman Transfer Dana (untuk split jika perlu)
- ✅ Master Data Item (untuk edit/manage)

---

## Ringkasan Quick Reference

| Aspek | Rutin | Split Transfer | Mode Input |
|---|---|---|---|
| **Definisi** | Biaya periodik/berulang | Multiple destination transfer | Metode input data |
| **Fungsi** | Auto-muncul di PDO | Alokasi ke multiple rekening | Siapa/bagaimana input |
| **Kapan Centang** | Biaya pasti setiap bulan | Perlu split ke 2+ rekening | Auto/Manual sesuai integrasi |
| **Contoh USE CASE** | Upah, gaji, sewa rutin | Gaji+komisi, fee+retention | Upah harian atau gaji auto |
| **Hasil** | Item otomatis tampil | User bisa split saat transfer | Data tampil manual atau auto |

---

**Versi:** 2.0 (Revised)  
**Terakhir diupdate:** 24 Juni 2026  
**Kontak Support:** Hubungi Admin PDO untuk pertanyaan lebih lanjut

---

## Document Change Log

| Versi | Tanggal | Perubahan |
|---|---|---|
| 1.0 | 24-06-2026 | Initial release |
| 2.0 | 24-06-2026 | **REVISED:** Perbaikan penjelasan Split Transfer (multiple destination, bukan multiple kebun) + contoh kasus yang lebih akurat |
