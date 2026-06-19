# Product Requirements Document
# Sistem Dana Operasional Kebun (PDO)
# PT Barumun Palma Nauli

---

| Atribut | Detail |
|---|---|
| Versi | 1.7 |
| Status | Draft — Validasi Realisasi Kumulatif Level PDO |
| Tanggal | 18 Juni 2026 |
| Target Go-Live | **29 Juni 2026** |
| Penulis | Tim Product |
| Direview oleh | — |
| Disetujui oleh | — |

---

## Daftar Isi

1. Executive Summary
2. Problem Statement
3. Goals & Success Metrics
4. Scope
5. User Personas
6. Functional Requirements
7. Non-Functional Requirements
8. User Stories
9. Wireframe References
10. Assumptions & Constraints
11. Open Questions
12. Revision History

---

## 1. Executive Summary

Sistem **Dana Operasional Kebun (PDO)** adalah aplikasi web internal milik PT Barumun Palma Nauli yang dirancang untuk mengelola seluruh siklus hidup pengajuan, persetujuan, dan realisasi dana operasional unit-unit kebun kelapa sawit — dimulai dari penyusunan anggaran bulanan oleh kerani kebun hingga pelaporan realisasi penggunaan dana kepada manajemen.

Sistem ini menggantikan proses manual berbasis spreadsheet dan email yang saat ini digunakan, dengan menyediakan platform terpusat yang memiliki alur persetujuan terstruktur, pencatatan realisasi berbasis bukti, dan dasbor monitoring real-time.

**Nilai utama sistem:**
- Satu sumber data kebenaran (single source of truth) untuk seluruh dana operasional kebun
- Alur persetujuan digital yang terstruktur, terlacak, dan beraudit
- Pencatatan realisasi berbasis bukti transaksi yang mencegah kesalahan dan kecurangan
- Visibilitas manajemen secara real-time terhadap realisasi anggaran per kategori biaya

---

## 2. Problem Statement

### 2.1 Masalah Saat Ini

Proses pengelolaan dana operasional kebun saat ini dilakukan secara manual dengan berbagai permasalahan:

**a. Penyusunan Pengajuan yang Tidak Terstandar**
Kerani menyusun PDO menggunakan template spreadsheet Excel yang berbeda-beda antar unit kebun. Tidak ada standardisasi format, sehingga Finance harus melakukan normalisasi manual sebelum dapat memproses pengajuan.

**b. Proses Persetujuan yang Lambat dan Tidak Terlacak**
Pengiriman dokumen PDO untuk persetujuan dilakukan via email atau WhatsApp. Tidak ada sistem pelacakan status — siapa yang sudah menyetujui, siapa yang menolak, dan apa alasannya. Keterlambatan satu pihak menyebabkan seluruh proses terhenti tanpa visibilitas yang jelas.

**c. Pencatatan Realisasi yang Tidak Konsisten**
Realisasi penggunaan dana dicatat secara terpisah oleh kerani di file Excel masing-masing, dengan format yang tidak seragam. Bukti transaksi (nota, kwitansi) disimpan secara fisik dan tidak terintegrasi dengan data realisasi digital, menyulitkan audit.

**d. Tidak Ada Pemisahan Akses Berdasarkan Peran**
Semua pihak — kerani, manajer, hingga Finance — menggunakan file Excel yang sama atau saling berbagi file, tanpa kontrol akses yang jelas. Staff purchasing yang membayar dari rekening utama perusahaan tidak memiliki jalur resmi untuk mencatat realisasi.

**e. Pelaporan yang Memakan Waktu**
Rekapitulasi realisasi vs pengajuan harus dibuat manual setiap bulan, memakan waktu berjam-jam dan rentan kesalahan. Manajemen tidak dapat memantau status anggaran secara real-time.

### 2.2 Dampak Bisnis

- Keterlambatan pencairan dana operasional kebun akibat proses approval yang tidak jelas
- Potensi kelebihan atau kekurangan anggaran yang tidak terdeteksi tepat waktu
- Risiko audit yang tinggi akibat bukti transaksi yang tidak terintegrasi
- Beban kerja administrasi yang besar pada tim Finance untuk rekonsiliasi data

---

## 3. Goals & Success Metrics

### 3.1 Tujuan Bisnis

| ID | Tujuan | Indikator Keberhasilan |
|----|--------|----------------------|
| G-01 | Mempercepat siklus approval PDO | Rata-rata waktu approval turun dari >5 hari menjadi ≤2 hari kerja |
| G-02 | 100% pengajuan PDO terdokumentasi digital | 0 PDO yang diproses di luar sistem setelah go-live 3 bulan |
| G-03 | Bukti transaksi terintegrasi dengan data realisasi | 100% entri realisasi di atas threshold memiliki bukti yang terunggah |
| G-04 | Visibilitas real-time bagi manajemen | Dashboard dapat diakses kapan saja, data terbaru dalam 1 menit |
| G-05 | Mengurangi waktu rekonsiliasi Finance | Waktu penyusunan laporan realisasi bulanan turun ≥60% |

### 3.2 KPI Operasional (3 Bulan Setelah Go-Live)

| Metrik | Baseline (Saat Ini) | Target |
|--------|--------------------|----|
| Waktu rata-rata approval PDO | >5 hari kerja | ≤2 hari kerja |
| % PDO diajukan via sistem | 0% | 100% |
| % entri realisasi dengan bukti (di atas threshold) | ~40% | ≥95% |
| Waktu penyusunan laporan realisasi bulanan | 4-8 jam | ≤1 jam |
| Jumlah unit yang aktif menggunakan sistem | 0 | Seluruh unit kebun |

---

## 4. Scope

### 4.1 In-Scope

- Manajemen master data biaya (Kategori, Sub-Kategori, Item Biaya)
- Manajemen user dan peran (Role-Based Access Control) — termasuk role **Admin**
- Pembuatan PDO Bulanan dengan template item biaya rutin otomatis
- Pembuatan PDO Tambahan
- Alur persetujuan bertingkat (Kerani → Asisten → Manajer Kebun + Manajer Keuangan → Direktur)
- Mekanisme reject dengan wajib alasan dan proses revisi
- Form input nilai transfer dana oleh Manajer Keuangan atau Staff Keuangan (jika berbeda dari nilai pengajuan)
- Pencatatan realisasi dana per item biaya oleh Kerani dan Staff Purchasing
- Upload bukti transaksi (PDF, JPG, PNG, maks 5 MB)
- Validasi threshold bukti dan penjelasan selisih
- History realisasi per item biaya
- Rekapitulasi digital per kategori dan sub-kategori
- Dashboard monitoring dengan grafik dan KPI (termasuk akses konsolidasi lintas unit untuk Manajer Keuangan dan Direktur)
- Export laporan ke Excel dan PDF
- Audit trail untuk seluruh perubahan status PDO dan entri realisasi
- Notifikasi in-system dan WhatsApp (via WhatsApp gateway perusahaan) untuk perubahan status yang relevan

### 4.2 Out-of-Scope (Versi 1.0)

- Integrasi langsung dengan sistem akuntansi (SAP, Oracle, dll.) — ekspor data manual via Excel
- Modul pembayaran atau transfer dana aktual (hanya pencatatan nilai transfer, bukan pemrosesan)
- Aplikasi mobile native (iOS/Android) — hanya web responsive
- Multi-bahasa (hanya Bahasa Indonesia)
- Modul anggaran tahunan (budgeting) — hanya operasional bulanan
- Single Sign-On (SSO) — autentikasi mandiri username dan password

---

## 5. User Personas

### Persona 1: Kerani / Admin Kebun

**Nama representatif:** Rika Aprillia
**Unit:** Kebun Kota Pinang

**Profil:**
Lulusan SMK atau D3 Akuntansi, bekerja di kebun selama 3-5 tahun. Terbiasa dengan Excel, namun tidak memiliki latar belakang teknis IT yang kuat. Menggunakan komputer desktop di kantor kebun dengan koneksi internet yang terkadang tidak stabil.

**Tanggung Jawab Utama:**
- Menyusun draft PDO Bulanan setiap awal bulan
- Mengisi nominal biaya pada setiap item dalam template
- Menambahkan item biaya non-rutin jika diperlukan
- Mencatat realisasi penggunaan dana dari kas/rekening kebun
- Mengunggah bukti transaksi setiap pengeluaran

**Kebutuhan Kritis:**
- Template otomatis yang sudah terisi daftar item rutin — tidak perlu input dari nol setiap bulan
- Dropdown berjenjang yang memudahkan pemilihan Kategori → Sub-Kategori → Item Biaya
- Kalkulasi total otomatis untuk setiap baris dan grand total
- Notifikasi yang jelas ketika PDO ditolak beserta alasan penolakan
- Proses input realisasi yang cepat dan mudah, langsung per item biaya

**Pain Points:**
- Harus menyalin format Excel setiap bulan — membosankan dan rawan salah
- Tidak tahu status approval PDO tanpa menghubungi pihak terkait
- Sering lupa item biaya mana yang belum ada buktinya
- Proses koreksi setelah PDO ditolak membingungkan — harus memulai dari mana?

---

### Persona 2: Asisten Kebun

**Nama representatif:** Budi Santoso
**Unit:** Kebun Kota Pinang

**Profil:**
Lulusan D3 atau S1 Pertanian/Manajemen. Bertanggung jawab atas operasional lapangan dan administrasi kebun. Mengakses sistem dari laptop atau PC kantor kebun.

**Tanggung Jawab Utama:**
- Melakukan review pertama terhadap draft PDO yang dibuat Kerani
- Memastikan kelengkapan dan kewajaran pengajuan biaya
- Memberikan persetujuan atau penolakan dengan alasan yang jelas

**Kebutuhan Kritis:**
- Dapat melihat rincian PDO secara detail (per kategori, sub-kategori, item)
- Perbandingan dengan periode sebelumnya untuk mendeteksi anomali
- Input alasan penolakan yang mudah

**Pain Points:**
- Harus memeriksa dokumen PDO yang dikirim via email, kadang tertimbun
- Tidak ada reminder otomatis untuk PDO yang menunggu persetujuannya

---

### Persona 3: Manajer Kebun

**Nama representatif:** Andi Wirawan
**Unit:** Kebun Kota Pinang

**Profil:**
Lulusan S1, pengalaman >10 tahun di industri perkebunan. Mengakses sistem dari laptop, sering berpindah lokasi antara kantor kebun dan lapangan.

**Tanggung Jawab Utama:**
- Review PDO setelah Asisten menyetujui
- Memastikan pengajuan sesuai dengan kebutuhan operasional aktual kebun
- Menyetujui atau menolak PDO secara bersamaan dengan Manajer Keuangan

**Kebutuhan Kritis:**
- Ringkasan cepat (dashboard) sebelum masuk ke detail
- Akses dari perangkat mobile (browser responsive)
- History persetujuan sebelumnya sebagai referensi

**Pain Points:**
- Sering menerima dokumen PDO via WhatsApp saat sedang di lapangan, sulit membaca detail di HP

---

### Persona 4: Manajer Keuangan

**Nama representatif:** Dewi Rahayu
**Kantor:** Pusat

**Profil:**
Lulusan S1 Akuntansi atau Keuangan. Bertanggung jawab atas kontrol anggaran untuk seluruh unit kebun perusahaan.

