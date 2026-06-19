# UI/UX Specification
# Sistem Dana Operasional Kebun (PDO)
# PT Barumun Palma Nauli

---

| Atribut | Detail |
|---|---|
| Versi | 1.1 — UI-01, UI-02, ED-01, ED-02, ED-03 Dikonfirmasi |
| Tanggal | 18 Juni 2026 |
| Referensi PRD | v1.7 |
| Referensi TAD | v1.1 (React 19 + Vite + Shadcn/ui + Tailwind CSS) |
| Referensi Mockup | pdo_mockup.html |

---

## Daftar Isi

1. Design System Tokens
2. Layout & Navigation
3. Matriks Akses Role per Halaman
4. Spesifikasi Halaman
5. Komponen UI Reusable
6. Behavior & Interaksi Global
7. Responsive Behavior
8. Implementasi React 19

---

## 1. Design System Tokens

Semua token diambil langsung dari CSS `:root` di `pdo_mockup.html`. Implementasikan sebagai CSS custom properties dan Tailwind config.

### 1.1 Warna

```css
:root {
  /* Background & Surface */
  --bg:      #f5f7f3;   /* Background halaman */
  --panel:   #ffffff;   /* Background card/panel */

  /* Text */
  --ink:     #17231d;   /* Text utama */
  --muted:   #657267;   /* Text sekunder, label, hint */

  /* Border */
  --line:    #dde7df;   /* Border card, separator */

  /* Brand & Action */
  --green:   #0f6b45;   /* Primary action, sidebar aktif, brand */
  --green2:  #16a36d;   /* Secondary green, grafik, progress */
  --mint:    #e8f6ef;   /* Background step aktif, highlight hijau muda */

  /* Semantic */
  --blue:    #2563eb;   /* Status In Review, badge review */
  --yellow:  #d99106;   /* Warning, badge warnb */
  --red:     #dc2626;   /* Danger, reject, over budget */
  --purple:  #7c3aed;   /* Badge auto_external */
  --slate:   #334155;   /* Badge closed, text sekunder gelap */

  /* Shadow & Radius */
  --shadow:  0 18px 45px rgba(17,40,29,.10);
  --radius:  18px;
}
```

**Sidebar:** background `#0c3d2c`, text `#d9f5e7`, muted text `#a8d4be`

**Panduan penggunaan warna:**

| Warna | Kapan digunakan |
|---|---|
| `--green` | Tombol primary, sidebar aktif, brand logo |
| `--green2` | Progress bar, grafik, trend positif |
| `--mint` | Step wizard aktif, highlight baris terpilih |
| `--blue` | Badge "In Review", badge "review" |
| `--yellow` | Badge "Butuh Bukti", "Ada Selisih", "Warnb" |
| `--red` | Badge "Reject", "Over Budget", tombol danger |
| `--purple` | Badge `auto_external` mode input |
| `--slate` | Badge "Closed" |
| `--muted` | Label field, hint, text sekunder |

### 1.2 Tipografi

```css
font-family: Inter, ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
```

| Elemen | Font Size | Font Weight | Color |
|---|---|---|---|
| Page title (h2) | 28px | 950 | `--ink` |
| Section title (h3) | 17px | 850 | `--ink` |
| KPI value | 24px | 950 | `--ink` |
| Body text | 14px | 400 | `--ink` |
| Table header | 11px | 700, uppercase, letter-spacing .04em | `#526257` |
| Table cell | 13px | 400 | `--ink` |
| Label field | 12px | 850 | `--muted` |
| Mini / hint | 12px | 400 | `--muted` |
| Badge | 12px | 850 | sesuai variant |
| Nav button | 14px | 750 | `#d9f5e7` |

### 1.3 Spacing & Radius

| Elemen | Nilai |
|---|---|
| Card border-radius | `18px` (var `--radius`) |
| Button border-radius | `12px` |
| Badge border-radius | `999px` (pill) |
| Input border-radius | `12px` |
| Table wrapper border-radius | `16px` |
| Modal border-radius | `22px` |
| Drawer width | `460px` (max 95vw) |
| Sidebar width | `282px` |
| Main content padding | `22px 28px 40px` |
| Card padding | `18px` |
| Form field gap | `12px` |
| Nav button padding | `12px 13px` |

### 1.4 Elevation & Shadow

```css
/* Card shadow */
box-shadow: 0 18px 45px rgba(17,40,29,.10);

/* Button primary shadow */
box-shadow: 0 8px 18px rgba(15,107,69,.18);
```

### 1.5 Badge Variants

| Variant | Class | Background | Text Color | Kapan Digunakan |
|---|---|---|---|---|
| `approved` | `.approved` | `#ddf8e9` | `#0f6b45` | Sesuai, Rutin=Ya, Status Terverifikasi |
| `review` | `.review` | `#dbeafe` | `#1d4ed8` | Status In Review, tag Finance |
| `draft` | `.draft` | `#f1f5f9` | `#475569` | Status Draft, Rutin=Tidak |
| `warnb` | `.warnb` | `#fef3c7` | `#a16207` | Butuh Bukti, Ada Selisih, Sub-total |
| `reject` | `.reject` | `#fee2e2` | `#b91c1c` | Status Ditolak |
| `over` | `.over` | `#fee2e2` | `#b91c1c` | Over Budget |
| `ok` | `.ok` | `#dcfce7` | `#15803d` | Subtotal group row |
| `purple` | `.purple` | `#ede9fe` | `#6d28d9` | Mode input `auto_external` |
| `closed` | `.closed` | `#e2e8f0` | `#334155` | Status Closed |

---

## 2. Layout & Navigation

### 2.1 Struktur Layout Aplikasi

```
┌─────────────────────────────────────────────────────────────┐
│  Sidebar (282px, fixed, bg: #0c3d2c)                        │
│  ┌─────────────────────────────────────────────────────┐    │
│  │ Brand: Logo PDO + Nama Perusahaan                   │    │
│  │ Navigation: 8 menu item                             │    │
│  └─────────────────────────────────────────────────────┘    │
├─────────────────────────────────────────────────────────────┤
│  Main Content Area (flex: 1)                                │
│  ┌─────────────────────────────────────────────────────┐    │
│  │ Topbar: Search + Profile                            │    │
│  ├─────────────────────────────────────────────────────┤    │
│  │ Page Content (padding: 22px 28px 40px)              │    │
│  └─────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────┘
```

Grid: `grid-template-columns: 282px 1fr; min-height: 100vh`

### 2.2 Sidebar

- Background: `#0c3d2c` (hijau tua gelap)
- Position: `sticky; top: 0; height: 100vh`
- Padding: `24px 18px`

**Brand:**
- Logo: `44×44px`, border-radius `14px`, gradient `135deg, #16a36d, #d4af37`, teks "PDO" warna putih, font-weight 900
- Nama: "Dana Operasional Kebun" (17px, margin 0) + "PT Barumun Nauli" (12px, warna `#a8d4be`)

**Navigation (8 item):**