**Tanggung Jawab Utama:**
- Review aspek keuangan dari setiap PDO (kewajaran nominal, kepatuhan terhadap anggaran)
- Menyetujui atau menolak bersama Manajer Kebun
- Memantau realisasi vs anggaran secara keseluruhan

**Kebutuhan Kritis:**
- Dashboard konsolidasi untuk semua unit kebun
- Laporan rekapitulasi yang bisa langsung di-export untuk rapat manajemen
- Notifikasi segera untuk PDO yang membutuhkan perhatiannya

**Pain Points:**
- Harus memeriksa PDO dari banyak unit kebun — butuh navigasi yang cepat
- Rekapitulasi manual setiap bulan sangat menyita waktu

---

### Persona 5: Direktur Keuangan

**Nama representatif:** Hendra Gunawan
**Kantor:** Pusat

**Profil:**
Level eksekutif, akses sistem terutama untuk final approval dan monitoring strategis. Menggunakan laptop dan tablet.

**Tanggung Jawab Utama:**
- Final approval PDO (setelah Manajer Kebun dan Manajer Keuangan menyetujui)
- Memantau kondisi keuangan operasional kebun secara keseluruhan

**Kebutuhan Kritis:**
- Proses approval yang cepat — tidak perlu membaca seluruh detail jika sudah ada ringkasan
- Dashboard executive yang menampilkan KPI utama
- Histori keputusan yang terdokumentasi untuk keperluan audit

**Pain Points:**
- Proses approval yang berlarut-larut karena harus bolak-balik konfirmasi
- Tidak ada visibilitas terhadap progres realisasi setelah dana ditransfer

---

### Persona 6: Staff Purchasing

**Nama representatif:** Tono Susanto
**Kantor:** Pusat

**Profil:**
Bertanggung jawab atas pembelian dan pembayaran yang dilakukan langsung dari rekening utama perusahaan. Tidak terlibat dalam penyusunan atau persetujuan PDO.

**Tanggung Jawab Utama:**
- Mencatat realisasi untuk item biaya yang dibayar dari rekening utama perusahaan
- Mengunggah bukti pembayaran (transfer bank, faktur)

**Kebutuhan Kritis:**
- Akses yang dibatasi hanya pada item biaya yang relevan (sumber dana: Rekening Utama Perusahaan)
- Proses input yang sederhana: pilih item → isi nominal → upload bukti

**Pain Points:**
- Saat ini tidak ada mekanisme resmi untuk mencatat pembayaran yang dilakukan Staff Purchasing di sistem kebun
- Data realisasi tidak lengkap karena pembayaran dari pusat tidak tercatat di sistem kebun

---

## 6. Functional Requirements

### FR-01: Dashboard & Monitoring

**Deskripsi:** Halaman utama yang menampilkan ringkasan kondisi PDO aktif, tren realisasi, dan item yang membutuhkan tindakan segera.

| ID | Requirement | Prioritas |
|----|-------------|-----------|
| FR-01-001 | Sistem menampilkan 5 KPI Card: Total Pengajuan, **Total Transfer** (kumulatif semua entri transfer yang sudah dicatat), Total Realisasi, Saldo (Total Transfer - Total Realisasi), dan Jumlah Item Belum Ada Bukti | Must Have |
| FR-01-002 | KPI Card "Total Pengajuan" dapat diklik dan mengarahkan ke halaman Daftar PDO | Should Have |
| FR-01-003 | KPI Card "Item Belum Bukti" dapat diklik dan mengarahkan ke halaman Input Realisasi dengan filter "Belum Ada Bukti" aktif | Should Have |
| FR-01-004 | Sistem menampilkan grafik bar horizontal yang menunjukkan perbandingan Pengajuan vs Realisasi per Kategori Biaya | Must Have |
| FR-01-005 | Sistem menampilkan donut chart yang menunjukkan proporsi biaya per kategori beserta persentase realisasi keseluruhan | Should Have |
| FR-01-006 | Dashboard dapat difilter berdasarkan: Periode (bulan/tahun), Unit Kebun, Kategori Biaya, dan Sub-Kategori Biaya | Must Have |
| FR-01-007 | Semua angka pada dashboard menggunakan format mata uang Rupiah dengan pemisah ribuan | Must Have |
| FR-01-008 | Dashboard menampilkan data yang relevan dengan unit kebun dan peran user yang sedang login | Must Have |
| FR-01-009 | Manajer Keuangan dan Direktur dapat melihat dashboard konsolidasi seluruh unit kebun | Should Have |

---

### FR-02: Master Data

**Deskripsi:** Pengelolaan data referensi yang menjadi fondasi struktur biaya PDO dan manajemen user.

#### FR-02-A: Kategori Biaya

| ID | Requirement | Prioritas |
|----|-------------|-----------|
| FR-02-001 | Admin dapat menambah Kategori Biaya baru dengan field: Kode (unik), Nama, Urutan Tampil, Masuk Rekap PDO (Ya/Tidak), Status (Aktif/Nonaktif), dan Catatan | Must Have |
| FR-02-002 | Sistem mencegah penyimpanan Kategori dengan kode yang sudah ada | Must Have |
| FR-02-003 | Admin dapat mengubah data Kategori Biaya yang sudah ada | Must Have |
| FR-02-004 | Kategori Biaya yang sudah memiliki Sub-Kategori aktif tidak dapat dihapus — hanya dapat dinonaktifkan | Must Have |
| FR-02-005 | Kategori Biaya yang dinonaktifkan tidak muncul di dropdown form PDO baru, namun tetap tampil di PDO yang sudah ada | Must Have |

#### FR-02-B: Sub-Kategori Biaya

| ID | Requirement | Prioritas |
|----|-------------|-----------|
| FR-02-006 | Admin dapat menambah Sub-Kategori Biaya baru yang terhubung ke satu Kategori Biaya induk, dengan field: Kategori Induk, Kode, Nama, Urutan Tampil, Status, dan Catatan | Must Have |
| FR-02-007 | Nama Sub-Kategori harus unik dalam satu Kategori Biaya induk | Must Have |
| FR-02-008 | Sub-Kategori yang sudah memiliki Item Biaya aktif tidak dapat dihapus — hanya dapat dinonaktifkan | Must Have |
| FR-02-009 | Sub-Kategori yang dinonaktifkan tidak muncul di dropdown form PDO baru | Must Have |

#### FR-02-C: Item Biaya

| ID | Requirement | Prioritas |
|----|-------------|-----------|
| FR-02-010 | Admin dapat menambah Item Biaya baru dengan field: Kategori Induk, Sub-Kategori Induk, Kode Item, Nama Item, No Akun Default, Satuan Default, Tarif Default, **Mode Input** (`manual` / `auto_external`), Rutin (Ya/Tidak), Wajib Bukti (Ya/Tidak), Threshold Wajib Bukti (nominal), Threshold Wajib Penjelasan Selisih (nominal), Status (Aktif/Nonaktif), dan Catatan | Must Have |
| FR-02-010a | Field **Mode Input** menentukan bagaimana nilai item biaya diisi pada form PDO: `manual` = Kerani mengisi langsung; `auto_external` = nilai diambil dari aplikasi eksternal melalui tombol "Ambil Biaya" | Must Have |
| FR-02-010b | Untuk item dengan `mode_input = auto_external`, kolom input **Jumlah** pada tabel PDO digantikan oleh tombol **"Ambil Biaya"**. Input manual pada kolom tersebut dinonaktifkan (disabled/read-only) | Must Have |
| FR-02-010c | Tombol "Ambil Biaya" memanggil fungsi placeholder `fetchExternalCost(item_id, periode)` yang kerangkanya harus disiapkan oleh developer namun implementasi pemanggilan API eksternal dilakukan secara terpisah di luar scope ini | Must Have |
| FR-02-010d | Setelah data berhasil diambil (baik dari placeholder maupun implementasi nyata), nilai **Jumlah** terisi otomatis dan kolom tetap read-only (tidak dapat diubah manual oleh Kerani) | Must Have |
| FR-02-010e | Pada tampilan Master Data (tab Item Biaya), kolom Mode Input ditampilkan dengan badge: `manual` = badge abu (Draft), `auto_external` = badge ungu (Purple) | Should Have |
| FR-02-011 | Field "Rutin" hanya menerima nilai "Ya" atau "Tidak" | Must Have |
| FR-02-012 | Item Biaya yang sudah pernah digunakan dalam PDO tidak dapat dihapus (hard delete), hanya dapat dinonaktifkan (soft delete) | Must Have |
| FR-02-013 | Perubahan tarif default pada master Item Biaya tidak memengaruhi PDO yang sudah dibuat sebelumnya | Must Have |
| FR-02-014 | Item Biaya nonaktif tidak muncul di dropdown form PDO baru, namun tetap tampil di rincian PDO lama | Must Have |
| FR-02-015 | Admin dapat melihat hierarki lengkap Kategori → Sub-Kategori → Item Biaya dalam tampilan pohon (tree view) yang dapat di-expand/collapse | Should Have |
| FR-02-016 | Sistem menampilkan preview dampak pengisian form Item Biaya (No Akun, Satuan, Tarif, status Rutin, Mode Input) sebelum disimpan | Nice to Have |
| FR-02-017 | Admin dapat melakukan bulk import Item Biaya melalui file Excel | Nice to Have |

#### FR-02-D: User & Role

| ID | Requirement | Prioritas |
|----|-------------|-----------|
| FR-02-018 | Sistem memiliki 8 peran (role): **Admin**, Kerani, Asisten Kebun, Manajer Kebun, Manajer Keuangan, **Staff Keuangan**, Direktur Keuangan, dan Staff Purchasing | Must Have |
| FR-02-019 | Setiap user memiliki tepat satu peran. Tidak ada multi-role dalam satu akun | Must Have |
| FR-02-020 | Binding user ke Unit Kebun: **Kerani dan Asisten Kebun** terikat pada tepat satu Unit Kebun dan hanya dapat mengakses data PDO unit tersebut. **Manajer Kebun** berkedudukan di Head Office dan bertanggung jawab untuk semua kebun — dapat mengakses data PDO semua unit. **Manajer Keuangan, Staff Keuangan, Direktur Keuangan, Staff Purchasing, dan Admin** juga tidak terikat unit dan dapat mengakses data semua unit | Must Have |
| FR-02-021 | **Admin** adalah role khusus yang berwenang: membuat/mengubah/menonaktifkan akun user, mengelola seluruh master data, dan mengkonfigurasi pengaturan sistem (threshold, WhatsApp gateway). Admin tidak terlibat dalam alur approval PDO | Must Have |
| FR-02-022 | Profil setiap user menyimpan **Nomor WhatsApp** sebagai field wajib yang digunakan untuk pengiriman notifikasi. Admin dapat mengisi dan mengubah nomor WhatsApp saat membuat atau mengedit akun user | Must Have |
| FR-02-023 | Admin dapat mengatur threshold wajib bukti dan wajib penjelasan selisih secara global dari halaman Pengaturan | Should Have |

---

### FR-03: Pembuatan PDO Bulanan

**Deskripsi:** Fitur inti yang memungkinkan Kerani menyusun pengajuan dana operasional bulanan.

> **Catatan Desain (CL-08):** Kolom Remisi I dan Remisi II dihapus dari seluruh sistem. Setiap baris item biaya hanya memiliki satu kolom **Jumlah** (nominal pengajuan). Seluruh referensi "Remisi" di dokumen ini mengacu pada keputusan ini.

| ID | Requirement | Prioritas |
|----|-------------|-----------|
| FR-03-001 | Sistem hanya mengizinkan satu PDO Bulanan per kombinasi Unit Kebun + Periode (bulan dan tahun). Sistem menolak pembuatan PDO kedua untuk kombinasi yang sama | Must Have |
| FR-03-002 | Saat Kerani membuat PDO Bulanan baru, sistem secara otomatis mengisi tabel rincian dengan semua Item Biaya yang berstatus Aktif dan memiliki atribut Rutin = Ya, diurutkan berdasarkan hierarki Kategori → Sub-Kategori → Item | Must Have |
| FR-03-003 | Template otomatis mengisi kolom Item Biaya, No Akun, Satuan, dan Tarif dari data master. Kolom Jumlah, Keterangan, dan Qty dibiarkan kosong untuk diisi Kerani | Must Have |
| FR-03-004 | Form header PDO memiliki field: PT, Unit Kebun, Periode (bulan/tahun), Tanggal Pengajuan, dan Dibuat Oleh | Must Have |
| FR-03-005 | Kerani dapat menambahkan baris item biaya non-rutin ke tabel rincian PDO dengan cara memilih dari dropdown hierarkis (Kategori → Sub-Kategori → Item) | Must Have |
| FR-03-006 | Saat Item Biaya dipilih dari dropdown, sistem otomatis mengisi No Akun, Satuan, dan Tarif dari master data | Must Have |
| FR-03-006a | Jika Item Biaya yang dipilih memiliki `mode_input = auto_external`, kolom Jumlah pada baris tersebut dinonaktifkan (read-only) dan digantikan oleh tombol **"Ambil Biaya"** | Must Have |
| FR-03-006b | Tombol "Ambil Biaya" memanggil fungsi placeholder `fetchExternalCost(item_id, periode)`. Setelah nilai berhasil diambil, kolom Jumlah terisi otomatis dan tetap read-only | Must Have |
| FR-03-006c | Baris dengan `mode_input = auto_external` yang belum diklik "Ambil Biaya" (Jumlah masih kosong/nol) dianggap invalid dan memblokir submit PDO, dengan pesan: "Klik 'Ambil Biaya' untuk mengisi nilai item ini" | Must Have |
| FR-03-007 | Kerani dapat mengubah nilai No Akun, Satuan, dan Tarif yang sudah terisi otomatis | Must Have |
| FR-03-008 | Total Pengajuan PDO = jumlah semua nilai Jumlah dari seluruh baris. Kalkulasi dilakukan real-time di frontend dan divalidasi ulang di backend saat submit | Must Have |
| FR-03-009 | Grand Total ditampilkan secara otomatis di footer tabel | Must Have |
| FR-03-010 | Kerani dapat menduplikasi baris yang ada untuk mempercepat pengisian item biaya serupa | Should Have |
| FR-03-011 | Kerani dapat menghapus baris item biaya dari PDO selama status masih Draft | Must Have |
| FR-03-012 | Sistem menandai baris sebagai invalid (highlight merah) jika: field Keterangan kosong, atau Jumlah = 0 | Must Have |
| FR-03-013 | Sistem mencegah submit PDO jika masih ada baris yang invalid | Must Have |
| FR-03-014 | Sistem mencegah input nilai negatif pada kolom Jumlah, Qty, dan Tarif | Must Have |
| FR-03-015 | Kerani dapat menyimpan PDO sebagai Draft sebelum disubmit, sehingga pengisian dapat dilanjutkan kemudian | Must Have |
| FR-03-016 | Nomor PDO dibuat otomatis oleh sistem dengan format: `PDO-[YYYY]-[MM]-[KODE_UNIT]-[NOMOR_URUT]`. Kode unit resmi: **KP** (Kebun Kota Pinang), **BN** (Binanga), **JM** (Janji Matogu), **SS** (Sosa). Contoh: `PDO-2026-06-KP-001` | Must Have |
| FR-03-017 | Kerani dapat melakukan pencarian dan filter pada daftar PDO berdasarkan nomor PDO, status, dan periode | Must Have |
| FR-03-018 | Satu item biaya yang sama boleh muncul lebih dari satu kali dalam satu PDO selama kolom Keterangan dibedakan. Sistem menampilkan peringatan visual (bukan blokir) jika item yang sama ditambahkan | Must Have |

---

### FR-03A: Pencatatan Transfer Dana

**Deskripsi:** Setelah PDO berstatus Final, Manajer Keuangan atau Staff Keuangan mencatat setiap transfer dana yang dikirim ke kebun. Satu item biaya dapat menerima lebih dari satu transfer (bertahap). Riwayat setiap transfer disimpan otomatis oleh sistem.

> **Contoh kasus:** Kebun mengajukan Rp 5.000.000 untuk pembelian polybag. Setelah disetujui Direktur, Finance mentransfer Rp 3.000.000 terlebih dahulu, lalu Rp 2.000.000 berikutnya. Keduanya dicatat sebagai dua entri transfer terpisah, sehingga total transfer tercatat Rp 5.000.000.

| ID | Requirement | Prioritas |
|----|-------------|-----------|
| FR-03A-001 | Manajer Keuangan dan Staff Keuangan dapat mencatat entri transfer dana untuk setiap item biaya PDO yang sudah berstatus Final, kapan saja tanpa batasan urutan dengan pencatatan realisasi | Must Have |
| FR-03A-002 | Satu item biaya dapat memiliki **lebih dari satu entri transfer** (transfer dilakukan bertahap). Setiap entri transfer dicatat secara terpisah dan riwayatnya tersimpan otomatis oleh sistem | Must Have |
| FR-03A-003 | Setiap entri transfer memiliki field wajib: **Tanggal Transfer**, **Nominal Transfer**, **Nomor Referensi Transfer** (nomor bukti bank/rekening), dan **Catatan** (opsional) | Must Have |
| FR-03A-004 | **Total Transfer per item** = jumlah kumulatif semua entri transfer yang sudah dicatat untuk item tersebut. Nilai ini dihitung otomatis oleh sistem setiap kali entri baru ditambahkan | Must Have |
| FR-03A-005 | Jika Total Transfer suatu item berbeda dari Jumlah Pengajuan yang disetujui, sistem menampilkan **peringatan visual** (badge "Selisih Transfer") beserta nominal selisihnya. Sistem tidak memblokir — pencatatan tetap diizinkan | Must Have |
| FR-03A-006 | Nilai yang digunakan sebagai dasar kalkulasi **Saldo Realisasi** (Transfer - Realisasi) adalah Total Transfer kumulatif per item, bukan Jumlah Pengajuan | Must Have |
| FR-03A-007 | Jika belum ada entri transfer untuk suatu item, nilai Transfer ditampilkan sebagai **Rp 0** dan Saldo Realisasi = 0 - Total Realisasi (negatif/over jika ada realisasi) | Must Have |
| FR-03A-008 | Manajer Keuangan dan Staff Keuangan dapat melihat **Riwayat Transfer** per item biaya: daftar semua entri transfer secara kronologis dengan kolom Tanggal, Nominal, No Referensi, Dicatat Oleh, dan Catatan | Must Have |
| FR-03A-009 | Setiap entri transfer yang sudah disimpan **tidak dapat dihapus**, hanya dapat dikoreksi oleh Manajer Keuangan atau Staff Keuangan. Setiap koreksi dicatat dalam audit log: nilai lama, nilai baru, siapa yang mengubah, dan kapan | Must Have |
| FR-03A-010 | Pencatatan transfer baru tidak dapat dilakukan setelah PDO berstatus **Closed** | Must Have |
| FR-03A-011 | Dashboard dan KPI card "Total Transfer" menampilkan jumlah kumulatif dari semua entri transfer yang sudah dicatat untuk PDO tersebut | Must Have |

---

### FR-03B: Penutupan PDO (Status Closed)

**Deskripsi:** PDO yang sudah Final akan ditutup secara otomatis atau manual. Setelah Closed, input realisasi baru tidak dapat dilakukan.

| ID | Requirement | Prioritas |
|----|-------------|-----------|
| FR-03B-001 | Sistem secara otomatis mengubah status PDO Final menjadi **Closed** pada **tanggal terakhir bulan berjalan** (hari terakhir bulan periode PDO). Contoh: PDO periode Juni 2026 otomatis Closed pada 30 Juni 2026 pukul 23:59 WIB | Must Have |
| FR-03B-002 | **Manajer Keuangan** dapat menutup PDO lebih cepat dari jadwal otomatis dengan cara memilih tanggal penutupan manual pada form penutupan PDO. Tanggal yang dipilih tidak boleh lebih awal dari hari ini dan tidak boleh lebih lambat dari tanggal otomatis | Must Have |
| FR-03B-003 | Pada form penutupan manual, Manajer Keuangan mengisi: Tanggal Penutupan dan Catatan Penutupan (opsional). Aksi ini membutuhkan konfirmasi sebelum dieksekusi | Must Have |
| FR-03B-004 | Setelah PDO berstatus Closed, tidak ada entri realisasi baru yang dapat ditambahkan dan entri yang sudah ada tidak dapat diedit | Must Have |
| FR-03B-005 | Penutupan PDO (otomatis maupun manual) dicatat dalam audit log: siapa yang menutup (sistem/user), tanggal penutupan, dan catatan | Must Have |
| FR-03B-006 | PDO dengan status Closed ditampilkan dengan badge "Closed" di Daftar PDO. Filter status di Daftar PDO menyertakan opsi "Closed" | Must Have |

---

**Deskripsi:** Fitur untuk mengajukan dana tambahan di luar PDO Bulanan yang sudah disetujui.

| ID | Requirement | Prioritas |
|----|-------------|-----------|
| FR-04-001 | Kerani hanya dapat membuat PDO Tambahan jika PDO Bulanan untuk periode yang sama sudah berstatus Final | Must Have |
| FR-04-002 | Sistem menampilkan pesan error yang jelas jika Kerani mencoba membuat PDO Tambahan tanpa PDO Bulanan yang Final | Must Have |
| FR-04-003 | PDO Tambahan tidak memiliki template otomatis — semua item biaya diisi manual oleh Kerani | Must Have |
| FR-04-004 | Nomor PDO Tambahan mengikuti format yang berbeda dari PDO Bulanan untuk membedakannya, misalnya: PDOT-[YYYY]-[MM]-[KODE_UNIT]-[NOMOR_URUT] | Must Have |
| FR-04-005 | Tidak ada batasan jumlah PDO Tambahan yang dapat dibuat untuk satu periode | Should Have |
| FR-04-006 | Setelah PDO Tambahan disetujui Direktur, sistem secara otomatis menambahkan item-item biaya dari PDO Tambahan ke bagian **paling bawah** tabel rincian PDO Bulanan induknya | Must Have |
| FR-04-007 | Item-item dari PDO Tambahan dikelompokkan dan dipisahkan dari item PDO Bulanan awal menggunakan **baris header pemisah** yang menampilkan nomor PDO Tambahan (contoh: `▶ PDO Tambahan: PDOT-2026-06-KP-001 — disetujui 15 Jun 2026`). Item dari PDO awal tetap di bagian atas tanpa header khusus | Must Have |
| FR-04-008 | Setelah merger berhasil, Kerani dapat langsung mencatat realisasi untuk item-item dari PDO Tambahan yang sudah ditambahkan | Must Have |
| FR-04-009 | Alur approval PDO Tambahan identik dengan PDO Bulanan (Kerani → Asisten → Manajer Kebun + Manajer Keuangan → Direktur) | Must Have |
| FR-04-010 | PDO Tambahan yang sudah Final dan ter-merge tidak ditampilkan sebagai entri terpisah di Daftar PDO — cukup terlihat di dalam Detail PDO Bulanan induknya melalui baris header pemisah. PDO Tambahan yang masih dalam proses approval (belum Final) tetap muncul sebagai entri terpisah di Daftar PDO dengan label tipe "Tambahan" | Must Have |