| Icon | Label | `data-page` | Badge |
|---|---|---|---|
| 📊 | Dashboard | `dashboard` | — |
| 📋 | Daftar PDO | `list` | Count PDO aktif |
| ➕ | Buat Pengajuan | `form` | — |
| 💸 | Realisasi Dana | `realisasi` | — |
| 📑 | Rekapitulasi | `rekap` | — |
| ⚙️ | Master Data | `master` | — |
| 📈 | Laporan | `laporan` | — |
| 🔧 | Pengaturan | `settings` | — |

Nav button state:
- Default: `background: transparent; color: #d9f5e7`
- Hover/Active: `background: rgba(255,255,255,.13)`

**Halaman tidak tampil di sidebar** (diakses via tombol dari halaman lain):
- `detail` — dari tombol "Detail" di Daftar PDO
- `inputRealisasi` — dari tombol di halaman Realisasi Dana
- `itemHistory` — dari tombol "↺ History" di Input Realisasi
- `approval` — dari klik badge status di Daftar PDO
- `addKategori`, `addSubKategori`, `addItemBiaya` — dari Master Data

### 2.3 Topbar

```
┌──────────────────────────────────────────────────────────┐
│ [Search Input (430px, border-radius 999px)]  [Badge][👤] │
└──────────────────────────────────────────────────────────┘
```

- Search: `width: 430px; max-width: 50vw; border-radius: 999px`
- Placeholder: `"Cari nomor PDO, kategori, sub-kategori, item, akun, atau keterangan biaya..."`
- Profile: Badge role aktif + Avatar (38×38px, border-radius 50%, bg: `--green`, font-weight 800, inisial nama)

---

## 3. Matriks Akses Role per Halaman

| Halaman | Admin | Kerani | Asisten | Mgr Kebun | Mgr Keuangan | Staff Keuangan | Direktur | Staff Purchasing |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| Dashboard | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Daftar PDO | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Buat PDO (form) | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Detail PDO | ✅ | ✅* | ✅* | ✅ | ✅ | ✅ | ✅ | ✅ |
| Approval Timeline | ✅ | ✅* | ✅* | ✅ | ✅ | ❌ | ✅ | ❌ |
| Tombol Approve | ❌ | ❌ | ✅* | ✅ | ✅ | ❌ | ✅ | ❌ |
| Realisasi Dana (list) | ✅ | ✅* | ✅* | ✅ | ✅ | ✅ | ✅ | ✅ |
| Input Realisasi | ❌ | ✅* | ❌ | ❌ | ❌ | ❌ | ❌ | ✅** |
| History Realisasi | ✅ | ✅* | ✅* | ✅ | ✅ | ✅ | ✅ | ✅ |
| Catat Transfer | ❌ | ❌ | ❌ | ❌ | ✅ | ✅ | ❌ | ❌ |
| Tutup PDO (tombol di Daftar PDO) | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ |
| Rekapitulasi | ✅ | ✅* | ✅* | ✅ | ✅ | ✅ | ✅ | ❌ |
| Laporan | ✅ | ✅* | ✅* | ✅ | ✅ | ✅ | ✅ | ❌ |
| Master Data | ✅ | 👁️ | 👁️ | 👁️ | 👁️ | 👁️ | 👁️ | ❌ |
| Tambah/Edit Master | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Pengaturan | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |

`*` = hanya unit kebun sendiri (Kerani, Asisten)
`**` = Staff Purchasing hanya bisa input dengan Sumber Dana "Rekening Utama Perusahaan"
`👁️` = read-only, tanpa aksi tambah/edit/hapus

---

## 4. Spesifikasi Halaman

### 4.1 Dashboard (`dashboard`)

**Akses:** Semua role. Kerani & Asisten hanya melihat data unit mereka.

**Hero Section:**
- H2: "Dashboard PDO [Bulan Tahun]" (contoh: "Dashboard PDO Februari 2026")
- Deskripsi: "Ringkasan pengajuan, transfer, realisasi, saldo, dan selisih dana operasional [Nama Unit]."
- Tombol: "Buat PDO Baru" → navigasi ke halaman `form`

**Filter Bar:**
```
[Dropdown: Periode] [Dropdown: Unit Kebun] [Dropdown: Kategori] [Dropdown: Sub-Kategori] [Tombol: Terapkan Filter]
```
- Periode: format "Februari 2026", "Maret 2026"
- Unit Kebun: hanya tampil untuk role lintas unit (Kerani otomatis di-lock ke unit sendiri)

**KPI Cards (5 kartu, grid 5 kolom):**

| # | Label | Nilai Contoh | Hint | Clickable? | Navigasi |
|---|---|---|---|---|---|
| 1 | Total Pengajuan | Rp 267,9 jt | "9 kategori · 17 sub-kategori" | ✅ | → `list` |
| 2 | Total Transfer | Rp 267,9 jt | "Sesuai PDO" | ❌ | — |
| 3 | Total Realisasi | Rp 254,4 jt | "Klik untuk input item" (trend down) | ✅ | → `inputRealisasi` |
| 4 | Saldo | Rp 13,5 jt | "5 item ada selisih" | ❌ | — |
| 5 | Item Belum Bukti | 2 | "Butuh upload bukti" (trend down) | ✅ | → `inputRealisasi` |

- Card clickable: `cursor: pointer; :hover { transform: translateY(-1px) }`
- Trend up: warna `--green2`; Trend down: warna `--red`

**Grafik Bar Horizontal (kiri, 1.15fr):**
- Title: "Pengajuan vs Realisasi per Kategori"
- Tombol kanan: "Filter" (ghost)
- Per baris: `[Nama Kategori (210px)] [Progress Bar] [Total (90px)]`
- Bar fill: `linear-gradient(90deg, var(--green), #71c99f)`, background `#eaf0ea`, height 12px, border-radius 999px
- Lebar bar = `(realisasi / pengajuan) × 100%`

**Donut Chart (kanan, 0.85fr):**
- Title: "Proporsi Biaya"
- Badge kanan: "94,9% terealisasi" (approved)
- Donut: `conic-gradient(#0f6b45 0 44%, #16a36d 44% 68%, #f0a61f 68% 84%, #2563eb 84% 94%, #94a3b8 94%)`
- Center text: Total transfer dalam format "Rp 267,9 jt" (font-weight 950, warna `--green`)
- Donut inner: `inset: 42px; border-radius: 50%; background: white`
- Legend di bawah: nama kategori + persentase

---

### 4.2 Daftar PDO (`list`)

**Akses:** Semua role (Kerani & Asisten hanya unit mereka).

**Hero Section:**
- H2: "Daftar PDO"
- Deskripsi: "Detail PDO dibuka dari tombol Detail. Approval timeline dibuka dengan mengklik status PDO."
- Tombol: "Buat PDO Baru" → `form` (hanya tampil untuk KERANI)

**Filter Bar:**
```
[Input: Cari PDO...] [Dropdown: Semua Status] [Tombol: Export Excel]
```

**Tabel (scrollable horizontal, min-width: 1060px):**