---

### FR-05: Approval Workflow

**Deskripsi:** Alur persetujuan bertingkat yang diterapkan pada PDO Bulanan dan PDO Tambahan.

#### Status Lifecycle PDO

```
Draft → Submitted → Reviewed (Asisten) → In Review (Manajer) → Approved (Direktur) → Final
                 ↑                    ↑                     ↑                   ↑
                 └────────────────────┴─────────────────────┴───────────────────┘
                                    Reject (kembali ke Draft)
```

| ID | Requirement | Prioritas |
|----|-------------|-----------|
| FR-05-001 | Setelah Kerani submit, status PDO berubah dari Draft menjadi Submitted, dan notifikasi dikirim ke Asisten Kebun terkait | Must Have |
| FR-05-002 | Asisten Kebun dapat melihat daftar PDO yang menunggu persetujuannya dan melakukan approve atau reject | Must Have |
| FR-05-003 | Setelah Asisten approve, status berubah dan notifikasi dikirim ke Manajer Kebun dan Manajer Keuangan secara bersamaan | Must Have |
| FR-05-004 | Manajer Kebun dan Manajer Keuangan keduanya harus memberikan persetujuan sebelum PDO dapat dilanjutkan ke Direktur | Must Have |
| FR-05-005 | Setelah keduanya (Manajer Kebun dan Manajer Keuangan) approve, notifikasi dikirim ke Direktur Keuangan | Must Have |
| FR-05-006 | Setelah Direktur approve, status PDO berubah menjadi Final | Must Have |
| FR-05-007 | Seorang user tidak dapat memberikan persetujuan pada PDO yang dia buat sendiri | Must Have |
| FR-05-008 | Setiap aksi approve harus dilakukan oleh user dengan peran yang sesuai dengan tahap approval yang sedang berjalan | Must Have |
| FR-05-009 | Siapa pun dalam rantai approval (Asisten, Manajer Kebun, Manajer Keuangan, Direktur) dapat melakukan reject | Must Have |
| FR-05-010 | Saat melakukan reject, approver wajib mengisi alasan penolakan (field tidak boleh kosong) | Must Have |
| FR-05-011 | Setelah reject, status PDO kembali ke Draft dan notifikasi beserta alasan penolakan dikirim ke Kerani | Must Have |
| FR-05-012 | Setelah reject dan direvisi oleh Kerani, proses approval dimulai ulang dari tahap pertama (Asisten) | Must Have |
| FR-05-013 | Seluruh riwayat approval (siapa yang approve/reject, kapan, catatan) ditampilkan dalam Approval Timeline yang dapat diakses dari halaman Daftar PDO | Must Have |
| FR-05-014 | PDO yang sudah Final tidak dapat diedit oleh siapa pun | Must Have |
| FR-05-015 | PDO yang masih Draft dapat diedit oleh Kerani pembuatnya | Must Have |
| FR-05-016 | Setiap tahap approval memiliki SLA 1 hari kerja. Jika approver belum memberikan keputusan dalam 1 hari kerja sejak notifikasi dikirim, sistem mengirimkan notifikasi reminder via WhatsApp kepada approver yang bersangkutan | Must Have |
| FR-05-017 | Pada tahap review paralel Manajer Kebun dan Manajer Keuangan: jika salah satu Manajer melakukan reject, PDO kembali ke Draft. Approval dari Manajer yang sudah menyetujui **hangus** dan tidak tersimpan — setelah Kerani revisi dan submit ulang, **kedua** Manajer harus approve kembali dari awal | Must Have |

---

### FR-06: Pencatatan Realisasi Dana

**Deskripsi:** Fitur untuk mencatat penggunaan dana aktual beserta bukti transaksi setelah dana ditransfer.

| ID | Requirement | Prioritas |
|----|-------------|-----------|
| FR-06-001 | Realisasi hanya dapat dicatat untuk PDO dengan status Final | Must Have |
| FR-06-002 | Kerani dapat mencatat realisasi untuk semua item biaya dalam PDO. Dropdown Sumber Dana menampilkan tiga pilihan: Kas Kebun, Rekening Kebun, dan Rekening Utama Perusahaan | Must Have |
| FR-06-003 | Ketika Staff Purchasing login dan membuka form input realisasi, dropdown **Sumber Dana hanya menampilkan satu pilihan: "Rekening Utama Perusahaan"** — opsi lain tidak muncul dan tidak bisa dipilih. Staff Purchasing dapat mengupdate realisasi item biaya manapun dalam PDO, namun semua entri realisasinya akan selalu bersumber dana Rekening Utama Perusahaan | Must Have |
| FR-06-004 | Setiap entri realisasi memiliki field wajib: Nominal, Tanggal Transaksi, Metode Pembayaran (Tunai / Transfer / Kas Kecil), Nomor Bukti Transaksi, **Sumber Dana**, dan Upload Bukti (file). Nilai Sumber Dana yang tersimpan mengikuti pilihan yang tersedia untuk role user yang bersangkutan | Must Have |
| FR-06-005 | Jika nominal realisasi melebihi nilai Threshold Wajib Bukti dari master item (default Rp 1.000.000), upload bukti transaksi menjadi wajib | Must Have |
| FR-06-006 | Jika selisih antara Total Transfer item dan Total Realisasi item melebihi nilai Threshold Wajib Penjelasan dari master item (default Rp 500.000), field Penjelasan Selisih untuk item tersebut menjadi wajib diisi. Validasi ini bersifat per item — tidak bergantung pada kondisi kumulatif PDO | Must Have |
| FR-06-007 | Satu item biaya dapat memiliki lebih dari satu entri realisasi (pembayaran dilakukan secara bertahap/parsial) | Must Have |
| FR-06-008 | Sistem menghitung dan menampilkan dua level agregasi secara real-time: **(1) Per item** — Total Transfer item, Total Realisasi item, Saldo item, % Realisasi item. **(2) Level PDO** — Total Transfer PDO (kumulatif seluruh item), Total Realisasi PDO (kumulatif seluruh item), Saldo PDO, % Realisasi PDO | Must Have |
| FR-06-009 | **Validasi kumulatif level PDO:** Total Realisasi PDO tidak boleh melebihi Total Transfer PDO. Jika Kerani mencoba menyimpan entri realisasi baru yang akan menyebabkan Total Realisasi PDO > Total Transfer PDO, sistem **memblokir** entri tersebut dengan pesan: "Total realisasi PDO akan melebihi total dana yang ditransfer. Tambahkan dana transfer terlebih dahulu." | Must Have |
| FR-06-010 | **Realisasi over budget per item diizinkan** selama Total Realisasi PDO ≤ Total Transfer PDO. Kerani dapat menggunakan saldo transfer dari item lain yang under-budget untuk menutupi item yang over-budget, tanpa perlu approval tambahan | Must Have |
| FR-06-011 | Sistem menampilkan **peringatan visual per item** (badge "Over Budget") jika Total Realisasi item melebihi Total Transfer item — meskipun secara kumulatif PDO masih dalam batas. Peringatan ini bersifat informatif, tidak memblokir | Must Have |
| FR-06-012 | Sistem menampilkan **indikator kumulatif PDO** di bagian atas halaman input realisasi: Total Transfer PDO, Total Realisasi PDO, Sisa Saldo PDO (Transfer PDO - Realisasi PDO), dan progress bar kumulatif. Indikator ini diperbarui real-time setiap kali entri baru ditambahkan | Must Have |
| FR-06-013 | Tabel input realisasi dapat difilter berdasarkan tab: Semua / Belum Realisasi / Over Budget per Item / Belum Ada Bukti / Butuh Penjelasan | Must Have |
| FR-06-014 | Kerani dapat menyimpan progress input realisasi sebagai Draft sebelum difinalisasi | Should Have |
| FR-06-015 | Setelah semua item selesai diisi, Kerani menandai realisasi sebagai **Selesai Diinput**. Tidak ada proses submit-approval untuk realisasi — Finance melakukan verifikasi secara mandiri dengan melihat bukti yang diunggah. Komunikasi terkait temuan verifikasi dilakukan di luar sistem | Must Have |
| FR-06-016 | Sistem menampilkan ringkasan realisasi real-time di panel atas: Total Transfer PDO, Total Realisasi PDO, Sisa Saldo PDO, Jumlah Item Over Budget (per item), Item Butuh Bukti, Item Butuh Penjelasan | Must Have |
| FR-06-017 | User dapat melihat history seluruh entri realisasi untuk satu item biaya beserta detail setiap transaksi | Must Have |
| FR-06-018 | History realisasi menampilkan: Tanggal, No Bukti, Metode, Nominal, Saldo Item Setelah, Diinput Oleh, Catatan, dan tombol Preview Bukti | Must Have |
| FR-06-019 | Bukti transaksi yang diunggah dapat di-preview langsung dari sistem oleh semua role yang berwenang melihat PDO tersebut | Must Have |
| FR-06-020 | Format file bukti yang diterima: PDF, JPG, PNG. Ukuran maksimum per file: **5 MB** | Must Have |
| FR-06-021 | Kerani dapat mengedit entri realisasi yang sudah diinput selama PDO belum berstatus **Closed**. Jika hasil edit menyebabkan Total Realisasi PDO > Total Transfer PDO, sistem memblokir perubahan tersebut. Setiap perubahan yang berhasil dicatat dalam audit log: nilai lama, nilai baru, diubah oleh siapa, dan kapan | Must Have |

---

### FR-07: Rekapitulasi & Laporan

**Deskripsi:** Fitur untuk menghasilkan laporan terstruktur dari data PDO dan realisasi.

| ID | Requirement | Prioritas |
|----|-------------|-----------|
| FR-07-001 | Sistem menyediakan halaman Rekapitulasi yang menampilkan data PDO per Kategori → Sub-Kategori dengan subtotal otomatis di setiap level | Must Have |
| FR-07-002 | Rekapitulasi menampilkan kolom: Nomor, Kategori, Sub-Kategori, Kode, Jumlah Pengajuan, **Total Transfer** (kumulatif semua entri transfer), Total Realisasi, Saldo | Must Have |
| FR-07-003 | Rekapitulasi dapat difilter berdasarkan Periode, Kategori, dan Sub-Kategori | Must Have |
| FR-07-004 | Sistem menyediakan Laporan Realisasi Dana dengan kolom: Item Biaya, Pengajuan, **Total Transfer** (kumulatif), Total Realisasi, Saldo, dan Status per item | Must Have |
| FR-07-005 | Sistem menyediakan Laporan Over Budget yang menampilkan item-item biaya yang realisasinya melebihi transfer | Must Have |
| FR-07-006 | Sistem menyediakan Laporan Bukti Belum Lengkap yang menampilkan item-item yang belum ada bukti unggahannya | Must Have |
| FR-07-007 | Semua laporan dapat diekspor ke format Excel (.xlsx) | Must Have |
| FR-07-008 | Semua laporan dapat diekspor ke format PDF | Should Have |
| FR-07-009 | Laporan dapat difilter berdasarkan Periode, Unit Kebun, Kategori Biaya, dan Status PDO | Must Have |