| Kolom | Tipe | Keterangan |
|---|---|---|
| Nomor PDO | Text bold | — |
| PT | Text | — |
| Unit | Text | — |
| Periode | Text | "Jun 2026" |
| Total Pengajuan | Number | Format Rupiah |
| Total Transfer | Number | Format Rupiah kumulatif |
| Total Realisasi | Number | Format Rupiah kumulatif |
| Saldo | Number | Format Rupiah (Transfer - Realisasi) |
| Status | Badge | Clickable → buka `approval` |
| Tipe | Badge | "Bulanan" / "Tambahan" |
| Dibuat Oleh | Text | Nama Kerani |
| Aksi | Buttons | "Detail" (ghost) + "Edit" (ghost, hanya jika Draft & KERANI) + **"Tutup PDO"** (danger, hanya jika Final & MANAJER_KEUANGAN) |

> **Catatan (CL-08):** Kolom "Remisi" dihapus dari tabel ini. Tidak ada referensi Remisi I/II di seluruh aplikasi.

**Status badge behavior:**
- `draft` → badge class `draft`, text "Draft"
- `submitted`, `reviewed_asisten`, `in_review_manager`, `in_review_direktur` → badge class `review`, text "In Review"
- `final` → badge class `approved`, text "Final"
- `closed` → badge class `closed`, text "Closed"
- **Semua badge status dapat diklik** → navigasi ke halaman `approval` (Approval Timeline)

**Tombol "Tutup PDO" (UI-02):**
- **Kapan muncul:** Hanya pada baris PDO dengan `status = final` DAN user yang login adalah `MANAJER_KEUANGAN`
- **Style:** Danger (`background: var(--red); color: white`)
- **Behavior:** Klik → buka Modal Tutup PDO
- **Modal Tutup PDO:**
```
┌───────────────────────────────────────────────┐
│ Tutup PDO: PDO-2026-06-KP-001                 │
│ Menutup PDO akan mencegah input realisasi dan  │
│ transfer baru. Tindakan ini tidak dapat          │
│ dibatalkan.                                     │
│                                                 │
│ Tanggal Penutupan: [Date input, min=hari ini,  │
│                     max=hari terakhir bulan]    │
│ Catatan (opsional): [Textarea]                  │
│                                                 │
│              [Batal]  [Tutup PDO (danger)]      │
└───────────────────────────────────────────────┘
```
- Tanggal penutupan: `type="date"`, min = hari ini, max = hari terakhir bulan periode PDO
- Setelah berhasil: status PDO di tabel berubah menjadi badge "Closed", tombol "Tutup PDO" menghilang, toast: "PDO berhasil ditutup"

**PDO Tambahan yang sudah merged:** TIDAK tampil di daftar ini.

**Empty State:**
```html
<div class="empty-state">Belum ada PDO. Klik "Buat PDO Baru" untuk memulai pengajuan.</div>
```
Styling: `padding: 24px; border: 1px dashed var(--line); border-radius: 16px; background: #fbfdfb; color: var(--muted)`

---

### 4.3 Form Buat/Edit PDO (`form`)

**Akses:** KERANI saja.

**Hero Section:**
- H2: "Buat / Edit PDO"
- Deskripsi: "Tambah baris sekarang menghasilkan baris kosong yang langsung bisa diinput. Dropdown sub-kategori dan item mengikuti pilihan di atasnya."
- Tombol kanan: [Simpan Draft] [Submit Pengajuan]
  - "Simpan Draft" → secondary style
  - "Submit Pengajuan" → primary style, buka modal konfirmasi

**Wizard Layout:** `grid-template-columns: 260px 1fr`

**Steps Panel (kiri):**
```
1. Informasi Umum     ← active (bg: mint, color: green)
2. Detail Permintaan  ← active
3. Review & Submit    ← inactive (step 3 aktif hanya saat submit)
```
Step aktif: `background: var(--mint); color: var(--green); border-radius: 13px`

**Section 1 — Informasi Umum (form-grid, 3 kolom):**

| Field | Tipe | Value Default | Editable? |
|---|---|---|---|
| PT | Text input | "Barumun Palma Nauli" | Tidak |
| Unit Kebun | Text input | Dari profil user | Tidak |
| Periode | Text input | Bulan berjalan | Ya |
| Tanggal Pengajuan | Date input | Hari ini | Ya |
| Dibuat Oleh | Text input | Nama user login | Tidak |

> **Catatan (CL-08):** Field "Remisi" dihapus dari form ini.

**Section 2 — Tabel Editable (field array):**

Toolbar tabel:
```
[H3: Detail Permintaan Dana]  [+ Tambah Baris] [Validasi]
```

Kolom tabel:

| Kolom | Tipe | Behavior |
|---|---|---|
| Kategori | Dropdown | Isi dari master, cascade ke Sub-Kategori |
| Sub-Kategori | Dropdown | Reaktif ke Kategori, cascade ke Item |
| Item Biaya | Dropdown | Reaktif ke Sub-Kategori, autofill Akun/Satuan/Tarif |
| No Akun | Input text | Autofill dari master, editable |
| Keterangan | Input text | Wajib diisi, `placeholder="Keterangan biaya"` |
| Qty | Input number | Opsional |
| Satuan | Input text | Autofill dari master, editable |
| Tarif | Input number | Autofill dari master, editable |
| **Jumlah** | Input number / Tombol | `mode_input=manual`: input angka; `mode_input=auto_external`: tombol "Ambil Biaya" (bg: `#dbeafe`, color: `#1d4ed8`, border: `#bfdbfe`) |
| Catatan | Input text | Opsional |
| Aksi | Buttons | [Duplikasi] [Hapus] (keduanya ghost style) |

**Footer tabel (Grand Total):**
```
[colspan 8: "Grand Total"]  [Grand Total Jumlah]  [colspan 2: —]
```
Footer row: `background: #f2faf5; font-weight: 950; color: #0f6b45`

**Validasi baris (highlight merah):**
- Kondisi: `Keterangan kosong` ATAU `Jumlah = 0` ATAU `auto_external belum diklik "Ambil Biaya"`
- Style: `outline: 2px solid #fecaca !important; background: #fff7f7 !important`

**Modal konfirmasi Submit:**
```
┌────────────────────────────────────────┐
│ Submit Pengajuan PDO                   │
│ Pengajuan akan dikirim ke Asisten      │
│ Kebun untuk review.                    │
│                                        │
│ [Textarea: Catatan approval / revisi]  │
│                                        │
│             [Batal] [Submit]           │
└────────────────────────────────────────┘
```

---

### 4.4 Detail PDO (`detail`)

**Akses:** Semua role (unit restriction berlaku).
**Navigasi masuk:** Dari tombol "Detail" di Daftar PDO.

**Hero Section:**
- H2: "Detail PDO: [Nomor PDO]" (contoh: "Detail PDO: PDO-2026-06-KP-001")
- Tombol: [Kembali → `list`] [Input Realisasi → `inputRealisasi`] [Export PDF]

**KPI Cards (4 kartu, bukan 5 — Remisi I/II dihapus):**

| Label | Nilai |
|---|---|
| Total Pengajuan | Rp XXX |
| Total Transfer | Rp XXX |
| Total Realisasi | Rp XXX |
| Saldo | Rp XXX |
| Status | Badge sesuai status PDO |

**Tabel Hierarkis:**

Toolbar:
```
[H3: Rincian Kategori → Sub-Kategori → Item Biaya]  [Expand / Collapse All]
```

Row levels:
1. **Kategori row** (`.group-row`): `background: #f2faf5; font-weight: 950; color: #0f6b45` — clickable, toggle expand sub-kategori
2. **Sub-Kategori row** (`.subgroup-row`): `background: #fbf7e8; font-weight: 900; color: #84540b` — clickable, toggle expand item
3. **Item row**: background putih, clickable → buka Drawer detail item

Kolom tabel detail:

| Kolom | Keterangan |
|---|---|
| Kategori/Sub/Item | Nama dengan indent |
| No Akun | Snapshot dari PDO detail |
| Keterangan | Deskripsi biaya |
| Qty | — |
| Satuan | — |
| Pengajuan | Jumlah yang diajukan |
| Transfer | Total transfer kumulatif |
| Realisasi | Total realisasi kumulatif |
| Saldo | Transfer - Realisasi |
| Status | Badge per item |

**Status badge per item:**

| Kondisi | Badge | Style |
|---|---|---|
| Realisasi = Transfer | "Sesuai" | `approved` |
| Realisasi < Transfer (selisih > threshold) | "Ada Selisih" | `warnb` |
| Realisasi > Transfer | "Over Budget" | `over` |
| Tidak ada bukti | "Belum Ada Bukti" | `warnb` |

**Item dari PDO Tambahan:**
- Tampil di bagian **paling bawah** tabel, setelah semua item PDO Bulanan asal
- Didahului **baris header pemisah** dengan teks: `▶ PDO Tambahan: PDOT-2026-06-KP-001 — disetujui 15 Jun 2026`
- Header pemisah style: `background: #dbeafe; color: #1d4ed8; font-weight: 850`

**Drawer (slide dari kanan):**
- Lebar: `460px`, max `95vw`
- `position: fixed; right: -480px; top: 0; height: 100vh`
- Transition: `.25s` ke `right: 0` saat `.open`
- Overlay: `position: fixed; inset: 0; background: rgba(15,23,42,.25); z-index: 9`
- Konten: nama item, hierarki, no akun, keterangan, total pengajuan, realisasi, saldo, status bukti
- Tombol tutup: "Tutup" (ghost)

---

### 4.4A Transfer Dana di Detail PDO (UI-01)

**Akses:** Hanya `MANAJER_KEUANGAN` dan `STAFF_KEUANGAN`. Section ini **tidak tampil** untuk role lain.

**Lokasi:** Section terpisah di bawah tabel hierarkis pada halaman Detail PDO, hanya muncul ketika `status = final` atau `status = closed`.

**Judul section:**
```
[H3: Pencatatan Transfer Dana]  [Badge: Final / Closed]
```

**Tabel Transfer per Item:**

Kolom:

| Kolom | Tipe | Keterangan |
|---|---|---|
| Item Biaya | Text | Nama item + kategori/sub (read-only) |
| Jumlah Pengajuan | Number | Read-only, format Rupiah |
| Total Transfer | Number | Kumulatif semua entri transfer, read-only, auto-update |
| Selisih | Number | Total Transfer - Pengajuan (merah jika negatif, badge "Selisih Transfer" jika ≠ 0) |
| Entri | Number | Jumlah entri transfer yang sudah dicatat |
| Aksi | Buttons | [+ Catat Transfer] [Riwayat] |

**Default value saat PDO baru Final:**
- Saat PDO pertama kali berstatus Final, kolom `Total Transfer` per item **diisi otomatis dengan nilai Jumlah Pengajuan** masing-masing item
- Ini diwujudkan sebagai satu entri transfer awal yang dibuat sistem: `tanggal = tanggal approval Direktur`, `nominal = jumlah pengajuan`, `reference_number = "Auto — sesuai pengajuan"`, `notes = "Dibuat otomatis saat PDO Final"`
- **Visual:** baris yang memiliki entri otomatis ini ditandai dengan badge abu kecil "Auto" di kolom Entri
- Manajer Keuangan/Staff Keuangan dapat mengedit entri otomatis ini atau menghapusnya jika nominal aktual berbeda

**Form "+ Catat Transfer" (inline di bawah baris atau modal kecil):**
```
┌─────────────────────────────────────────────────────┐
│ Catat Transfer: [Nama Item]                          │
│                                                      │
│ Tanggal Transfer:    [Date input]                    │
│ Nominal Transfer:    [Number input, Rupiah]          │
│ No Referensi:        [Text input, wajib]             │
│ Catatan:             [Text input, opsional]          │
│                                                      │
│                      [Batal]  [Simpan Transfer]      │
└─────────────────────────────────────────────────────┘
```
- Setelah simpan: Total Transfer item di tabel diperbarui real-time, badge "Selisih Transfer" muncul/hilang sesuai kondisi

**Tombol "Riwayat"** → membuka Drawer riwayat transfer item:
```
[H3: Riwayat Transfer — Nama Item]
Tabel: Tanggal | Nominal | No Referensi | Dicatat Oleh | Catatan | Aksi (Koreksi)
```
- Entri tidak bisa dihapus — hanya bisa dikoreksi (tombol "Koreksi" per baris)
- Entri auto sistem ditandai badge "Auto" di kolom Dicatat Oleh

**Behavior badge "Selisih Transfer":**

| Kondisi | Tampilan |
|---|---|
| Total Transfer = Pengajuan | Tidak ada badge |
| Total Transfer > Pengajuan | Badge `warnb` "Selisih +Rp X" |
| Total Transfer < Pengajuan | Badge `warnb` "Selisih -Rp X" |

**PDO Closed:** Tombol "+ Catat Transfer" dan "Koreksi" disembunyikan. Tabel menjadi read-only penuh.

---

### 4.5 Realisasi Dana — List (`realisasi`)

**Akses:** Semua role.

**Hero Section:**
- H2: "Realisasi Dana"
- Deskripsi: "Pilih PDO yang sudah ditransfer, lalu input realisasi sampai level item biaya."
- Tombol: "Input Realisasi Item" → `inputRealisasi`

**Filter Bar:**
```
[Input: Cari nomor PDO...]  [Dropdown: Status]  [Tombol: Terapkan]
```

**Tabel PDO List:**

| Kolom | Keterangan |
|---|---|
| Nomor PDO | Bold |
| Unit | — |
| Periode | — |
| Total Transfer | Rupiah |
| Total Realisasi | Rupiah |
| Saldo | Rupiah |
| Progress | Progress bar (height 12px, gradient green) |
| Jumlah Item | Number |
| Belum Realisasi | Number |
| Status Realisasi | Badge |
| Aksi | Tombol "Input Realisasi" (ghost) → `inputRealisasi` |

---

### 4.6 Input Realisasi (`inputRealisasi`)

**Akses:** KERANI (semua sumber dana) dan STAFF_PURCHASING (hanya Rekening Utama).

**Hero Section:**
- H2: "Input Realisasi Item Biaya"
- Tombol: [Kembali → `realisasi`] [Submit Semua Item]

**Panel Atas (grid 2 kolom):**