---

### FR-08: Notifikasi & Audit Trail

**Deskripsi:** Mekanisme pemberitahuan otomatis dan pencatatan riwayat perubahan untuk kebutuhan transparansi dan audit.

| ID | Requirement | Prioritas |
|----|-------------|-----------|
| FR-08-001 | Sistem mencatat audit log untuk setiap perubahan status PDO: siapa yang mengubah, dari status apa ke status apa, kapan, dan catatan yang ditinggalkan | Must Have |
| FR-08-002 | Sistem mencatat audit log untuk setiap entri realisasi: siapa yang menginput, kapan, nominal, dan perubahan apapun pada entri tersebut | Must Have |
| FR-08-003 | Sistem menampilkan notifikasi in-system (toast notification) untuk setiap aksi yang berhasil atau gagal | Must Have |
| FR-08-004 | Sistem mengirimkan notifikasi in-system kepada approver berikutnya saat PDO berpindah status | Must Have |
| FR-08-005 | Sistem mengirimkan notifikasi in-system dan WhatsApp kepada Kerani saat PDO-nya ditolak, beserta alasan penolakan | Must Have |
| FR-08-006 | Approval Timeline dapat diakses dari halaman Daftar PDO dengan mengklik status badge | Must Have |
| FR-08-007 | Audit log tidak dapat diubah atau dihapus oleh siapa pun | Must Have |
| FR-08-008 | Sistem mendukung pengiriman notifikasi melalui dua channel: **in-system notification** dan **WhatsApp** via WhatsApp gateway perusahaan | Must Have |
| FR-08-009 | Admin dapat mengkonfigurasi integrasi WhatsApp gateway dari halaman Pengaturan dengan field: URL Endpoint Gateway, API Key/Token, dan status koneksi (test connection). Konfigurasi disimpan terenkripsi dan dapat diubah tanpa deployment ulang | Must Have |
| FR-08-010 | Sistem menyediakan template pesan WhatsApp yang dapat dikonfigurasi per jenis notifikasi. Setiap template mendukung variabel dinamis: `{{nama_user}}`, `{{nomor_pdo}}`, `{{periode}}`, `{{unit_kebun}}`, `{{alasan_reject}}`, `{{deadline}}` | Must Have |
| FR-08-011 | Sistem mengirimkan notifikasi reminder otomatis via WhatsApp kepada Kerani, Asisten Kebun, Manajer Kebun, Manajer Keuangan, dan Direktur Keuangan apabila PDO Bulanan untuk bulan berjalan belum disubmit pada tanggal 1 setiap bulan | Must Have |
| FR-08-012 | Admin dapat mengkonfigurasi tanggal dan jam pengiriman reminder bulanan dari halaman Pengaturan | Should Have |

---

## 7. Non-Functional Requirements

### 7.1 Performa

| ID | Requirement |
|----|-------------|
| NFR-01 | Halaman dashboard harus selesai dimuat dalam ≤3 detik pada koneksi 10 Mbps |
| NFR-02 | Tabel dengan 100+ baris item biaya harus dapat dirender dalam ≤3 detik |
| NFR-03 | Operasi CRUD (buat, baca, update, hapus) untuk PDO dan master data harus selesai dalam ≤2 detik |
| NFR-04 | Upload file bukti hingga ukuran maksimum yang ditentukan harus selesai dalam ≤10 detik pada koneksi 10 Mbps |
| NFR-05 | Sistem harus mampu melayani minimal 50 pengguna bersamaan tanpa degradasi performa yang signifikan |

### 7.2 Keamanan

| ID | Requirement |
|----|-------------|
| NFR-06 | Seluruh akses ke sistem wajib melalui autentikasi (login dengan username dan password) |
| NFR-07 | Setiap endpoint API dilindungi oleh verifikasi peran (Role-Based Access Control) |
| NFR-08 | User dari satu unit kebun tidak dapat mengakses atau memodifikasi data unit kebun lain |
| NFR-09 | Seluruh transmisi data menggunakan HTTPS/TLS |
| NFR-10 | Password disimpan menggunakan algoritma hashing yang aman (bcrypt atau Argon2) |
| NFR-11 | Session atau token autentikasi memiliki masa berlaku dan dapat di-invalidate saat logout |
| NFR-12 | Sistem mencatat log akses gagal (failed login attempts) |

### 7.3 Ketersediaan & Reliabilitas

| ID | Requirement |
|----|-------------|
| NFR-13 | Sistem tersedia minimal 99% uptime pada hari kerja (Senin–Jumat, 07.00–20.00 WIB) |
| NFR-14 | Backup database dilakukan minimal satu kali sehari |
| NFR-15 | Data tidak boleh hilang akibat crash sistem (recover point objective ≤24 jam) |

### 7.4 Kompatibilitas

| ID | Requirement |
|----|-------------|
| NFR-16 | Aplikasi berjalan dengan baik pada browser terbaru: Google Chrome (versi terbaru-2), Mozilla Firefox (versi terbaru-2), dan Microsoft Edge (versi terbaru-2) |
| NFR-17 | Tampilan responsive dan dapat digunakan di layar dengan lebar minimum 375px (smartphone) dan 1024px (laptop/desktop) |
| NFR-18 | Tidak memerlukan instalasi plugin atau software tambahan di sisi pengguna |

### 7.5 Usability

| ID | Requirement |
|----|-------------|
| NFR-19 | User baru dengan latar belakang akuntansi (non-IT) harus dapat menggunakan fitur pembuatan PDO tanpa pelatihan khusus (self-onboarding) setelah membaca panduan singkat 1 halaman |
| NFR-20 | Setiap aksi destruktif (hapus baris, reject PDO) harus meminta konfirmasi sebelum dieksekusi |
| NFR-21 | Pesan error harus bersifat deskriptif dan memberikan panduan cara mengoreksinya |

---

## 8. User Stories

### US-01: Template Otomatis PDO Bulanan

**As a** Kerani,  
**I want** sistem untuk mengisi otomatis daftar item biaya rutin saat saya membuat PDO Bulanan baru,  
**So that** saya tidak perlu menginput ulang dari nol setiap bulan dan tidak ada item rutin yang terlewat.

**Acceptance Criteria:**
- Saat form PDO Bulanan baru dibuka, tabel rincian sudah berisi semua item dengan atribut Rutin = Ya dan Status = Aktif
- Setiap baris terisi otomatis: Item Biaya, No Akun, Satuan, dan Tarif dari master data
- Kolom **Jumlah**, Keterangan, dan Qty masih kosong dan harus diisi oleh Kerani
- Item diurutkan berdasarkan hierarki: Kategori (A, B, C...) → Sub-Kategori → Item
- Jika tidak ada item rutin di master, tabel dimulai kosong dan Kerani perlu menambah item secara manual

---

### US-02: Penambahan Item Non-Rutin

**As a** Kerani,  
**I want** menambahkan item biaya yang tidak ada dalam template ke PDO,  
**So that** saya dapat mengajukan biaya-biaya khusus yang hanya terjadi di bulan tertentu.

**Acceptance Criteria:**
- Tombol "+ Tambah Baris" menambahkan baris kosong di bagian bawah tabel
- Baris baru memiliki dropdown Kategori yang dapat dipilih
- Saat Kategori dipilih, dropdown Sub-Kategori terisi sesuai Kategori yang dipilih
- Saat Sub-Kategori dipilih, dropdown Item Biaya terisi sesuai Sub-Kategori yang dipilih
- Saat Item Biaya dipilih, No Akun, Satuan, dan Tarif terisi otomatis dari master
- Baris dapat dihapus dengan tombol "Hapus" di kolom Aksi

---

### US-03: Kalkulasi Otomatis

**As a** Kerani,  
**I want** total setiap baris dan grand total dihitung secara otomatis,  
**So that** saya tidak perlu menghitung manual dan tidak ada risiko kesalahan kalkulasi.

**Acceptance Criteria:**
- Grand Total diperbarui secara real-time saat nilai **Jumlah** pada baris manapun berubah
- Baris dengan Jumlah = 0 ditandai sebagai invalid (highlight merah)
- Grand Total ditampilkan di footer tabel yang selalu terlihat meski tabel di-scroll

---

### US-04: Validasi Sebelum Submit

**As a** Kerani,  
**I want** sistem memberitahu saya jika ada data yang belum lengkap sebelum submit,  
**So that** saya tidak mengirim PDO yang tidak lengkap ke Asisten.

**Acceptance Criteria:**
- Baris dengan Keterangan kosong atau Total = 0 diberi highlight merah
- Tombol Submit dinonaktifkan atau menampilkan error jika masih ada baris invalid
- Pesan error menjelaskan baris mana yang bermasalah dan apa yang perlu dilengkapi

---

### US-05: Submit PDO

**As a** Kerani,  
**I want** mengirimkan PDO yang sudah selesai ke Asisten untuk disetujui,  
**So that** proses approval dapat segera dimulai.

**Acceptance Criteria:**
- Modal konfirmasi muncul sebelum submit, menampilkan ringkasan PDO (grand total, jumlah item)
- Setelah submit berhasil, status PDO berubah menjadi "Submitted"
- PDO tidak lagi dapat diedit setelah di-submit
- Asisten Kebun menerima notifikasi bahwa ada PDO yang menunggu review-nya

---

### US-06: Approval oleh Asisten

**As an** Asisten Kebun,  
**I want** melihat dan memberikan persetujuan pada PDO yang diajukan Kerani,  
**So that** PDO dapat dilanjutkan ke tahap review Manajer.

**Acceptance Criteria:**
- Asisten dapat melihat daftar PDO dengan status "Submitted" yang menunggu persetujuannya
- Asisten dapat membuka detail lengkap PDO sebelum memutuskan
- Tombol "Approve" tersedia dan mengubah status PDO ke tahap berikutnya
- Setelah approve, Manajer Kebun dan Manajer Keuangan menerima notifikasi

---

### US-07: Reject dengan Alasan

**As an** approver (Asisten, Manajer, atau Direktur),  
**I want** menolak PDO dengan menyertakan alasan yang jelas,  
**So that** Kerani mengetahui apa yang perlu diperbaiki.

**Acceptance Criteria:**
- Modal reject menampilkan textarea untuk alasan penolakan
- Field alasan wajib diisi — tombol Reject dinonaktifkan jika kosong
- Setelah reject berhasil, status PDO kembali ke Draft
- Kerani menerima notifikasi yang berisi alasan penolakan
- Alasan reject tercatat dalam Approval Timeline

---

### US-08: Revisi PDO Setelah Reject

**As a** Kerani,  
**I want** melihat alasan penolakan dan merevisi PDO yang ditolak,  
**So that** saya dapat memperbaiki dan mengajukan kembali dengan cepat.

**Acceptance Criteria:**
- Kerani dapat melihat alasan penolakan di Approval Timeline PDO yang bersangkutan
- PDO yang dikembalikan ke Draft dapat diedit kembali oleh Kerani
- Setelah revisi selesai, Kerani dapat submit ulang
- Submit ulang memulai proses approval dari tahap awal (Asisten)

---

### US-09: Approval Final oleh Direktur

**As a** Direktur Keuangan,  
**I want** memberikan persetujuan final pada PDO yang sudah disetujui Manajer,  
**So that** dana dapat segera diproses untuk ditransfer ke kebun.