Kiri — Info PDO (read-only):
```
Nomor PDO:     PDO-2026-06-KP-001
PT / Unit:     BPN · KP
Periode:       Juni 2026
Total Transfer: Rp 267.936.586
Total Realisasi: [live update]
Progress:      [live update]%
```

Kanan — Ringkasan (sticky, `position: sticky; top: 18px`):
```
[H3: Ringkasan Realisasi]  [Badge: Auto update]

Total Input Baru:         Rp 0
Realisasi Setelah Input:  Rp 0
Total Saldo:              Rp 0
Item berubah:             0
Butuh bukti:              0
Butuh penjelasan:         0

[Simpan Draft]  [Submit Realisasi]
```

**Indikator Kumulatif PDO (sticky bar di atas tabel):**
- Total Transfer PDO, Total Realisasi PDO, Sisa Saldo PDO
- Progress bar keseluruhan PDO (warna berubah merah saat mendekati 100%)
- Update real-time setiap input angka baru

**Tab Filter:**
```
[Semua] [Belum Realisasi] [Over Budget] [Belum Ada Bukti] [Butuh Penjelasan]
```
Tab aktif: `background: var(--green); color: white; border-radius: 999px`
Tab default: `border: 1px solid var(--line); background: white; border-radius: 999px`

**Tabel Input Realisasi (editable):**

Kolom read-only:
- Kategori/Sub/Item (dengan sub-header group row dan subgroup row)
- No Akun
- Keterangan
- Transfer (per item)
- Realisasi Saat Ini

Kolom input:
- Input Baru (`type="number"`, `oninput="calcReal()"`)
- Total Setelah Input (kalkulasi otomatis)
- Saldo Setelah (kalkulasi otomatis)
- % Realisasi (kalkulasi otomatis)
- Tgl Transaksi (`type="date"`)
- Metode (dropdown: Tunai / Transfer / Kas Kecil)
- Sumber Dana (dropdown):
  - KERANI: Kas Kebun / Rekening Kebun / Rekening Utama Perusahaan
  - STAFF_PURCHASING: **hanya** Rekening Utama Perusahaan (opsi lain tidak ditampilkan)
- No Bukti (text input)
- Upload Bukti (tombol ghost → setelah upload berubah menjadi "Preview")
- Penjelasan Selisih (text input, wajib jika selisih > threshold)
- Status badge (auto-update)
- Aksi: [Simpan] [↺ History → `itemHistory`]

**Tombol History:**
Style khusus: `background: #dbeafe; color: #1d4ed8; border: 1px solid #bfdbfe`

**Kalkulasi real-time:**
- Setiap perubahan pada "Input Baru" → update Total Setelah Input, Saldo Setelah, % Realisasi, Ringkasan, dan Indikator Kumulatif PDO
- Jika Total Realisasi PDO akan melebihi Total Transfer PDO → tombol "Simpan" diblokir + pesan error merah

---

### 4.7 History Realisasi Item (`itemHistory`)

**Akses:** Semua role.
**Navigasi masuk:** Dari tombol "↺ History" di tabel Input Realisasi.

**Hero Section:**
- H2: "History Realisasi Item Biaya"
- Tombol: [Kembali → `inputRealisasi`] [Export (ghost)]

**Summary Cards (4 kartu, grid):**
```
[Kategori: A · Gaji Staff]  [Transfer: Rp X]  [Total Realisasi: Rp X]  [Saldo: Rp X]
```
Style per metric: `background: #f7faf7; border: 1px solid var(--line); border-radius: 14px; padding: 13px`

**Tabel Riwayat:**

| Kolom | Keterangan |
|---|---|
| Tanggal | Format "08 Mar 2026" |
| No Bukti | String |
| Metode | Tunai / Transfer / Kas Kecil |
| Nominal | Format Rupiah |
| Saldo Setelah | Format Rupiah |
| Diinput Oleh | Nama user |
| Catatan | Text |
| Bukti | Tombol "Preview" (ghost) |

> **Catatan (CL-09):** Kolom "Status Verifikasi" **tidak ada** di tabel ini — verifikasi dilakukan Finance di luar sistem.

---

### 4.8 Approval Timeline (`approval`)

**Akses:** Semua role kecuali STAFF_KEUANGAN dan STAFF_PURCHASING.
**Navigasi masuk:** Dari klik badge status di Daftar PDO.

**Hero Section:**
- H2: "Approval Timeline"
- Deskripsi: "Halaman ini tidak muncul di sidebar; aksesnya dari klik status PDO pada Daftar PDO."
- Tombol: [Kembali → `list`] [Action Approval] (hanya tampil jika user adalah approver di tahap aktif saat ini)

**Timeline Vertikal:**
```
grid-template-columns: 36px 1fr; gap: 14px
```

Setiap step:
```
[Dot Nomor]  [Card: Nama step, status, tanggal, durasi]
```

Dot: `34×34px; border-radius: 50%; background: #ddf8e9; color: var(--green); font-weight: 950`

Card step:
- Step selesai: tampilkan nama approver + tanggal + durasi
- Step aktif (menunggu): tampilkan tombol "Approve / Reject" → buka Modal
- Step pending: tampilkan "Belum dimulai"
- Step reject: tampilkan alasan reject dengan background merah muda

**Tahap-tahap:**
1. Draft — dibuat Kerani
2. Submitted — Kerani submit
3. Reviewed Asisten — Asisten approve
4. In Review Manajer (paralel: Manajer Kebun + Manajer Keuangan)
5. In Review Direktur
6. Final — Direktur approve
7. Closed — sistem auto / Manajer Keuangan manual

**Modal Approval:**
```
┌────────────────────────────────────────────┐
│ [H3: Approval PDO / Reject PDO]            │
│ Isi catatan jika reject atau request revisi │
│                                            │
│ [Textarea: Catatan approval / revisi]      │
│                                            │
│        [Batal] [Reject (danger)] [Approve] │
└────────────────────────────────────────────┘
```
- Width: `520px; max-width: 92vw`
- Border-radius: `22px`
- Tombol Reject: style `danger` (background: `--red`)
- Field catatan: wajib diisi untuk Reject

---

### 4.9 Rekapitulasi (`rekap`)

**Akses:** Semua role kecuali STAFF_PURCHASING.

**Hero Section:**
- H2: "Rekapitulasi PDO"
- Deskripsi: "Rekap digital mengikuti struktur kategori dan sub-kategori, dengan subtotal otomatis."
- Tombol: "Export" → trigger export Excel

**Filter Bar:**
```
[Dropdown: Periode]  [Dropdown: Kategori]  [Dropdown: Sub-Kategori]
```

**Tabel Rekapitulasi:**

| Kolom | Keterangan |
|---|---|
| No | Nomor urut |
| Kategori | Nama kategori |
| Sub-Kategori | Nama sub-kategori |
| Kode | Kode kategori |
| Jumlah Pengajuan | Total amount |
| Total Transfer | Total transfer kumulatif |
| Total Realisasi | Total realisasi |
| Saldo | Transfer - Realisasi |

> **Catatan (CL-08):** Kolom Remisi I dan Remisi II dihapus. Kolom Total Transfer dan Total Realisasi ditambahkan.

Row subtotal: `background: #f2faf5; font-weight: 950; color: #0f6b45`
Row grand total: sama dengan subtotal + `colspan`

---

### 4.10 Master Data (`master`)

**Akses:** Semua role (read-only kecuali ADMIN yang bisa add/edit).

**Hero Section:**
- H2: "Master Data"
- Tombol (hanya ADMIN): [+ Kategori → `addKategori`] [+ Sub-Kategori → `addSubKategori`] [+ Item Biaya → `addItemBiaya`]

**Tab:**
```
[Hierarki Biaya (aktif default)] [Item Biaya] [Akun] [User & Role]
```

**Tab Hierarki Biaya:**

Layout split: `grid-template-columns: 1.1fr 0.9fr`

Kiri — Tabel Hierarki:
- Kolom: Kategori/Sub/Item | Induk | Detail | Aksi

Row Kategori: `group-row` style (hijau tua)
- Tombol toggle: `▾ Sub` / `▸ Sub` — style `tree-toggle` (border: `#b7e9d1`, background: `#e8f6ef`, color: `#0f6b45`, border-radius: 999px, font-weight: 950)
- Aksi: [+ Sub] → `addSubKategori`

Row Sub-Kategori: `subgroup-row` style (kuning muda), tersembunyi default
- Tombol toggle: `▾ Item` / `▸ Item` — style sama dengan tree-toggle
- Aksi: [+ Item] → `addItemBiaya`

Row Item: indent kiri, tersembunyi default (class `master-item hidden`)
- Aksi: [+ Item Serupa]

Kanan — Panduan Alur (sticky):
```
Timeline 3 langkah:
[1] Tambah Kategori → [Buka Halaman]
[2] Tambah Sub-Kategori → [Buka Halaman]
[3] Tambah Item Biaya → [Buka Halaman]
```

**Tab Item Biaya:**

Tabel dengan kolom:

| Kolom | Keterangan |
|---|---|
| Kategori | — |
| Sub-Kategori | — |
| Item Biaya | — |
| No Akun Default | — |
| Satuan | — |
| Tarif | Format Rupiah |
| Rutin | Badge: `approved` (Ya) / `draft` (Tidak) |
| Mode Input | Badge: abu (manual) / `purple` (auto_external) |
| Status | Badge: `approved` (Aktif) / `draft` (Nonaktif) |
| Aksi | [+ Item Serupa] (ghost) |

---

### 4.11 Form Tambah Kategori (`addKategori`)

**Akses:** ADMIN saja.
**Navigasi:** Dari tombol "+ Kategori" di Master Data.

**Layout:** `grid-template-columns: 1fr 1fr`

**Form Kiri:**

| Field | Tipe | Keterangan |
|---|---|---|
| Kode Kategori | Text input | Wajib, unik (A, B, C...) |
| Nama Kategori | Text input | Wajib |
| Urutan Tampil | Number input | Default: 0 |
| Masuk Rekap PDO | Select | Ya / Tidak |
| Status | Select | Aktif / Nonaktif |
| Catatan | Textarea | Opsional |

Tombol: [Simpan Kategori] [Reset Form]

**Preview Kanan:** Panel menampilkan dampak — kode, nama, urutan, status rekap sebelum disimpan.

---

### 4.12 Form Tambah Sub-Kategori (`addSubKategori`)

**Akses:** ADMIN saja.

| Field | Tipe | Keterangan |
|---|---|---|
| Kategori Induk | Dropdown | Dari master, cascade |
| Kode Sub-Kategori | Text input | Wajib, unik per kategori |
| Nama Sub-Kategori | Text input | Wajib |
| Urutan Tampil | Number | Default: 0 |
| Status | Select | Aktif / Nonaktif |
| Catatan | Textarea | Opsional |

---

### 4.13 Form Tambah Item Biaya (`addItemBiaya`)

**Akses:** ADMIN saja.

**Layout:** `grid-template-columns: 1fr 1fr`

**Form Kiri (tabel 1 baris):**

| Field | Tipe | Keterangan |
|---|---|---|
| Kategori Induk | Dropdown | Cascade ke Sub-Kategori |
| Sub-Kategori Induk | Dropdown | Reaktif ke Kategori |
| Kode Item | Text input | Wajib, unik per sub-kategori |
| Nama Item | Text input | Wajib |
| No Akun Default | Text input | Chart of accounts |
| Satuan Default | Text input | "Orang", "Liter", dll. |
| Tarif Default | Number input | Rupiah, >= 0 |
| Mode Input | Dropdown | `manual` / `auto_external` |
| Rutin | Dropdown | **Ya / Tidak** (hanya dua opsi ini) |
| Wajib Bukti | Dropdown | Ya / Tidak |
| Status | Dropdown | Aktif / Nonaktif |
| Catatan | Textarea | Opsional |

Tombol: [Simpan Item Biaya] [Reset Form]

**Preview Kanan:**
```
Tabel: Field | Terisi dari master
No Akun  → [live update]
Satuan   → [live update]
Tarif    → [live update]
Rutin    → Badge Ya/Tidak [live update]
Validasi → "Threshold bukti & penjelasan"
```

Preview update real-time saat user mengisi field di kiri.

---

### 4.14 Laporan (`laporan`)

**Akses:** Semua role kecuali STAFF_PURCHASING.

**Hero Section:**
- H2: "Laporan"
- Deskripsi: "Preview dan export laporan pengajuan, transfer, realisasi, saldo, over budget, dan bukti belum lengkap."
- Tombol: [Preview Laporan] [Export PDF/Excel]

**Form Filter:**

| Field | Opsi |
|---|---|
| Jenis Laporan | Laporan Realisasi Dana / Over Budget / Bukti Belum Lengkap / Rekapitulasi |
| Periode | Dropdown bulan/tahun |
| Kategori | Semua / per kategori |

**Area Preview:**
```html
<div class="empty-state">
  Area preview laporan. Klik Preview Laporan untuk menampilkan hasil filter, lalu export PDF/Excel.
</div>
```

---

### 4.15 Pengaturan (`settings`)

**Akses:** ADMIN saja.

**Hero Section:**
- H2: "Pengaturan"

**Section 1 — Threshold Global:**

| Field | Default | Tipe | Keterangan |
|---|---|---|---|
| Threshold wajib bukti | 1.000.000 | Number input | Rupiah, berlaku global semua item |
| Threshold penjelasan selisih | 500.000 | Number input | Rupiah, berlaku global semua item |

Tombol: [Simpan Threshold]

**Section 2 — WhatsApp Gateway:**

| Field | Default | Tipe | Keterangan |
|---|---|---|---|
| URL Endpoint Gateway | — | Text input | URL lengkap endpoint kirim pesan |
| API Key / Token | — | Password input (`type="password"`) | Tersembunyi, toggle show/hide via ikon mata |

Tombol: [Simpan Konfigurasi Gateway] [Test Koneksi]

**Behavior tombol "Test Koneksi":**
- Klik → sistem mengirim pesan test ke nomor WhatsApp Admin yang sedang login
- Success: toast hijau "Test koneksi berhasil. Pesan terkirim ke [nomor]"
- Gagal: toast merah "Koneksi gagal: [pesan error dari gateway]"
- Selama proses: tombol disabled + loading spinner