**Acceptance Criteria:**
- Direktur hanya dapat approve PDO yang sudah disetujui oleh Manajer Kebun dan Manajer Keuangan
- Setelah Direktur approve, status PDO berubah menjadi "Final"
- PDO Final tidak dapat diubah atau diedit oleh siapa pun
- Kerani menerima notifikasi bahwa PDO sudah Final dan dapat dicatat realisasinya

---

### US-10: Pembuatan PDO Tambahan

**As a** Kerani,  
**I want** membuat PDO Tambahan untuk kebutuhan dana yang muncul di luar PDO Bulanan,  
**So that** kebutuhan mendesak kebun dapat terpenuhi melalui proses yang resmi.

**Acceptance Criteria:**
- Menu Buat PDO Tambahan hanya aktif jika sudah ada PDO Bulanan yang Final untuk periode tersebut
- Jika belum ada PDO Bulanan Final, sistem menampilkan pesan yang jelas
- Form PDO Tambahan tidak memiliki template — tabel rincian dimulai kosong
- Proses approval identik dengan PDO Bulanan

---

### US-11: Merger PDO Tambahan ke Bulanan

**As a** Kerani,  
**I want** item-item dari PDO Tambahan yang sudah disetujui otomatis masuk ke PDO Bulanan,  
**So that** saya hanya perlu mencatat realisasi di satu tempat (PDO Bulanan).

**Acceptance Criteria:**
- Setelah Direktur approve PDO Tambahan, item-itemnya langsung ditambahkan ke tabel rincian PDO Bulanan induknya
- Item yang berasal dari PDO Tambahan ditandai dengan referensi ke nomor PDO Tambahan asalnya
- Kerani langsung dapat mencatat realisasi untuk item-item yang baru ditambahkan

---

### US-12: Input Realisasi Dana

**As a** Kerani,  
**I want** mencatat realisasi penggunaan dana untuk setiap item biaya,  
**So that** ada bukti tertulis dari setiap pengeluaran yang terjadi.

**Acceptance Criteria:**
- Hanya PDO dengan status Final yang menampilkan form input realisasi
- Setiap baris item biaya memiliki field input: Nominal Baru, Tanggal Transaksi, Metode, No Bukti
- Saldo dan % Realisasi diperbarui secara otomatis saat nilai nominal input berubah
- User dapat menyimpan satu item sebelum lanjut ke item berikutnya

---

### US-13: Upload Bukti Transaksi

**As a** Kerani,  
**I want** mengunggah foto atau scan bukti transaksi untuk setiap pengeluaran,  
**So that** setiap realisasi memiliki dokumentasi yang dapat diverifikasi.

**Acceptance Criteria:**
- Setiap entri realisasi memiliki tombol upload bukti
- Format yang diterima: PDF, JPG, PNG
- Jika nominal realisasi di atas threshold (default Rp 1.000.000), upload menjadi wajib sebelum submit
- Setelah upload berhasil, tombol berubah menjadi "Preview" dan user dapat melihat file yang sudah diunggah

---

### US-14: Validasi Threshold Penjelasan

**As a** Kerani,  
**I want** sistem memberitahu saya jika ada selisih besar antara transfer dan realisasi yang perlu dijelaskan,  
**So that** saya tidak lupa mengisi penjelasan sebelum submit.

**Acceptance Criteria:**
- Jika |Transfer - Realisasi| > threshold (default Rp 500.000), field "Penjelasan Selisih" menjadi wajib diisi
- Badge "Butuh Penjelasan" ditampilkan pada baris tersebut
- Tab filter "Butuh Penjelasan" menampilkan semua item yang memerlukan penjelasan
- Tombol "Simpan" pada baris tersebut diblokir hingga Penjelasan Selisih diisi

---

### US-15: Pembatasan Akses Staff Purchasing

**As a** Staff Purchasing,  
**I want** mencatat realisasi untuk pembayaran yang dilakukan dari rekening utama perusahaan,  
**So that** data realisasi PDO kebun tetap lengkap meski ada pembayaran yang dilakukan dari pusat.

**Acceptance Criteria:**
- Staff Purchasing dapat melihat dan mengupdate realisasi untuk item biaya manapun dalam PDO
- Saat Staff Purchasing membuka form input realisasi, dropdown Sumber Dana hanya menampilkan satu opsi: "Rekening Utama Perusahaan" — opsi Kas Kebun dan Rekening Kebun tidak muncul
- Entri realisasi yang dibuat Staff Purchasing selalu tercatat dengan Sumber Dana "Rekening Utama Perusahaan"

---

### US-16: History Realisasi per Item

**As a** Kerani,  
**I want** melihat riwayat lengkap semua entri realisasi untuk satu item biaya,  
**So that** saya dapat melacak kapan, siapa, dan berapa yang sudah dicatat untuk item tersebut.

**Acceptance Criteria:**
- Setiap baris di tabel input realisasi memiliki tombol "↺ History"
- Halaman History menampilkan semua entri realisasi untuk item tersebut secara kronologis
- History menampilkan: Tanggal, No Bukti, Metode, Nominal, Saldo Setelah, Diinput Oleh, Catatan
- User dapat men-preview bukti transaksi langsung dari halaman History

---

### US-17: Dashboard Monitoring

**As a** Manajer Keuangan,  
**I want** melihat ringkasan kondisi semua PDO aktif dalam satu tampilan,  
**So that** saya dapat langsung mengetahui mana yang butuh tindakan segera.

**Acceptance Criteria:**
- Dashboard menampilkan KPI: Total Pengajuan, Total Transfer, Total Realisasi, Saldo, dan Item Belum Bukti
- Grafik menampilkan realisasi vs pengajuan per kategori biaya
- Dashboard dapat difilter berdasarkan periode, unit kebun, dan kategori
- Manajer Keuangan dapat melihat data dari semua unit kebun (tidak terbatas pada satu unit)

---

### US-18: Approval Timeline

**As a** Kerani,  
**I want** melihat status approval PDO saya secara real-time,  
**So that** saya tahu sudah sampai di tahap mana dan tidak perlu menghubungi pihak lain untuk menanyakan status.

**Acceptance Criteria:**
- Status badge PDO pada Daftar PDO dapat diklik dan membuka halaman Approval Timeline
- Timeline menampilkan semua tahap approval dalam urutan kronologis
- Tahap yang sudah selesai menampilkan nama approver dan tanggal/waktu approval
- Tahap yang sedang menunggu ditandai dengan jelas
- Alasan reject ditampilkan pada tahap yang melakukan reject

---

### US-19: Export Rekapitulasi

**As a** Manajer Keuangan,  
**I want** mengekspor rekapitulasi PDO ke file Excel,  
**So that** saya dapat menggunakannya dalam presentasi dan rapat manajemen.

**Acceptance Criteria:**
- Tombol "Export" tersedia di halaman Rekapitulasi dan halaman Laporan
- File Excel yang dihasilkan memiliki format yang rapi dan siap digunakan (termasuk header, subtotal per kategori, dan grand total)
- Filter yang aktif saat export diterapkan pada data yang diekspor

---

### US-20: Manajemen Master Data oleh Admin

**As an** Admin,  
**I want** menambahkan kategori, sub-kategori, dan item biaya baru ke dalam sistem,  
**So that** form PDO selalu mencerminkan struktur biaya terkini operasional kebun.

**Acceptance Criteria:**
- Admin dapat menambah Kategori dengan field yang lengkap dan kode yang unik
- Admin dapat menambah Sub-Kategori di bawah Kategori yang dipilih
- Admin dapat menambah Item Biaya dengan semua atribut termasuk field Rutin (hanya Ya/Tidak)
- Item baru dengan Rutin = Ya akan muncul di template PDO Bulanan berikutnya
- Preview dampak ditampilkan saat mengisi form Item Biaya (No Akun, Satuan, Tarif, status Rutin terlihat sebelum disimpan)

---

### US-21: Pencarian Global

**As a** pengguna,  
**I want** mencari PDO, item biaya, atau keterangan dari kolom pencarian yang selalu tersedia,  
**So that** saya dapat menemukan data yang saya cari dengan cepat tanpa harus navigasi ke halaman tertentu.

**Acceptance Criteria:**
- Kolom pencarian global selalu tersedia di topbar semua halaman
- Pencarian memfilter data pada halaman yang sedang aktif (nomor PDO, kategori, item biaya, keterangan)
- Pencarian bersifat real-time (filter saat mengetik, tanpa perlu menekan Enter)

---

### US-22: Pengelolaan Satu PDO per Bulan

**As a** sistem,  
**I want** mencegah Kerani membuat PDO Bulanan kedua untuk bulan yang sama,  
**So that** tidak ada duplikasi pengajuan dana untuk satu periode.

**Acceptance Criteria:**
- Saat Kerani membuat PDO baru, sistem memeriksa apakah sudah ada PDO Bulanan untuk Unit Kebun + Periode yang sama
- Jika sudah ada, sistem menampilkan pesan error yang informatif beserta nomor PDO yang sudah ada
- Kerani diarahkan untuk membuat PDO Tambahan jika membutuhkan dana tambahan

---

## 9. Wireframe References

Mockup interaktif lengkap tersedia di file `pdo_mockup.html`. Berikut deskripsi singkat setiap halaman utama:

### 9.1 Dashboard (Halaman Utama)

**Elemen kunci:**
- Topbar: input pencarian global + profil user + badge peran aktif
- Hero section: judul halaman, deskripsi singkat, tombol "Buat PDO Baru"
- Filter bar: dropdown Periode, Unit Kebun, Kategori, Sub-Kategori + tombol Terapkan
- Grid 5 KPI Card (klik untuk navigasi)
- Grafik bar horizontal: Pengajuan vs Realisasi per Kategori
- Donut chart: Proporsi biaya dengan badge % realisasi keseluruhan

### 9.2 Daftar PDO

**Elemen kunci:**
- Hero: judul + tombol "Buat PDO Baru"
- Filter: input pencarian + dropdown status + dropdown tipe (Bulanan / Tambahan) + tombol Export Excel
- Tabel: Nomor PDO, PT, Unit, Periode, **Tipe** (Bulanan / Tambahan), Total Pengajuan, Transfer, Realisasi, Saldo, Status (badge, clickable → Approval Timeline), Dibuat Oleh, Aksi (tombol Detail + Edit)
- PDO Tambahan yang sudah Final dan ter-merge **tidak muncul** di tabel ini — hanya yang masih dalam proses approval yang tampil dengan label "Tambahan"

### 9.3 Form Buat/Edit PDO

**Elemen kunci:**
- Step wizard (kiri): 1. Informasi Umum, 2. Detail Permintaan, 3. Review & Submit
- Section Informasi Umum: 5 field (PT, Unit Kebun, Periode, Tanggal Pengajuan, Dibuat Oleh) — **field Remisi dihapus (CL-08)**
- Tabel editable rincian biaya: dropdown cascading (Kategori → Sub-Kategori → Item), autofill No Akun/Satuan/Tarif, satu kolom **Jumlah** (bukan Remisi I/II), kalkulasi Grand Total otomatis, tombol Duplikasi dan Hapus per baris
- Baris dengan `mode_input = auto_external`: kolom Jumlah digantikan tombol "Ambil Biaya" (read-only setelah diisi)
- Footer tabel: Grand Total Pengajuan
- Tombol aksi: Simpan Draft + Submit Pengajuan