**Section 3 — Jadwal Reminder:**

| Field | Default | Tipe | Keterangan |
|---|---|---|---|
| Tanggal reminder bulanan | 1 | Number input (1–28) | Tanggal pengiriman setiap bulan |
| Jam pengiriman | 08 | Number input (0–23) | Format 24 jam WIB |

Tombol: [Simpan Jadwal]

**Section 4 — Template Pesan WhatsApp:**
- Daftar template per jenis event (pdo_submitted, pdo_rejected, pdo_final, sla_reminder, monthly_reminder)
- Setiap template: `event_type` (read-only label) + textarea `template_body`
- Hint di bawah textarea: daftar variabel yang tersedia `{{nama_user}}`, `{{nomor_pdo}}`, `{{periode}}`, `{{unit_kebun}}`, `{{alasan_reject}}`, `{{deadline}}`
- Tombol: [Simpan Template] per template

---

## 5. Komponen UI Reusable

### 5.1 Button Variants

| Variant | Style | Kapan Digunakan |
|---|---|---|
| Primary | `bg: --green; color: white; border-radius: 12px; box-shadow: 0 8px 18px rgba(15,107,69,.18)` | Aksi utama (Submit, Approve, Simpan) |
| Secondary | `bg: white; color: --green; border: 1px solid --line; box-shadow: none` | Aksi sekunder (Kembali, Export, Simpan Draft) |
| Warning | `bg: --yellow` | Aksi peringatan |
| Danger | `bg: --red` | Reject, Hapus |
| Ghost | `bg: #eef5f0; color: #18593e; box-shadow: none` | Aksi di dalam tabel, aksi tersier |

Semua button: `border: 0; border-radius: 12px; padding: 10px 14px; font-weight: 850; cursor: pointer; white-space: nowrap`

### 5.2 Input & Select

```css
border: 1px solid var(--line);
background: white;
border-radius: 12px;
padding: 10px 12px;
color: var(--ink);
```

Input di tabel editable:
```css
width: 100%; min-width: 115px;
border: 1px solid #dbe7df;
background: #fff;
padding: 8px;
border-radius: 8px;
```
Focus: `outline: 2px solid #b7e9d1`

### 5.3 Card

```css
background: var(--panel);      /* white */
border: 1px solid var(--line);
border-radius: var(--radius);  /* 18px */
box-shadow: var(--shadow);     /* 0 18px 45px rgba(17,40,29,.10) */
padding: 18px;
```

### 5.4 Table

```css
/* Wrapper */
overflow: auto;
border: 1px solid var(--line);
border-radius: 16px;
background: white;

/* Table */
border-collapse: separate;
border-spacing: 0;
width: 100%;

/* Header */
th {
  position: sticky; top: 0;
  background: #f7faf7;
  color: #526257;
  font-size: 11px; text-transform: uppercase; letter-spacing: .04em;
  z-index: 2;
}

/* Hover row */
tr:hover td { background: #fbfdfb; }

/* Sticky columns */
.sticky-col  { position: sticky; left: 0; background: #fff; z-index: 1; }
.sticky-col2 { position: sticky; left: 180px; background: #fff; z-index: 1; }
```

Row types:
- Group row (Kategori): `.group-row td { background: #f2faf5 !important; font-weight: 950; color: #0f6b45 }`
- Subgroup row (Sub-Kategori): `.subgroup-row td { background: #fbf7e8 !important; font-weight: 900; color: #84540b }`

### 5.5 Toast Notification

```css
position: fixed; bottom: 22px; right: 22px;
background: #123c2d; color: white;
padding: 13px 16px; border-radius: 14px;
box-shadow: var(--shadow);
opacity: 0; transform: translateY(14px);
transition: .25s; z-index: 20;
```
Active (`.show`): `opacity: 1; transform: translateY(0)`
Auto-dismiss: **2.4 detik**

### 5.6 Modal

```css
/* Overlay */
position: fixed; inset: 0; z-index: 30;
display: grid; place-items: center;
background: rgba(15,23,42,.35);

/* Card */
width: 520px; max-width: 92vw;
background: white; border-radius: 22px;
padding: 22px; box-shadow: var(--shadow);
```

### 5.7 Drawer

```css
position: fixed; right: -480px; top: 0;
width: 460px; max-width: 95vw; height: 100vh;
background: white;
box-shadow: -20px 0 50px rgba(0,0,0,.16);
z-index: 10; transition: .25s;
padding: 24px; overflow: auto;
```
Active (`.open`): `right: 0`
Overlay: `position: fixed; inset: 0; background: rgba(15,23,42,.25); z-index: 9`

### 5.8 Progress Bar

```css
height: 12px; border-radius: 999px;
background: #e8eee9; overflow: hidden;

/* Fill */
span {
  display: block; height: 100%;
  background: linear-gradient(90deg, var(--green), #26b87c);
  border-radius: 999px;
}
```

### 5.9 KPI Card

```css
/* Base card */
.card.kpi { /* inherits card style */ }

.kpi .label {
  font-size: 12px; color: var(--muted);
  font-weight: 800; text-transform: uppercase;
  letter-spacing: .04em;
}
.kpi .value { font-size: 24px; font-weight: 950; margin: 9px 0; }
.kpi .hint  { font-size: 12px; color: var(--muted); }

/* Clickable */
.kpi.clickable { cursor: pointer; }
.kpi.clickable:hover { transform: translateY(-1px); }
```

### 5.10 Tree Toggle Button

```css
display: inline-flex; align-items: center; gap: 6px;
border: 1px solid #b7e9d1; background: #e8f6ef; color: #0f6b45;
border-radius: 999px; padding: 7px 10px;
font-weight: 950; cursor: pointer;
min-width: 104px; justify-content: center;
```
Hover: `background: #d9f5e7`

### 5.11 Empty State

```css
padding: 24px;
border: 1px dashed var(--line);
border-radius: 16px;
background: #fbfdfb;
color: var(--muted);
```

### 5.12 History Button (khusus)

```css
display: inline-flex !important;
align-items: center; gap: 6px;
background: #dbeafe !important;
color: #1d4ed8 !important;
border: 1px solid #bfdbfe !important;
box-shadow: none !important;
```

---

## 6. Behavior & Interaksi Global

### 6.1 Global Search

- Lokasi: Topbar, selalu terlihat di semua halaman
- Placeholder: "Cari nomor PDO, kategori, sub-kategori, item, akun, atau keterangan biaya..."
- Behavior: filter real-time pada konten halaman yang aktif (nomor PDO, nama item, keterangan)
- Implementasi: `oninput` → `filterTable(activeTableId, value)` yang mencari di seluruh `textContent` baris

### 6.2 Cascading Dropdown (Kategori → Sub-Kategori → Item)

```
onCatChange(catEl)
  └── Update subEl.innerHTML = subOptions(cat)
  └── Update itemEl.innerHTML = itemOptions(cat, firstSub)
  └── onItemChange(itemEl) → autofill Akun, Satuan, Tarif

onSubChange(subEl)
  └── Update itemEl.innerHTML = itemOptions(cat, sub)
  └── onItemChange(itemEl) → autofill

onItemChange(itemEl)
  └── Fill akunEl.value = defaults[item][0]
  └── Fill satEl.value  = defaults[item][1]
  └── Fill tarifEl.value = defaults[item][2]
  └── recalc()
```

### 6.3 Kalkulasi Grand Total Real-time

```
recalc() dipanggil setiap:
  - Input pada kolom Jumlah (amount)
  - Perubahan item melalui dropdown

Kalkulasi:
  total = Σ(jumlah per baris)
  Tampilkan di footer: "Grand Total Rp X"
  Flag baris invalid: keterangan kosong OR jumlah <= 0
```

### 6.4 Upload Bukti

- Tombol default: "Upload" (ghost)
- Setelah upload berhasil: tombol berubah menjadi "Preview" (ghost)
- Format diterima: PDF, JPG, PNG (max 5 MB)
- Toast: "Bukti transaksi berhasil diunggah"

### 6.5 Toast Notification

Dipanggil setiap aksi berhasil atau gagal:
```javascript
function toast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 2400);
}
```

### 6.6 Navigasi antar Halaman

```javascript
function go(page) {
  // Sembunyikan semua halaman
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  // Tampilkan halaman target
  document.getElementById(page).classList.add('active');
  // Update nav aktif
  document.querySelectorAll('.nav button').forEach(b =>
    b.classList.toggle('active', b.dataset.page === page)
  );
  // Scroll ke atas
  window.scrollTo(0, 0);
}
```

---

## 7. Responsive Behavior

**Breakpoint:** `@media(max-width: 1050px)`

| Elemen | Desktop | Mobile (<1050px) |
|---|---|---|
| Layout app | `grid: 282px 1fr` | `grid: 1fr` (sidebar di atas) |
| Sidebar | Fixed, `height: 100vh` | `position: relative; height: auto` |
| Nav | Vertikal | `grid-template-columns: repeat(2, 1fr)` |
| KPI cards | 5 kolom | 1 kolom |
| Charts | `1.15fr 0.85fr` | 1 kolom |
| Master split | `1.1fr 0.9fr` | 1 kolom |
| Split panels | `1fr 1fr` | 1 kolom |
| Wizard | `260px 1fr` | 1 kolom |
| Form grid | `repeat(3, 1fr)` | 1 kolom |
| Search | `430px; max-width: 50vw` | `max-width: none; width: 100%` |
| Topbar | `flex-direction: row` | `flex-direction: column; align-items: flex-start` |
| Hero | Row | Column |
| Right sticky panel | `position: sticky` | `position: static` |

---

## 8. Implementasi React 19

### 8.1 Struktur Komponen

```
apps/web/src/
├── components/
│   ├── layout/
│   │   ├── AppLayout.tsx        ← Sidebar + Main wrapper
│   │   ├── Sidebar.tsx          ← Nav 8 item
│   │   └── Topbar.tsx           ← Search + Profile
│   ├── ui/                      ← Shadcn/ui components
│   │   ├── Button.tsx
│   │   ├── Badge.tsx
│   │   ├── Card.tsx
│   │   ├── Modal.tsx
│   │   ├── Drawer.tsx
│   │   ├── Toast.tsx
│   │   └── ProgressBar.tsx
│   ├── pdo/
│   │   ├── PdoEditableTable.tsx ← Form buat PDO (useFieldArray)
│   │   ├── PdoTableRow.tsx      ← Satu baris editable
│   │   ├── PdoHierarchicalTable.tsx ← Detail PDO (expand/collapse)
│   │   ├── PdoApprovalTimeline.tsx
│   │   └── PdoStatusBadge.tsx
│   ├── realization/
│   │   ├── RealizationTable.tsx ← Input realisasi
│   │   ├── RealizationSummary.tsx ← Panel kanan sticky
│   │   └── CumulativeIndicator.tsx ← Bar PDO level
│   └── dashboard/
│       ├── KpiCards.tsx
│       ├── CategoryBarChart.tsx ← Recharts
│       └── ProportionDonut.tsx  ← Recharts
│
├── pages/
│   ├── DashboardPage.tsx
│   ├── PdoListPage.tsx
│   ├── PdoFormPage.tsx
│   ├── PdoDetailPage.tsx
│   ├── RealizationListPage.tsx
│   ├── RealizationInputPage.tsx
│   ├── ItemHistoryPage.tsx
│   ├── ApprovalTimelinePage.tsx
│   ├── RecapPage.tsx
│   ├── MasterDataPage.tsx
│   ├── ReportPage.tsx
│   └── SettingsPage.tsx
│
├── hooks/
│   ├── usePdo.ts               ← TanStack Query untuk PDO
│   ├── useRealization.ts
│   ├── useMasterData.ts
│   └── useGrandTotal.ts        ← useMemo kalkulasi real-time
│
└── lib/
    ├── api.ts                  ← Axios instance + interceptors
    ├── formatRupiah.ts         ← n => 'Rp ' + N.toLocaleString('id-ID')
    └── designTokens.ts         ← CSS custom properties sebagai JS constants
```

### 8.2 Tailwind Config (Design Tokens)

```javascript
// tailwind.config.js
module.exports = {
  theme: {
    extend: {
      colors: {
        bg:     '#f5f7f3',
        panel:  '#ffffff',
        ink:    '#17231d',
        muted:  '#657267',
        line:   '#dde7df',
        green:  '#0f6b45',
        green2: '#16a36d',
        mint:   '#e8f6ef',
        blue:   '#2563eb',
        yellow: '#d99106',
        red:    '#dc2626',
        purple: '#7c3aed',
        slate:  '#334155',
        sidebar:'#0c3d2c',
      },
      borderRadius: {
        card:  '18px',
        btn:   '12px',
        badge: '999px',
        modal: '22px',
      },
      boxShadow: {
        card: '0 18px 45px rgba(17,40,29,.10)',
        btn:  '0 8px 18px rgba(15,107,69,.18)',
      },
      fontFamily: {
        sans: ['Inter', 'ui-sans-serif', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'Arial', 'sans-serif'],
      },
    },
  },
}
```

### 8.3 Format Rupiah

```typescript
// lib/formatRupiah.ts
export const fmt = (n: number | null | undefined): string =>
  'Rp ' + Number(n || 0).toLocaleString('id-ID');

// Contoh: fmt(267936586) → "Rp 267.936.586"
```

### 8.4 React 19 — Catatan Implementasi

**React Compiler:** Aktifkan di `vite.config.ts` untuk optimasi re-render otomatis. Tidak perlu `useMemo` manual untuk kalkulasi grand total — React Compiler menanganinya.

**Actions API (React 19):** Gunakan untuk form submit PDO dan realisasi:
```typescript
// PdoFormPage.tsx
import { useActionState } from 'react';

function PdoFormPage() {
  const [state, submitAction, isPending] = useActionState(
    async (prevState, formData) => {
      const result = await createPdo(formData);
      return result;
    },
    null
  );
  // ...
}
```

**`use()` hook:** Untuk data fetching langsung di komponen:
```typescript
import { use } from 'react';
const pdo = use(fetchPdo(id)); // Suspense-based data fetching
```