### 9.3A Form Pencatatan Transfer Dana (halaman baru)

**Akses:** Manajer Keuangan dan Staff Keuangan, setelah PDO berstatus Final dan sebelum Closed

**Elemen kunci:**
- Tabel daftar item biaya PDO dengan kolom: Item Biaya, Jumlah Pengajuan, **Total Transfer** (kumulatif, read-only), Selisih vs Pengajuan (kalkulasi otomatis, badge peringatan jika berbeda), Total Realisasi, Saldo
- Setiap baris item memiliki tombol **"+ Catat Transfer"** dan tombol **"Riwayat"**
- Form inline (atau modal) Catat Transfer per item dengan field: Tanggal Transfer, Nominal, Nomor Referensi Transfer, Catatan (opsional)
- Setelah disimpan, Total Transfer pada baris tersebut langsung diperbarui
- Badge **"Selisih Transfer"** muncul pada baris yang Total Transfer-nya berbeda dari Jumlah Pengajuan

### 9.3B Riwayat Transfer per Item (halaman/drawer)

**Akses:** Manajer Keuangan dan Staff Keuangan

**Elemen kunci:**
- Summary cards: Jumlah Pengajuan, Total Transfer (kumulatif), Selisih, Jumlah Entri Transfer
- Tabel riwayat transfer: Tanggal, Nominal, No Referensi, Dicatat Oleh, Catatan, Aksi (koreksi)
- Tombol koreksi per baris → form edit dengan audit log otomatis

### 9.4 Detail PDO

**Elemen kunci:**
- 4 KPI Card: Total Pengajuan, Total Transfer, Total Realisasi, Saldo, Status — **KPI Remisi I dan Remisi II dihapus (CL-08)**
- Tabel hierarkis 3 level (Kategori → Sub-Kategori → Item) yang dapat di-expand/collapse untuk item-item PDO Bulanan awal
- Di bagian **paling bawah** tabel: baris header pemisah berwarna berbeda untuk setiap PDO Tambahan yang sudah ter-merge (contoh: `▶ PDO Tambahan: PDOT-2026-06-KP-001`), diikuti item-item dari PDO Tambahan tersebut
- Setiap item dapat diklik untuk membuka drawer detail di sisi kanan
- Status badge per item: Sesuai / Ada Selisih / Over Budget / Belum Ada Bukti
- Tombol: Kembali, Input Realisasi, Export PDF

### 9.5 Input Realisasi

**Elemen kunci:**
- **Indikator kumulatif PDO (sticky di atas tabel):** Total Transfer PDO, Total Realisasi PDO, Sisa Saldo PDO (Transfer PDO - Realisasi PDO), progress bar kumulatif — diperbarui real-time. Progress bar berubah merah saat mendekati atau mencapai batas total transfer PDO
- Header info PDO (read-only): Nomor PDO, PT/Unit, Periode
- Tab filter: Semua / Belum Realisasi / Over Budget per Item / Belum Ada Bukti / Butuh Penjelasan
- Tabel editable per item dengan kolom: input nominal, tanggal, metode, no bukti, upload bukti, penjelasan selisih; plus kolom read-only: Total Transfer item, Total Realisasi item, Saldo item
- Badge per item: "Over Budget" (kuning — informatif saja), "Belum Bukti", "Butuh Penjelasan"
- Tombol "Simpan" per item: diblokir dengan pesan error jika penambahan entri akan menyebabkan Total Realisasi PDO > Total Transfer PDO
- Tombol "↺ History" per baris → navigasi ke halaman History Realisasi

### 9.6 History Realisasi Item

**Elemen kunci:**
- Summary cards: Kategori, Transfer, Total Realisasi, Saldo
- Tabel history transaksi: Tanggal, No Bukti, Metode, Nominal, Saldo Setelah, Diinput Oleh, Catatan, tombol Preview Bukti — **kolom Status Verifikasi dihapus (CL-09)**

### 9.7 Approval Timeline

**Elemen kunci:**
- Timeline vertikal dengan dot bernomor untuk setiap tahap
- Setiap card: nama tahap, status (selesai/menunggu/belum), tanggal, durasi
- Tahap aktif menampilkan tombol "Approve / Reject" → modal

### 9.8 Master Data — Hierarki Biaya

**Elemen kunci:**
- Tab: Hierarki Biaya / Item Biaya / Akun / User & Role
- Tabel hierarki: row kategori (toggle "▾ Sub" / "▸ Sub") → row sub-kategori (toggle "▾ Item" / "▸ Item") → row item biaya (tersembunyi default)
- Panel kanan: alur panduan tambah master (timeline 3 langkah)

### 9.9 Form Tambah Kategori, Sub-Kategori, dan Item Biaya

**Elemen kunci:**
- Form Kategori: 6 field + preview dampak
- Form Sub-Kategori: 6 field dengan dropdown kategori induk
- Form Item Biaya: tabel form 1 baris dengan semua atribut + preview pemakaian otomatis. Termasuk field **Mode Input** (dropdown: `manual` / `auto_external`) yang menentukan apakah baris item pada form PDO dapat diisi manual atau harus menggunakan tombol "Ambil Biaya"

---

## 10. Assumptions & Constraints

### Asumsi

| ID | Asumsi | Status Konfirmasi |
|----|--------|-------------------|
| A-01 | Setiap unit kebun memiliki setidaknya satu Kerani yang bertanggung jawab untuk sistem ini | ✅ Confirmed |
| A-02 | Transfer dana aktual ke kebun dilakukan di luar sistem (via perbankan), sistem hanya mencatat dan memonitor | ✅ Confirmed |
| A-03 | Koneksi internet tersedia di seluruh lokasi pengguna, meskipun mungkin tidak selalu stabil | ✅ Confirmed |
| A-04 | Setiap user hanya memiliki satu peran dalam sistem (tidak ada multi-role dalam satu akun) | ✅ Confirmed |
| A-05 | Threshold wajib bukti dan wajib penjelasan ditetapkan secara global oleh Admin dan dapat berbeda per item biaya | ✅ Confirmed |
| A-06 | Manajer Kebun dan Manajer Keuangan melakukan review secara paralel (keduanya harus approve) | ✅ Confirmed |
| A-07 | Format nomor akun (chart of accounts) yang digunakan mengikuti sistem yang sudah ada di perusahaan | ✅ Confirmed |
| A-08 | Persetujuan Direktur dilakukan setelah keduanya (Manajer Kebun dan Manajer Keuangan) selesai melakukan review | ✅ Confirmed |
| A-09 | Tidak ada integrasi otomatis dengan sistem perbankan — konfirmasi transfer dilakukan manual oleh Finance | ✅ Confirmed |
| A-10 | Versi pertama mendukung multi-unit kebun dalam satu perusahaan (PT Barumun Palma Nauli) | ✅ Confirmed |

### Constraints (Batasan)

| ID | Batasan | Status Konfirmasi |
|----|---------|-------------------|
| C-01 | Sistem dibangun sebagai aplikasi web (bukan native mobile) untuk mempercepat pengembangan | ✅ Confirmed |
| C-02 | Budget bukan kendala karena sistem dikembangkan secara internal. Namun sistem **wajib go-live pada tanggal 29 Juni 2026** — seluruh scope dan prioritas fitur harus mengacu pada deadline ini | ✅ Confirmed — Diperbarui |
| C-03 | Tim developer terbatas (2-3 orang) — fitur harus diprioritaskan dengan ketat | ✅ Confirmed |
| C-04 | Terdapat integrasi dengan aplikasi internal lain: sejumlah item biaya **tidak dapat diisi manual** oleh user — nilainya harus diambil dari aplikasi eksternal. Setiap Item Biaya di master data memiliki atribut `mode_input` dengan dua nilai: `manual` (dapat diisi langsung oleh Kerani) atau `auto_external` (nilainya diambil dari aplikasi lain). Untuk item dengan `mode_input = auto_external`, baris di tabel PDO menampilkan tombol **"Ambil Biaya"** sebagai pengganti input manual. Implementasi logika pengambilan data dari aplikasi eksternal di balik tombol ini dilakukan secara terpisah dan **tidak termasuk dalam scope AI/Coder** — developer hanya menyiapkan kerangka (placeholder fungsi, UI tombol, dan struktur data) | ✅ Confirmed — Diperbarui |
| C-05 | Aplikasi di-hosting di **cloud AWS**. Desain infrastruktur mengacu pada layanan AWS (EC2/ECS untuk compute, RDS untuk database, S3 untuk penyimpanan file bukti). Koneksi internet di beberapa lokasi kebun mungkin tidak stabil — desain frontend harus mempertimbangkan latensi tinggi | ✅ Confirmed — Diperbarui (OQ-05) |

---

## 11. Open Questions & Keputusan

Seluruh Open Questions telah dijawab per 18 Juni 2026. Tabel berikut mencatat pertanyaan, keputusan yang diambil, dan dampak terhadap requirement.

| ID | Pertanyaan | Keputusan | Dampak ke Requirement |
|----|-----------|-----------|----------------------|
| OQ-01 | Apakah Manajer Kebun dan Manajer Keuangan harus keduanya approve? | **Harus keduanya** | Mengkonfirmasi A-06 dan FR-05-004 |
| OQ-02 | Berapa ukuran maksimum file bukti transaksi? | **Maksimum 5 MB per file** | FR-06-018 diperbarui |
| OQ-03 | Apakah ada SLA untuk setiap tahap approval? | **1 hari kerja per tahap** | FR-05 ditambah FR-05-016 (SLA) |
| OQ-04 | Channel notifikasi? | **In-system notification + WhatsApp.** Sistem memiliki WhatsApp gateway sendiri — siapkan modul konfigurasi gateway (URL endpoint, API key, template pesan) | FR-08 ditambah FR-08-008 s/d FR-08-010 |
| OQ-05 | On-premise atau cloud? | **Cloud — AWS** | Constraint C-05 diperbarui; dokumen arsitektur mengacu AWS |
| OQ-06 | Ada SSO? | **Belum ada** — autentikasi mandiri (username + password) | Tidak ada perubahan requirement; SSO dicoret dari backlog |
| OQ-07 | Format kode unit kebun? | **KP** (Kota Pinang) · **BN** (Binanga) · **JM** (Janji Matogu) · **SS** (Sosa) | FR-03-016 diperbarui dengan daftar kode resmi |
| OQ-08 | Bolehkah item biaya sama muncul >1x dalam satu PDO? | **Boleh** (contoh: BBM Solar Divisi I dan Divisi II) | FR-03 ditambah FR-03-018 |
| OQ-09 | Field Sumber Dana ditetapkan di mana? | **Per entri realisasi** (bukan di master item biaya) | FR-06-004 diperbarui; field Sumber Dana wajib diisi tiap entri |
| OQ-10 | Bolehkah realisasi yang sudah disubmit dikoreksi? | **Boleh.** Kerani mengajukan koreksi, Asisten Kebun menyetujui | FR-06 ditambah FR-06-019 s/d FR-06-021 |
| OQ-11 | Berapa periode history yang disimpan? | **12 bulan terakhir (1 tahun anggaran)** | FR-07 ditambah FR-07-010 |
| OQ-12 | Perlu reminder otomatis jika PDO belum disubmit? | **Perlu.** Deadline submit: **tanggal 1 setiap bulan**. Notifikasi via WhatsApp dikirim ke: Kerani, Asisten, Manajer Kebun, Manajer Keuangan, dan Direktur Keuangan | FR-08 ditambah FR-08-011 s/d FR-08-012 |

---

## 11A. Requirement Tambahan dari Jawaban Open Questions

Jawaban OQ di atas menghasilkan requirement baru berikut yang ditambahkan ke modul terkait:

### Tambahan FR-03: Pembuatan PDO Bulanan

| ID | Requirement | Prioritas |
|----|-------------|-----------|
| FR-03-016 | Nomor PDO dibuat otomatis oleh sistem dengan format: `PDO-[YYYY]-[MM]-[KODE_UNIT]-[NOMOR_URUT]`. Kode unit resmi: **KP** (Kebun Kota Pinang), **BN** (Binanga), **JM** (Janji Matogu), **SS** (Sosa). Contoh: `PDO-2026-02-KP-001` | Must Have |
| FR-03-018 | Satu item biaya yang sama (contoh: BBM Solar) boleh muncul lebih dari satu kali dalam satu PDO, selama kolom Keterangan dibedakan (contoh: "BBM Solar Divisi I" dan "BBM Solar Divisi II"). Sistem tidak memblokir duplikasi item, namun menampilkan peringatan visual agar Kerani tidak salah input | Must Have |

### Tambahan FR-05: Approval Workflow

| ID | Requirement | Prioritas |
|----|-------------|-----------|
| FR-05-016 | Setiap tahap approval memiliki **SLA 1 hari kerja**. Jika melewati SLA, sistem mengirimkan notifikasi pengingat via WhatsApp kepada approver yang bersangkutan | Must Have |

### Tambahan FR-06: Pencatatan Realisasi Dana

| ID | Requirement | Prioritas |
|----|-------------|-----------|
| FR-06-004 | *(Diperbarui)* Setiap entri realisasi memiliki field wajib: Nominal, Tanggal Transaksi, Metode Pembayaran (Tunai / Transfer / Kas Kecil), Nomor Bukti Transaksi, **Sumber Dana** (Kas Kebun / Rekening Kebun / Rekening Utama Perusahaan), dan Upload Bukti (file). Field Sumber Dana ditetapkan per entri realisasi, bukan di level master item biaya | Must Have |
| FR-06-018 | *(Diperbarui)* Format file bukti yang diterima: PDF, JPG, PNG. **Ukuran maksimum 5 MB per file** | Must Have |
| FR-06-019 | *(Diperbarui — CL-09)* Tidak ada proses submit-approval untuk realisasi. Verifikasi dilakukan oleh Finance secara mandiri dengan melihat bukti yang diunggah di sistem. Komunikasi terkait temuan verifikasi dilakukan di luar sistem. Kerani dapat mengedit entri realisasi selama PDO belum ditutup, dengan audit log otomatis | Must Have |

### Tambahan FR-07: Rekapitulasi & Laporan

| ID | Requirement | Prioritas |
|----|-------------|-----------|
| FR-07-010 | Sistem menampilkan dan menyimpan data PDO untuk **12 bulan terakhir (1 tahun anggaran)**. Data di luar periode ini diarsipkan dan tidak tampil di filter default, namun tetap dapat diakses melalui filter periode lanjutan | Should Have |

### Tambahan FR-08: Notifikasi & Audit Trail

| ID | Requirement | Prioritas |
|----|-------------|-----------|
| FR-08-008 | Sistem mendukung pengiriman notifikasi melalui dua channel: **in-system notification** (toast dan badge di UI) dan **WhatsApp** melalui WhatsApp gateway milik perusahaan | Must Have |
| FR-08-009 | Admin dapat mengkonfigurasi WhatsApp gateway dari halaman Pengaturan, dengan field: URL Endpoint Gateway, API Key, dan Template Pesan (per jenis notifikasi). Konfigurasi ini harus dapat diubah tanpa perlu deployment ulang | Must Have |
| FR-08-010 | Sistem mendukung template pesan WhatsApp yang berbeda untuk setiap jenis notifikasi (contoh: template untuk "PDO menunggu approval", "PDO ditolak", "reminder submit PDO"). Setiap template menggunakan variabel dinamis seperti `{{nama_kerani}}`, `{{nomor_pdo}}`, `{{periode}}`, `{{alasan_reject}}` | Must Have |
| FR-08-011 | Sistem mengirimkan **notifikasi reminder otomatis via WhatsApp** kepada Kerani, Asisten Kebun, Manajer Kebun, Manajer Keuangan, dan Direktur Keuangan apabila PDO Bulanan untuk bulan berjalan belum disubmit pada **tanggal 1 setiap bulan** | Must Have |
| FR-08-012 | Admin dapat mengkonfigurasi tanggal dan jam pengiriman reminder bulanan dari halaman Pengaturan | Should Have |

---

## 12. Revision History

| Versi | Tanggal | Perubahan | Penulis |
|-------|---------|-----------|---------|
| 0.1 | 18 Juni 2026 | Draft awal — semua bagian | Tim Product |
| 1.1 | 18 Juni 2026 | Konfirmasi seluruh Asumsi (A-01 s/d A-10). Revisi C-02: budget bukan kendala, deadline go-live ditetapkan 29 Juni 2026. Revisi C-04: integrasi aplikasi eksternal — field `mode_input`, tombol "Ambil Biaya", placeholder `fetchExternalCost()`. Pembaruan FR-02-010, FR-03-006, Wireframe Bab 9.3 dan 9.9 | Tim Product |
| 1.7 | 18 Juni 2026 | Perubahan logika validasi realisasi: constraint "tidak boleh over budget" bergeser dari level per item ke **level kumulatif PDO**. FR-06-009 (baru): sistem memblokir entri realisasi baru jika Total Realisasi PDO akan melebihi Total Transfer PDO. FR-06-010 (baru): over budget per item diizinkan selama kumulatif PDO masih dalam batas — Kerani bebas menggunakan saldo transfer item lain. FR-06-011 (baru): badge "Over Budget" per item bersifat informatif, tidak memblokir. FR-06-012 (baru): indikator kumulatif PDO sticky di atas tabel input realisasi (Total Transfer PDO, Total Realisasi PDO, Sisa Saldo PDO, progress bar real-time). FR-06-021 diperbarui: edit entri realisasi juga divalidasi terhadap batas kumulatif PDO. FR-06-006 dipertegas: threshold penjelasan selisih tetap per item, tidak berubah. Wireframe 9.5 diperbarui sesuai logika baru | Tim Product | dari satu nilai statis per item menjadi **multi-entri transfer per item dengan riwayat**. FR-03A ditulis ulang sepenuhnya (11 requirement baru): transfer dicatat per entri oleh Manajer Keuangan/Staff Keuangan, bebas kapan saja setelah PDO Final, riwayat tersimpan otomatis, tidak bisa dihapus hanya dikoreksi dengan audit log, peringatan visual jika Total Transfer ≠ Pengajuan (tidak diblokir), transfer baru tidak bisa dicatat setelah PDO Closed. FR-06-006/008/009 diperbarui — semua kalkulasi saldo dan over budget mengacu Total Transfer kumulatif. FR-07-002/004 diperbarui — kolom Transfer di laporan adalah kumulatif. FR-01-001 diperbarui — KPI Total Transfer adalah kumulatif. Wireframe 9.3A ditulis ulang (form catat transfer per baris + tombol Riwayat). Wireframe 9.3B ditambahkan (halaman Riwayat Transfer per item) | Tim Product | **PD-01**: ditambahkan FR-03B (Penutupan PDO) — Closed otomatis pada hari terakhir bulan periode, atau manual lebih cepat oleh Manajer Keuangan dengan tanggal dan catatan. **PD-02**: FR-02-022 ditambahkan — nomor WhatsApp adalah field wajib di profil user. **PD-03**: FR-06-003/004 diperbarui — Staff Purchasing dapat mengupdate item manapun, pembatasan hanya pada dropdown Sumber Dana yang hanya menampilkan "Rekening Utama Perusahaan". **PK-01**: FR-06-019 dipertegas — batas edit realisasi adalah saat PDO Closed, tidak ada deadline lain. **PK-02**: dikonfirmasi, FR-03A-006 tetap. **PK-03**: koreksi signifikan — Manajer Kebun berkedudukan di Head Office dan bertanggung jawab semua kebun (lintas unit), Asisten Kebun adalah jabatan tertinggi di kebun dan terikat satu unit. FR-02-020 diperbarui. Patch editorial (ED-01 s/d ED-05): FR-02-010b/010d, US-01, US-03, US-14, US-15, US-16 dibersihkan dari referensi "Remisi I/II" dan "Status Verifikasi" | Tim Product | **CL-09**: alur verifikasi realisasi disederhanakan — tidak ada proses submit-approval, Finance verifikasi mandiri dengan melihat bukti di sistem, komunikasi di luar sistem. FR-06-012/013 diperbarui, FR-06-016 kolom "Status Verifikasi" dihapus, FR-06-019 diperbarui (koreksi realisasi oleh Kerani dengan audit log, tanpa persetujuan Asisten), FR-06-020/021 dihapus. Wireframe 9.6 diperbarui. **CL-10**: dikonfirmasi (patch FR-06-018 sudah dilakukan di v1.3). **CL-11**: dikonfirmasi (patch FR-03-016 sudah dilakukan di v1.3). **CL-12**: PDO Tambahan yang sudah Final ter-merge ke PDO Bulanan dan tidak muncul sebagai entri terpisah di Daftar PDO — item-nya ditampilkan di bagian bawah tabel Detail PDO Bulanan dengan baris header pemisah bertanda nomor PDO Tambahan. FR-04-006/007/010 diperbarui. Wireframe 9.2 (tambah kolom Tipe, hapus kolom Remisi) dan 9.4 (hapus KPI Remisi I/II, tambah tampilan header PDO Tambahan) diperbarui | Tim Product | **CL-01**: ditambahkan role Admin (berwenang kelola user & master data) dan Staff Keuangan (input nilai transfer). **CL-02**: setiap user terikat satu unit kebun; Manajer Keuangan/Direktur/Staff Keuangan/Staff Purchasing/Admin lintas unit. **CL-03**: ditambahkan FR-03A (Input Nilai Transfer — default = nilai pengajuan, dapat diubah oleh Manajer Keuangan/Staff Keuangan). **CL-04**: Scope 4.1 & 4.2 diperbarui — WhatsApp masuk in-scope, SSO masuk out-of-scope. **CL-05**: dikonfirmasi Manajer Keuangan & Direktur akses lintas unit, tercermin di FR-02-020. **CL-06**: FR-05-017 ditambahkan — jika salah satu Manajer reject, approval Manajer lain hangus dan keduanya harus approve ulang setelah revisi. **CL-07**: dikonfirmasi akses konsolidasi berlaku untuk semua data PDO (bukan hanya dashboard). **CL-08**: kolom Remisi I dan II dihapus dari seluruh sistem — diganti satu kolom Jumlah. Berdampak pada FR-03-003/004/006a/006b/008/009/012/014, FR-07-002, Wireframe 9.3. Ditambahkan Wireframe 9.3A (Form Input Transfer). Patch CL-10: FR-06-018 diperbarui (5 MB). Patch CL-11: FR-03-016 diperbarui (kode unit KP/BN/JM/SS). FR-08-004 dinaikan dari Should Have ke Must Have. FR-08-005 diperbarui mencakup WhatsApp | Tim Product |
| 1.0 | — | Finalisasi setelah review stakeholder lengkap | — |
