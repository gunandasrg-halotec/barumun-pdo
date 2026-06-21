# Business Rules Document (BRD)
# Sistem Dana Operasional Kebun (PDO)
# PT Barumun Palma Nauli

---

| Atribut | Detail |
|---|---|
| Versi | 1.1 |
| Status | Draft — KD-01 s/d KD-03 Dikonfirmasi, ED-01 s/d ED-04 Dipatch |
| Tanggal | 18 Juni 2026 |
| Referensi PRD | PRD-Dana-Operasional-Kebun-v1.7 |
| Target Go-Live | **29 Juni 2026** |

---

## Daftar Isi

1. Glosarium Istilah
2. Matriks Hak Akses per Role
3. Modul 1: Manajemen PDO Bulanan (BR-PDO)
4. Modul 2: Pencatatan Transfer Dana (BR-TRF)
5. Modul 3: Penutupan PDO (BR-CLOSE)
6. Modul 4: Manajemen PDO Tambahan (BR-PDOT)
7. Modul 5: Approval Workflow (BR-APPR)
8. Modul 6: Pencatatan Realisasi Dana (BR-REAL)
9. Modul 7: Master Data (BR-MASTER)
10. Modul 8: Validasi & Integritas Data (BR-VAL)
11. Modul 9: Laporan & Rekapitulasi (BR-LAP)
12. Modul 10: Notifikasi & Audit Trail (BR-NOTIF)

---

## 1. Glosarium Istilah

| Istilah | Definisi |
|---------|----------|
| **PDO Bulanan** | Pengajuan Dana Operasional yang dibuat satu kali per bulan per unit kebun, melalui template item biaya rutin |
| **PDO Tambahan** | Pengajuan Dana Operasional di luar PDO Bulanan, dibuat setelah PDO Bulanan berstatus Final |
| **Jumlah Pengajuan** | Nominal biaya yang diajukan oleh Kerani per item biaya dalam PDO |
| **Entri Transfer** | Satu catatan transfer dana yang dilakukan ke kebun. Satu item bisa menerima lebih dari satu entri transfer |
| **Total Transfer (per item)** | Jumlah kumulatif semua entri transfer yang sudah dicatat untuk satu item biaya |
| **Total Transfer (PDO)** | Jumlah kumulatif Total Transfer dari seluruh item biaya dalam satu PDO |
| **Entri Realisasi** | Satu catatan penggunaan dana aktual untuk satu item biaya |
| **Total Realisasi (per item)** | Jumlah kumulatif semua entri realisasi untuk satu item biaya |
| **Total Realisasi (PDO)** | Jumlah kumulatif Total Realisasi dari seluruh item biaya dalam satu PDO |
| **Saldo (per item)** | Total Transfer item − Total Realisasi item |
| **Saldo (PDO)** | Total Transfer PDO − Total Realisasi PDO |
| **Over Budget (per item)** | Kondisi ketika Total Realisasi item > Total Transfer item |
| **Item Rutin** | Item biaya dengan atribut `rutin = Ya` di master data — masuk template PDO Bulanan secara otomatis |
| **Mode Input Manual** | Item biaya yang nilainya diisi langsung oleh Kerani |
| **Mode Input Auto External** | Item biaya yang nilainya diambil dari aplikasi eksternal via tombol "Ambil Biaya" |
| **Merger** | Proses penggabungan otomatis item-item PDO Tambahan yang sudah Final ke dalam PDO Bulanan induknya |
| **Closed** | Status akhir PDO setelah periode berakhir atau ditutup manual — tidak ada lagi transaksi yang bisa dilakukan |
| **SLA Approval** | Service Level Agreement: batas waktu 1 hari kerja untuk setiap tahap approval |

---

## 2. Matriks Hak Akses per Role

### 2.1 Binding User ke Unit Kebun

| Role | Terikat Unit Kebun | Akses Data |
|------|--------------------|------------|
| Kerani | Ya — tepat 1 unit kebun | Hanya unit sendiri |
| Asisten Kebun | Ya — tepat 1 unit kebun | Hanya unit sendiri |
| Manajer Kebun | Unit HO (lintas unit) | Semua unit |
| Manajer Keuangan | Unit HO (lintas unit) | Semua unit |
| Staff Keuangan | Unit HO (lintas unit) | Semua unit |
| Direktur Keuangan | Unit HO (lintas unit) | Semua unit |
| Staff Purchasing | Unit HO (lintas unit) | Semua unit |
| Admin | Unit HO (lintas unit) | Semua unit |

> **Unit HO (Head Office):** Unit khusus yang merepresentasikan kantor pusat. User yang di-assign ke unit HO diperlakukan sebagai pengguna lintas unit dan dapat mengakses data seluruh unit kebun. Kerani dan Asisten Kebun tidak dapat di-assign ke unit HO.

### 2.2 Matriks Akses Fungsional

| Fitur | Admin | Kerani | Asisten | Mgr Kebun | Mgr Keuangan | Staff Keuangan | Direktur | Staff Purchasing |
|-------|:-----:|:------:|:-------:|:---------:|:------------:|:--------------:|:--------:|:---------------:|
| Buat PDO Bulanan | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Edit PDO (Draft) | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Lihat PDO | ✅ | ✅ (unit sendiri) | ✅ (unit sendiri) | ✅ (semua) | ✅ (semua) | ✅ (semua) | ✅ (semua) | ✅ (semua) |
| Approve PDO (Asisten) | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Approve PDO (Manajer) | ❌ | ❌ | ❌ | ✅ | ✅ | ❌ | ❌ | ❌ |
| Approve PDO (Direktur) | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ |
| Reject PDO | ❌ | ❌ | ✅ | ✅ | ✅ | ❌ | ✅ | ❌ |
| Buat PDO Tambahan | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Catat Transfer | ❌ | ❌ | ❌ | ❌ | ✅ | ✅ | ❌ | ❌ |
| Koreksi Transfer | ❌ | ❌ | ❌ | ❌ | ✅ | ✅ | ❌ | ❌ |
| Tutup PDO (manual) | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ |
| Catat Realisasi | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅* |
| Edit Realisasi | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Lihat Realisasi | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Kelola Master Data | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Kelola User | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Konfigurasi Sistem | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Export Laporan | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ |

*Staff Purchasing: hanya bisa mencatat realisasi dengan Sumber Dana = "Rekening Utama Perusahaan"

### 2.3 Matriks Sumber Dana yang Tersedia saat Input Realisasi

| Role | Kas Kebun | Rekening Kebun | Rekening Utama Perusahaan |
|------|:---------:|:--------------:|:------------------------:|
| Kerani | ✅ | ✅ | ✅ |
| Staff Purchasing | ❌ | ❌ | ✅ (satu-satunya pilihan) |

---

## 3. Modul 1: Manajemen PDO Bulanan (BR-PDO)

### Ringkasan Modul

| ID | Judul | Prioritas |
|----|-------|-----------|
| BR-PDO-001 | Satu PDO Bulanan per Unit per Periode | Kritikal |
| BR-PDO-002 | Template Otomatis Item Rutin | Kritikal |
| BR-PDO-003 | Penambahan Item Non-Rutin | Tinggi |
| BR-PDO-004 | Mode Input Item Biaya | Tinggi |
| BR-PDO-005 | Validasi Baris Sebelum Submit | Kritikal |
| BR-PDO-006 | Format Nomor PDO | Tinggi |
| BR-PDO-007 | Lifecycle Status PDO Bulanan | Kritikal |
| BR-PDO-008 | Kalkulasi Grand Total | Kritikal |
| BR-PDO-009 | Duplikasi Item dalam Satu PDO | Sedang |

---

### BR-PDO-001: Satu PDO Bulanan per Unit per Periode

**Deskripsi:** Sistem hanya mengizinkan tepat satu PDO Bulanan aktif per kombinasi Unit Kebun dan Periode (bulan + tahun). Duplikasi tidak diizinkan dalam kondisi apapun.

**Kondisi / Trigger:** Kerani mencoba membuat PDO Bulanan baru.

**Aksi / Konsekuensi:**
- Sistem memeriksa apakah sudah ada PDO Bulanan (dengan status apapun kecuali tidak ada) untuk kombinasi unit kebun + periode yang sama.
- Jika sudah ada: sistem menampilkan pesan error yang menyebutkan nomor PDO yang sudah ada dan mengarahkan Kerani untuk membuat PDO Tambahan jika membutuhkan dana tambahan.
- Jika belum ada: sistem melanjutkan proses pembuatan PDO.

**Pengecualian:** Tidak ada. Aturan ini berlaku mutlak terlepas dari status PDO yang sudah ada (Draft, Submitted, Final, maupun Closed).

**Contoh konkret:**
- ✅ Kerani unit KP membuat PDO untuk Juni 2026 → Diizinkan (belum ada PDO Juni 2026 untuk unit KP)
- ❌ Kerani unit KP mencoba membuat PDO kedua untuk Juni 2026 → Ditolak, sistem menampilkan: "PDO Bulanan untuk unit KP periode Juni 2026 sudah ada: PDO-2026-06-KP-001"
- ✅ Kerani unit BN membuat PDO untuk Juni 2026 → Diizinkan (unit berbeda)

---

### BR-PDO-002: Template Otomatis Item Rutin

**Deskripsi:** Saat PDO Bulanan baru dibuat, sistem secara otomatis mengisi tabel rincian dengan semua item biaya yang memiliki atribut `rutin = Ya` dan status `Aktif`.

**Kondisi / Trigger:** Kerani membuka form pembuatan PDO Bulanan baru dan form berhasil dibuka.

**Aksi / Konsekuensi:**
- Sistem mengambil semua item biaya dengan `rutin = Ya` dan `status = Aktif` dari master data.
- Item diurutkan berdasarkan: urutan Kategori (A, B, C...) → urutan Sub-Kategori → urutan Item Biaya.
- Setiap baris template terisi otomatis: Item Biaya, No Akun, Satuan, dan Tarif dari master data.
- Kolom Jumlah, Keterangan, dan Qty dibiarkan kosong — wajib diisi oleh Kerani.
- Jika item memiliki `mode_input = auto_external`, kolom Jumlah digantikan tombol "Ambil Biaya".
- Jika tidak ada item rutin aktif di master, tabel dimulai kosong dan Kerani menambah item secara manual.

**Pengecualian:** Item dengan `rutin = Ya` namun `status = Nonaktif` tidak dimasukkan ke template.

**Contoh konkret:**
- Master data memiliki 12 item dengan `rutin = Ya` dan `status = Aktif` → Template PDO baru langsung berisi 12 baris.
- Admin mengubah item "Gaji Sopir" menjadi nonaktif kemarin → Item tersebut tidak muncul di PDO yang dibuat hari ini, namun tetap terlihat di PDO yang dibuat sebelum perubahan.

---

### BR-PDO-003: Penambahan Item Non-Rutin

**Deskripsi:** Kerani dapat menambahkan item biaya di luar template ke dalam PDO Bulanan yang masih berstatus Draft.

**Kondisi / Trigger:** Kerani mengklik tombol "+ Tambah Baris" pada form PDO Draft.

**Aksi / Konsekuensi:**
- Sistem menambahkan baris kosong di bagian bawah tabel.
- Kerani memilih Kategori → Sub-Kategori → Item Biaya secara berurutan (cascading dropdown).
- Item yang dapat dipilih hanya item yang `status = Aktif` di master data.
- Saat Item Biaya dipilih: No Akun, Satuan, dan Tarif terisi otomatis dari master data (dapat diubah oleh Kerani).
- Baris dapat dihapus selama PDO masih berstatus Draft.

**Pengecualian:** Item dengan `status = Nonaktif` tidak muncul di dropdown. Item tidak dapat ditambahkan secara custom di luar master data — harus dipilih dari daftar master.

**Contoh konkret:**
- Bulan ini ada kebutuhan sewa alat berat (tidak rutin) → Kerani menambah baris baru, memilih "H - Biaya Umum" → "Sewa Peralatan" → "Sewa Excavator".

---

### BR-PDO-004: Mode Input Item Biaya

**Deskripsi:** Setiap item biaya memiliki atribut `mode_input` yang menentukan bagaimana nilai diisi pada form PDO.

**Kondisi / Trigger:** Item biaya dipilih pada baris PDO (baik dari template maupun ditambah manual).

**Aksi / Konsekuensi:**

| mode_input | Perilaku Kolom Jumlah | Tindakan User |
|------------|----------------------|---------------|
| `manual` | Input teks aktif | Kerani mengisi nominal langsung |
| `auto_external` | Read-only, tampil tombol "Ambil Biaya" | Kerani menekan tombol "Ambil Biaya" |

- Untuk `auto_external`: sistem memanggil fungsi placeholder `fetchExternalCost(item_id, periode)`. Setelah nilai kembali, kolom Jumlah terisi otomatis dan tetap read-only.
- Baris dengan `mode_input = auto_external` yang belum diklik "Ambil Biaya" dianggap invalid dan memblokir submit PDO.

**Pesan error:** "Klik 'Ambil Biaya' untuk mengisi nilai item [nama item] sebelum submit."

---

### BR-PDO-005: Validasi Baris Sebelum Submit

**Deskripsi:** Setiap baris dalam PDO harus memenuhi kondisi minimum sebelum PDO dapat disubmit ke Asisten.

**Kondisi / Trigger:** Kerani menekan tombol "Submit Pengajuan".

**Aksi / Konsekuensi:** Sistem memeriksa setiap baris. Baris dianggap invalid jika:

| Kondisi Invalid | Visual | Blokir Submit? |
|----------------|--------|----------------|
| Field Keterangan kosong | Highlight merah pada baris | Ya |
| Jumlah = 0 | Highlight merah pada baris | Ya |
| Item `auto_external` belum diklik "Ambil Biaya" | Highlight merah pada baris | Ya |
| Nilai negatif pada kolom Jumlah, Qty, atau Tarif | Pesan error inline | Ya |

Jika ada baris invalid: tombol Submit dinonaktifkan dan sistem menampilkan daftar baris yang bermasalah. Jika semua valid: sistem menampilkan modal konfirmasi sebelum submit dieksekusi.

**Pengecualian:** Baris boleh disimpan sebagai Draft meskipun ada kondisi invalid — validasi hanya berlaku saat submit.

---

### BR-PDO-006: Format Nomor PDO

**Deskripsi:** Nomor PDO dibuat secara otomatis oleh sistem dengan format yang terstandar.

**Format:**
- PDO Bulanan: `PDO-[YYYY]-[MM]-[KODE_UNIT]-[NOMOR_URUT]`
- PDO Tambahan: `PDOT-[YYYY]-[MM]-[KODE_UNIT]-[NOMOR_URUT]`

**Kode Unit Resmi:**

| Kode | Unit Kebun |
|------|-----------|
| KP | Kebun Kota Pinang |
| BN | Binanga |
| JM | Janji Matogu |
| SS | Sosa |

**Contoh:**
- `PDO-2026-06-KP-001` — PDO Bulanan pertama Juni 2026, unit Kota Pinang
- `PDOT-2026-06-KP-001` — PDO Tambahan pertama Juni 2026, unit Kota Pinang
- `PDO-2026-06-KP-002` — jika PDO pertama di-reject dan Kerani membuat ulang, nomor urut tetap increment

**Aturan nomor urut:** Nomor urut selalu increment, tidak pernah di-recycle meskipun PDO dihapus atau dibatalkan.

---

### BR-PDO-007: Lifecycle Status PDO Bulanan

**State Diagram:**

```
[Draft] ──submit──► [Submitted] ──approve Asisten──► [Reviewed Asisten]
   ▲                     │                                    │
   │                  reject                              approve Mgr Kebun
   │                     │                               approve Mgr Keuangan
   │◄────────────────────┘                                    │ (keduanya)
   │                                                          ▼
   │◄──────────────────────────────────────── [In Review Manajer]
   │                 reject                                   │
   │                                                    approve Direktur
   │◄──────────────────────────────────────── [In Review Direktur]
   │                 reject                                   │
   │                                                          ▼
   │                                                       [Final]
   │                                                          │
   │                                            otomatis (akhir bulan)
   │                                            atau manual (Mgr Keuangan)
   │                                                          ▼
   │                                                       [Closed]
```

**Tabel Transisi Status:**

| Dari | Ke | Trigger | Aktor |
|------|----|---------|-------|
| — | Draft | PDO baru dibuat | Kerani |
| Draft | Submitted | Submit berhasil (semua baris valid) | Kerani |
| Submitted | Reviewed (Asisten) | Approve | Asisten Kebun |
| Submitted | Draft | Reject + alasan | Asisten Kebun |
| Reviewed (Asisten) | In Review (Manajer) | Kedua Manajer menerima notifikasi | Sistem |
| In Review (Manajer) | In Review (Direktur) | Approve oleh Manajer Kebun DAN Manajer Keuangan | Manajer Kebun + Manajer Keuangan |
| In Review (Manajer) | Draft | Reject + alasan (oleh salah satu atau keduanya) | Manajer Kebun ATAU Manajer Keuangan |
| In Review (Direktur) | Final | Approve | Direktur Keuangan |
| In Review (Direktur) | Draft | Reject + alasan | Direktur Keuangan |
| Final | Closed | Otomatis hari terakhir bulan, atau manual Mgr Keuangan | Sistem / Manajer Keuangan |

**Aturan tambahan:**
- PDO dengan status Draft: dapat diedit oleh Kerani pembuatnya.
- PDO dengan status apapun selain Draft: tidak dapat diedit.
- PDO dengan status Final atau Closed: tidak dapat diedit oleh siapapun.

---

### BR-PDO-008: Kalkulasi Grand Total

**Deskripsi:** Grand Total PDO dihitung otomatis dari jumlah nilai Jumlah seluruh baris.

**Formula:**
```
Grand Total PDO = Σ(Jumlah per baris)
```

**Aturan:**
- Kalkulasi dilakukan real-time di frontend setiap kali nilai Jumlah pada baris manapun berubah.
- Backend memvalidasi ulang kalkulasi saat submit untuk memastikan konsistensi.
- Tidak ada pembulatan — nilai disimpan sebagai bilangan bulat (satuan Rupiah, tanpa desimal).
- Nilai negatif tidak diizinkan pada field Jumlah, Qty, maupun Tarif.

---

### BR-PDO-009: Duplikasi Item dalam Satu PDO

**Deskripsi:** Item biaya yang sama (kode item sama) diizinkan muncul lebih dari satu kali dalam satu PDO, dengan syarat kolom Keterangan berbeda.

**Kondisi / Trigger:** Kerani menambah item yang sudah ada di PDO.

**Aksi / Konsekuensi:**
- Sistem mengizinkan penambahan item duplikat.
- Sistem menampilkan peringatan visual (bukan blokir): "Item ini sudah ada di baris [nomor baris]. Pastikan Keterangan berbeda agar tidak terjadi pencatatan ganda."
- Jika Keterangan sama persis, peringatan diperkuat namun tetap tidak memblokir.

**Contoh konkret:**
- "BBM Solar" untuk Divisi I (Keterangan: "Divisi I") dan "BBM Solar" untuk Divisi II (Keterangan: "Divisi II") → Keduanya diizinkan dalam satu PDO.

---

## 4. Modul 2: Pencatatan Transfer Dana (BR-TRF)

### Ringkasan Modul

| ID | Judul | Prioritas |
|----|-------|-----------|
| BR-TRF-001 | Siapa yang Mencatat Transfer | Kritikal |
| BR-TRF-002 | Multi-Entri Transfer per Item | Kritikal |
| BR-TRF-003 | Komponen Wajib Entri Transfer | Kritikal |
| BR-TRF-004 | Kalkulasi Total Transfer | Kritikal |
| BR-TRF-005 | Peringatan Selisih Transfer vs Pengajuan | Tinggi |
| BR-TRF-006 | Transfer sebagai Dasar Kalkulasi Saldo | Kritikal |
| BR-TRF-007 | Transfer Sebelum Ada Entri | Tinggi |
| BR-TRF-008 | Koreksi Entri Transfer | Tinggi |
| BR-TRF-009 | Batas Waktu Pencatatan Transfer | Tinggi |

---

### BR-TRF-001: Siapa yang Mencatat Transfer

**Deskripsi:** Pencatatan entri transfer dana ke dalam sistem hanya dapat dilakukan oleh role tertentu.

**Kondisi / Trigger:** User mengakses form pencatatan transfer.

**Aksi / Konsekuensi:**
- Role yang diizinkan: **Manajer Keuangan** dan **Staff Keuangan**.
- Role lain tidak dapat mengakses form pencatatan transfer.
- Transfer dapat dicatat kapan saja setelah PDO berstatus **Final**, tanpa ketergantungan urutan dengan pencatatan realisasi.

**Pengecualian:** Tidak ada. Aturan ini berlaku mutlak.

---

### BR-TRF-002: Multi-Entri Transfer per Item

**Deskripsi:** Satu item biaya dapat menerima lebih dari satu entri transfer yang dicatat secara terpisah.

**Kondisi / Trigger:** Manajer Keuangan atau Staff Keuangan menambah entri transfer untuk item yang sudah memiliki entri transfer sebelumnya.

**Aksi / Konsekuensi:**
- Sistem menerima entri baru tanpa memblokir atau memperingatkan bahwa sudah ada entri sebelumnya.
- Setiap entri baru ditambahkan ke riwayat transfer item tersebut.
- Total Transfer item diperbarui otomatis (kumulatif).

**Contoh konkret:**
- Item "Polybag" pengajuan Rp 5.000.000:
  - Entri transfer 1: Rp 3.000.000 (15 Juni 2026) → Total Transfer = Rp 3.000.000
  - Entri transfer 2: Rp 2.000.000 (20 Juni 2026) → Total Transfer = Rp 5.000.000

---

### BR-TRF-003: Komponen Wajib Entri Transfer

**Deskripsi:** Setiap entri transfer harus memiliki komponen data yang lengkap.

**Field wajib:**

| Field | Tipe | Keterangan |
|-------|------|-----------|
| Tanggal Transfer | Date | Format: DD/MM/YYYY |
| Nominal Transfer | Integer (Rupiah) | > 0, tidak boleh negatif |
| Nomor Referensi Transfer | String | Nomor bukti bank/rekening |
| Catatan | String | Opsional |

**Validasi:** Sistem menolak penyimpanan jika salah satu field wajib kosong atau Nominal ≤ 0.

---

### BR-TRF-004: Kalkulasi Total Transfer

**Deskripsi:** Total Transfer per item dan per PDO dihitung otomatis oleh sistem.

**Formula:**
```
Total Transfer (per item) = Σ(Nominal semua entri transfer item tersebut)
Total Transfer (PDO)      = Σ(Total Transfer semua item dalam PDO)
```

**Aturan:** Kalkulasi diperbarui setiap kali entri transfer baru disimpan atau entri yang ada dikoreksi.

---

### BR-TRF-005: Peringatan Selisih Transfer vs Pengajuan

**Deskripsi:** Sistem memberi peringatan visual jika Total Transfer item berbeda dari Jumlah Pengajuan item — namun tidak memblokir.

**Kondisi / Trigger:** Total Transfer item ≠ Jumlah Pengajuan item.

**Aksi / Konsekuensi:**
- Badge "Selisih Transfer" ditampilkan pada baris item tersebut.
- Badge menampilkan nominal selisih (positif = transfer lebih besar dari pengajuan, negatif = transfer lebih kecil).
- Pencatatan transfer tetap diizinkan — tidak ada pemblokiran.

**Decision Table:**

| Total Transfer vs Pengajuan | Badge | Blokir? |
|----------------------------|-------|---------|
| Transfer = Pengajuan | Tidak ada | Tidak |
| Transfer > Pengajuan | "Selisih Transfer: +Rp X" | Tidak |
| Transfer < Pengajuan | "Selisih Transfer: -Rp X" | Tidak |

---

### BR-TRF-006: Transfer sebagai Dasar Kalkulasi Saldo

**Deskripsi:** Saldo realisasi dihitung berdasarkan Total Transfer kumulatif, bukan Jumlah Pengajuan.

**Formula:**
```
Saldo (per item) = Total Transfer (per item) − Total Realisasi (per item)
Saldo (PDO)      = Total Transfer (PDO) − Total Realisasi (PDO)
```

**Aturan:** Setiap perubahan pada Total Transfer (penambahan atau koreksi entri) langsung memperbarui Saldo yang ditampilkan di semua halaman yang relevan.

---

### BR-TRF-007: Item Tanpa Entri Transfer

**Deskripsi:** Item yang belum memiliki entri transfer dicatat dengan Total Transfer = Rp 0.

**Kondisi / Trigger:** Item dalam PDO Final belum ada entri transfer yang dicatat.

**Aksi / Konsekuensi:**
- Total Transfer item ditampilkan sebagai Rp 0.
- Saldo item = 0 − Total Realisasi item.
- Jika ada realisasi untuk item ini: Saldo menjadi negatif, badge "Over Budget" muncul.
- Kerani tetap dapat mencatat realisasi untuk item ini, namun tunduk pada batas kumulatif PDO (BR-REAL-005).

---

### BR-TRF-008: Koreksi Entri Transfer

**Deskripsi:** Entri transfer yang sudah disimpan tidak dapat dihapus, namun dapat dikoreksi.

**Kondisi / Trigger:** Manajer Keuangan atau Staff Keuangan memilih "Koreksi" pada entri transfer tertentu.

**Aksi / Konsekuensi:**
- Form koreksi menampilkan nilai saat ini di semua field.
- User mengubah nilai yang perlu dikoreksi dan menyimpan.
- Sistem mencatat audit log: nilai lama, nilai baru, siapa yang mengubah, dan timestamp.
- Total Transfer item diperbarui otomatis berdasarkan nilai yang sudah dikoreksi.

**Pengecualian:** Koreksi tidak dapat dilakukan setelah PDO berstatus **Closed**.

---

### BR-TRF-009: Batas Waktu Pencatatan Transfer

**Deskripsi:** Pencatatan entri transfer baru dibatasi oleh status PDO.

**Aturan:**
- Transfer **dapat** dicatat: status PDO = Final
- Transfer **tidak dapat** dicatat: status PDO = Draft, Submitted, In Review, atau Closed

**Contoh konkret:**
- PDO sudah Closed pada 30 Juni 2026 pukul 23:59 → Finance tidak dapat lagi menambah entri transfer mulai 1 Juli 2026.

---

## 5. Modul 3: Penutupan PDO (BR-CLOSE)

### Ringkasan Modul

| ID | Judul | Prioritas |
|----|-------|-----------|
| BR-CLOSE-001 | Penutupan Otomatis PDO | Kritikal |
| BR-CLOSE-002 | Penutupan Manual oleh Manajer Keuangan | Tinggi |
| BR-CLOSE-003 | Efek Status Closed | Kritikal |
| BR-CLOSE-004 | Audit Log Penutupan | Tinggi |

---

### BR-CLOSE-001: Penutupan Otomatis PDO

**Deskripsi:** PDO dengan status Final ditutup secara otomatis oleh sistem pada hari terakhir bulan periode PDO.

**Kondisi / Trigger:** Hari terakhir bulan periode PDO pukul 23:59 WIB.

**Aksi / Konsekuensi:**
- Sistem mengubah status semua PDO Final yang periode-nya sesuai menjadi **Closed**.
- Proses dijalankan oleh scheduled job otomatis.
- Audit log dicatat: `ditutup_oleh = SYSTEM`, `timestamp = tanggal penutupan`.

**Contoh konkret:**
- PDO-2026-06-KP-001 dengan periode Juni 2026 → otomatis Closed pada 30 Juni 2026 pukul 23:59 WIB.
- PDO-2026-02-BN-001 dengan periode Februari 2026 → otomatis Closed pada 28 Februari 2026 pukul 23:59 WIB.

---

### BR-CLOSE-002: Penutupan Manual oleh Manajer Keuangan

**Deskripsi:** Manajer Keuangan dapat menutup PDO lebih awal dari jadwal otomatis.

**Kondisi / Trigger:** Manajer Keuangan memilih "Tutup PDO" pada PDO berstatus Final.

**Aksi / Konsekuensi:**
- Sistem menampilkan form penutupan dengan field: Tanggal Penutupan dan Catatan (opsional).
- Validasi tanggal:
  - Tidak boleh lebih awal dari hari ini.
  - Tidak boleh lebih lambat dari tanggal penutupan otomatis (hari terakhir bulan periode).
- Modal konfirmasi wajib ditampilkan sebelum eksekusi.
- Setelah dikonfirmasi, status PDO berubah menjadi **Closed**.

**Pengecualian:** Hanya Manajer Keuangan yang dapat menutup PDO secara manual. Staff Keuangan dan role lain tidak dapat melakukan ini.

---

### BR-CLOSE-003: Efek Status Closed

**Deskripsi:** Setelah PDO berstatus Closed, semua operasi tulis dinonaktifkan.

**Tabel efek Closed:**

| Operasi | Sebelum Closed | Setelah Closed |
|---------|----------------|----------------|
| Tambah entri realisasi baru | ✅ Diizinkan | ❌ Diblokir |
| Edit entri realisasi | ✅ Diizinkan | ❌ Diblokir |
| Tambah entri transfer baru | ✅ Diizinkan | ❌ Diblokir |
| Koreksi entri transfer | ✅ Diizinkan | ❌ Diblokir |
| Lihat data PDO | ✅ Diizinkan | ✅ Diizinkan |
| Export laporan | ✅ Diizinkan | ✅ Diizinkan |

**Pesan error saat mencoba menulis setelah Closed:** "PDO ini sudah ditutup pada [tanggal penutupan] dan tidak dapat diubah."

---

### BR-CLOSE-004: Audit Log Penutupan

**Deskripsi:** Setiap penutupan PDO — otomatis maupun manual — wajib dicatat dalam audit log.

**Data yang dicatat:**

| Field | Nilai (Otomatis) | Nilai (Manual) |
|-------|-----------------|----------------|
| Jenis Penutupan | SYSTEM | MANUAL |
| Ditutup Oleh | SYSTEM | Nama + ID User |
| Tanggal Penutupan | Hari terakhir bulan | Tanggal yang dipilih |
| Catatan | — | Catatan yang diinput |
| Timestamp | Waktu eksekusi | Waktu eksekusi |

---

## 6. Modul 4: Manajemen PDO Tambahan (BR-PDOT)

### Ringkasan Modul

| ID | Judul | Prioritas |
|----|-------|-----------|
| BR-PDOT-001 | Prasyarat Pembuatan PDO Tambahan | Kritikal |
| BR-PDOT-002 | Tidak Ada Template | Tinggi |
| BR-PDOT-003 | Jumlah PDO Tambahan per Periode | Sedang |
| BR-PDOT-004 | Merger ke PDO Bulanan | Kritikal |
| BR-PDOT-005 | Tampilan PDO Tambahan di Daftar PDO | Tinggi |

---

### BR-PDOT-001: Prasyarat Pembuatan PDO Tambahan

**Deskripsi:** PDO Tambahan hanya dapat dibuat jika PDO Bulanan untuk periode yang sama sudah berstatus Final.

**Kondisi / Trigger:** Kerani mencoba membuat PDO Tambahan.

**Aksi / Konsekuensi:**
- Sistem memeriksa apakah ada PDO Bulanan berstatus Final untuk unit kebun + periode yang sama.
- Jika ada: sistem mengizinkan pembuatan PDO Tambahan.
- Jika tidak ada atau status tidak memenuhi syarat: sistem menampilkan pesan error yang sesuai:
  - PDO Bulanan belum ada / masih Draft / In Review: "PDO Bulanan untuk periode [bulan/tahun] belum Final. PDO Tambahan hanya dapat dibuat setelah PDO Bulanan disetujui Direktur."
  - PDO Bulanan sudah Closed: "PDO Bulanan untuk periode [bulan/tahun] sudah ditutup. PDO Tambahan tidak dapat dibuat untuk periode yang sudah Closed."

**Decision Table:**

| Status PDO Bulanan | Pembuatan PDO Tambahan |
|--------------------|----------------------|
| Tidak ada | ❌ Ditolak |
| Draft | ❌ Ditolak |
| Submitted / In Review | ❌ Ditolak |
| Final | ✅ Diizinkan | — |
| Closed | ❌ Ditolak | PDO Bulanan sudah ditutup — PDO Tambahan tidak dapat dibuat |

---

### BR-PDOT-002: Tidak Ada Template

**Deskripsi:** PDO Tambahan tidak memiliki template otomatis. Semua item biaya diisi manual oleh Kerani.

**Kondisi / Trigger:** Form PDO Tambahan dibuka.

**Aksi / Konsekuensi:**
- Tabel rincian dimulai kosong.
- Kerani menambah item menggunakan cascading dropdown (Kategori → Sub-Kategori → Item).
- Aturan validasi baris sama dengan PDO Bulanan (BR-PDO-005).

---

### BR-PDOT-003: Jumlah PDO Tambahan per Periode

**Deskripsi:** Tidak ada batasan jumlah PDO Tambahan yang dapat dibuat untuk satu periode.

**Aturan:** Kebun dapat membuat PDO Tambahan sebanyak yang diperlukan selama PDO Bulanan induknya sudah Final. Setiap PDO Tambahan melalui approval chain yang sama dan mendapat nomor urut yang berbeda.

---

### BR-PDOT-004: Merger ke PDO Bulanan

**Deskripsi:** Setelah PDO Tambahan disetujui Direktur, item-itemnya otomatis digabungkan ke PDO Bulanan induknya.

**Kondisi / Trigger:** Direktur Keuangan memberikan approve pada PDO Tambahan.

**Aksi / Konsekuensi:**
1. Sistem mengidentifikasi PDO Bulanan induk (unit kebun + periode yang sama).
2. Semua item dari PDO Tambahan ditambahkan ke bagian paling bawah tabel rincian PDO Bulanan.
3. Sebelum item-item tersebut, sistem menyisipkan **baris header pemisah** berteks: `▶ PDO Tambahan: [NOMOR_PDOT] — disetujui [TANGGAL]`.
4. Status PDO Tambahan berubah menjadi **Final (Merged)**.
5. PDO Tambahan tidak lagi muncul sebagai entri terpisah di Daftar PDO.
6. Kerani dapat langsung mencatat realisasi untuk item-item yang baru ditambahkan (tunduk pada semua aturan BR-REAL).

**Aturan referensi:** Setiap item yang berasal dari merger menyimpan referensi ke nomor PDO Tambahan asalnya untuk keperluan audit. Referensi ini tidak dapat dihapus.

**Contoh konkret:**
- PDOT-2026-06-KP-001 disetujui → 3 item baru ditambahkan ke PDO-2026-06-KP-001 di bagian bawah, dengan header: "▶ PDO Tambahan: PDOT-2026-06-KP-001 — disetujui 15 Jun 2026".

---

### BR-PDOT-005: Tampilan PDO Tambahan di Daftar PDO

**Deskripsi:** Visibilitas PDO Tambahan di Daftar PDO bergantung pada statusnya.

**Decision Table:**

| Status PDO Tambahan | Tampil di Daftar PDO? | Keterangan |
|--------------------|----------------------|-----------|
| Draft | ✅ Ya | Label Tipe: "Tambahan" |
| Submitted / In Review | ✅ Ya | Label Tipe: "Tambahan" |
| Final (Merged) | ❌ Tidak | Terlihat di dalam Detail PDO Bulanan via header pemisah |
| Rejected (kembali Draft) | ✅ Ya | Label Tipe: "Tambahan" |

---

## 7. Modul 5: Approval Workflow (BR-APPR)

### Ringkasan Modul

| ID | Judul | Prioritas |
|----|-------|-----------|
| BR-APPR-001 | Urutan Approval | Kritikal |
| BR-APPR-002 | Approval Paralel Manajer | Kritikal |
| BR-APPR-003 | Larangan Self-Approval | Kritikal |
| BR-APPR-004 | Mekanisme Reject | Kritikal |
| BR-APPR-005 | Efek Reject pada Approval Paralel | Kritikal |
| BR-APPR-006 | Revisi Setelah Reject | Tinggi |
| BR-APPR-007 | SLA Approval | Tinggi |
| BR-APPR-008 | Notifikasi per Transisi Status | Tinggi |

---

### BR-APPR-001: Urutan Approval

**Deskripsi:** Alur approval PDO (Bulanan maupun Tambahan) bersifat berurutan dan tidak dapat dilewati.

**Urutan wajib:**
```
Kerani (submit) → Asisten Kebun → [Manajer Kebun & Manajer Keuangan] → Direktur Keuangan
```

**Aturan:**
- Manajer Kebun dan Manajer Keuangan menerima notifikasi secara bersamaan dan melakukan review secara paralel.
- Direktur Keuangan hanya menerima PDO setelah **kedua** Manajer memberikan persetujuan.
- Sistem menolak aksi approve yang tidak sesuai tahap saat ini (misal: Direktur mencoba approve PDO yang belum di-approve Manajer).

---

### BR-APPR-002: Approval Paralel Manajer

**Deskripsi:** Manajer Kebun dan Manajer Keuangan melakukan review secara independen dan paralel. Keduanya harus approve sebelum PDO dilanjutkan ke Direktur.

**State pada tahap Manajer:**

| Kondisi | Status |
|---------|--------|
| Belum ada yang approve | In Review (Manajer) |
| Salah satu approve, satu belum | In Review (Manajer) — menunggu yang belum |
| Keduanya approve | → Lanjut ke Direktur |
| Salah satu reject (apapun kondisi yang lain) | → Kembali ke Draft |

**Aturan penting:** Jika salah satu Manajer reject, approval dari Manajer lain yang sudah approve **hangus** dan tidak disimpan. Setelah Kerani revisi dan submit ulang, **kedua** Manajer harus approve kembali dari awal.

---

### BR-APPR-003: Larangan Self-Approval

**Deskripsi:** User yang membuat PDO tidak dapat memberikan persetujuan pada PDO yang sama, di tahap manapun.

**Kondisi / Trigger:** User yang membuat PDO mencoba melakukan approve.

**Aksi / Konsekuensi:**
- Sistem memeriksa `created_by` PDO vs `user_id` yang sedang login.
- Jika sama: tombol "Approve" disembunyikan atau dinonaktifkan, bukan sekadar diberi pesan error.
- Pesan: "Anda tidak dapat menyetujui PDO yang Anda buat sendiri."

**Pengecualian:** Larangan hanya berlaku untuk approve. Pihak yang membuat PDO tetap dapat melihat PDO-nya dan melihat Approval Timeline.

---

### BR-APPR-004: Mekanisme Reject

**Deskripsi:** Setiap approver dalam chain dapat melakukan reject. Reject wajib disertai alasan.

**Kondisi / Trigger:** Approver menekan tombol "Reject".

**Aksi / Konsekuensi:**
1. Sistem menampilkan modal dengan textarea "Alasan Penolakan".
2. Field alasan wajib diisi — tombol konfirmasi reject dinonaktifkan jika kosong.
3. Setelah dikonfirmasi:
   - Status PDO berubah kembali ke **Draft**.
   - Alasan reject disimpan dalam Approval Timeline.
   - Notifikasi (in-system + WhatsApp) dikirim ke Kerani beserta alasan penolakan.
4. Approval yang sudah diberikan di tahap-tahap sebelumnya hangus — tidak disimpan.
5. PDO kembali ke Kerani untuk direvisi dan disubmit ulang dari awal.

**Pengecualian:** Tidak ada. Reject selalu mengembalikan PDO ke Draft terlepas dari di tahap mana reject dilakukan.

---

### BR-APPR-005: Efek Reject pada Tahap Approval Paralel Manajer

**Deskripsi:** Aturan khusus yang berlaku saat reject terjadi di tahap review paralel Manajer Kebun dan Manajer Keuangan.

**Kondisi / Trigger:** Salah satu Manajer melakukan reject, sementara Manajer lainnya sudah atau belum memberikan keputusan.

**Aksi / Konsekuensi:**

| Kondisi saat reject | Aksi Sistem |
|---------------------|------------|
| Manajer A reject, Manajer B belum approve | PDO kembali ke Draft. Keputusan Manajer B (yang belum) dihapus dari antrian. |
| Manajer A reject, Manajer B sudah approve | PDO kembali ke Draft. **Approval Manajer B hangus** dan tidak disimpan. |

**Setelah Kerani revisi dan submit ulang:** Kedua Manajer harus memberikan persetujuan kembali dari awal — tidak ada "carry-over" approval dari putaran sebelumnya. Ini berlaku tanpa pengecualian untuk menjamin integritas review.

**Alasan aturan ini:** Revisi Kerani dapat mengubah substansi PDO, sehingga approval yang diberikan sebelumnya tidak relevan dan tidak dapat diasumsikan masih berlaku.

---

### BR-APPR-006: Revisi Setelah Reject

**Deskripsi:** Setelah PDO dikembalikan ke Draft akibat reject, Kerani dapat melakukan revisi dan submit ulang.

**Yang boleh dilakukan Kerani saat revisi:**
- Mengubah nilai Jumlah pada baris yang ada.
- Mengubah Keterangan, Qty, Satuan, Tarif, No Akun.
- Menambah baris item baru.
- Menghapus baris item yang ada.
- Mengubah data header PDO (Tanggal Pengajuan, Catatan).

**Yang tidak boleh dilakukan:**
- Mengubah Periode PDO.
- Mengubah Unit Kebun.

**Setelah revisi selesai:** Kerani submit ulang → approval mulai dari tahap pertama (Asisten Kebun).

---

### BR-APPR-007: SLA Approval

**Deskripsi:** Setiap tahap approval memiliki batas waktu penyelesaian.

**SLA:** **1 hari kerja** sejak notifikasi dikirim ke approver.

**Definisi hari kerja:** Senin–Jumat. Hari libur nasional **tidak diperhitungkan** pada versi 1.0 — sistem menghitung hari kerja secara sederhana berdasarkan hari dalam seminggu saja.

**Aksi jika SLA terlampaui:**
- Sistem mengirimkan notifikasi reminder via WhatsApp ke approver yang bersangkutan.
- Reminder dikirim **satu kali** saat SLA terlampaui. Jika masih belum ada keputusan, tidak ada reminder tambahan.
- PDO tidak otomatis di-reject meskipun SLA terlampaui — keputusan tetap di tangan approver.

---

### BR-APPR-008: Notifikasi per Transisi Status

**Matriks Notifikasi:**

| Event | Penerima | Channel |
|-------|----------|---------|
| PDO disubmit | Asisten Kebun | In-system + WhatsApp |
| Asisten approve | Manajer Kebun, Manajer Keuangan | In-system + WhatsApp |
| Manajer approve (keduanya) | Direktur Keuangan | In-system + WhatsApp |
| PDO reject (oleh siapapun) | Kerani | In-system + WhatsApp (dengan alasan) |
| PDO Final | Kerani | In-system + WhatsApp |
| SLA terlampaui | Approver yang bersangkutan | WhatsApp |
| PDO belum submit tanggal 1 | Kerani, Asisten, Manajer Kebun, Manajer Keuangan, Direktur | WhatsApp |

---

## 8. Modul 6: Pencatatan Realisasi Dana (BR-REAL)

### Ringkasan Modul

| ID | Judul | Prioritas |
|----|-------|-----------|
| BR-REAL-001 | Prasyarat Realisasi | Kritikal |
| BR-REAL-002 | Hak Akses Realisasi | Kritikal |
| BR-REAL-003 | Komponen Wajib Entri Realisasi | Kritikal |
| BR-REAL-004 | Multi-Entri Realisasi per Item | Tinggi |
| BR-REAL-005 | Validasi Kumulatif Level PDO | Kritikal |
| BR-REAL-006 | Over Budget per Item | Tinggi |
| BR-REAL-007 | Threshold Wajib Bukti | Kritikal |
| BR-REAL-008 | Threshold Wajib Penjelasan Selisih | Kritikal |
| BR-REAL-009 | Edit Entri Realisasi | Tinggi |
| BR-REAL-010 | Kalkulasi Saldo dan Persentase | Kritikal |
| BR-REAL-011 | Status Realisasi per Item | Sedang |

---

### BR-REAL-001: Prasyarat Realisasi

**Deskripsi:** Realisasi hanya dapat dicatat untuk PDO dengan status Final.

**Decision Table:**

| Status PDO | Dapat Dicatat Realisasi? |
|-----------|------------------------|
| Draft | ❌ Tidak |
| Submitted / In Review | ❌ Tidak |
| Final | ✅ Ya |
| Closed | ❌ Tidak |

---

### BR-REAL-002: Hak Akses Realisasi

**Deskripsi:** Hak akses untuk mencatat realisasi berbeda berdasarkan role, khususnya pada pilihan Sumber Dana.

**Aturan Kerani:**
- Dapat mencatat realisasi untuk semua item biaya dalam PDO unitnya.
- Dropdown Sumber Dana menampilkan: Kas Kebun, Rekening Kebun, Rekening Utama Perusahaan.

**Aturan Staff Purchasing:**
- Dapat mencatat realisasi untuk **semua item biaya** dalam PDO (tidak dibatasi per item).
- Dropdown Sumber Dana hanya menampilkan satu pilihan: **"Rekening Utama Perusahaan"**.
- Pilihan Kas Kebun dan Rekening Kebun tidak ditampilkan dan tidak dapat dipilih.

**Catatan implementasi:** Pembatasan Staff Purchasing diimplementasikan di level UI (dropdown) DAN di level backend (validasi server-side). Backend menolak entri dengan Sumber Dana selain "Rekening Utama Perusahaan" jika aktor adalah Staff Purchasing.

---

### BR-REAL-003: Komponen Wajib Entri Realisasi

**Field wajib untuk setiap entri realisasi:**

| Field | Tipe | Keterangan |
|-------|------|-----------|
| Nominal | Integer (Rupiah) | > 0, tidak boleh negatif |
| Tanggal Transaksi | Date | Format DD/MM/YYYY |
| Metode Pembayaran | Enum | Tunai / Transfer / Kas Kecil |
| Nomor Bukti Transaksi | String | Nomor kwitansi/bukti |
| Sumber Dana | Enum | Kas Kebun / Rekening Kebun / Rekening Utama Perusahaan |
| Upload Bukti | File | Wajib jika Nominal > threshold (default Rp 1.000.000) |
| Penjelasan Selisih | String | Wajib jika |Transfer item − Realisasi item| > threshold (default Rp 500.000) |

---

### BR-REAL-004: Multi-Entri Realisasi per Item

**Deskripsi:** Satu item biaya dapat memiliki lebih dari satu entri realisasi.

**Kondisi / Trigger:** Kerani atau Staff Purchasing menambah entri realisasi baru untuk item yang sudah memiliki entri realisasi.

**Aksi / Konsekuensi:**
- Sistem menerima entri baru — tidak ada batasan jumlah entri per item.
- Total Realisasi item diperbarui otomatis (kumulatif).
- Sistem memvalidasi batas kumulatif PDO (BR-REAL-005) sebelum menyimpan.

**Contoh konkret:**
- Pembayaran gaji dipecah menjadi dua kali: Rp 3.000.000 (tanggal 5) dan Rp 2.000.000 (tanggal 15) → Dua entri terpisah, Total Realisasi item = Rp 5.000.000.

---

### BR-REAL-005: Validasi Kumulatif Level PDO

**Deskripsi:** Ini adalah constraint keras yang tidak dapat dilanggar. Total Realisasi PDO tidak boleh melebihi Total Transfer PDO.

**Formula validasi:**
```
Total Realisasi PDO (setelah entri baru) ≤ Total Transfer PDO
```

**Kondisi / Trigger:** Kerani atau Staff Purchasing menekan "Simpan" pada entri realisasi baru, atau menyimpan edit entri yang sudah ada.

**Aksi / Konsekuensi:**
- Sistem menghitung: Total Realisasi PDO saat ini + Nominal entri baru.
- Jika hasil > Total Transfer PDO: **sistem memblokir** penyimpanan.
- Pesan error: "Total realisasi PDO akan melebihi total dana yang ditransfer (Rp [Total Transfer PDO]). Sisa saldo PDO: Rp [Sisa Saldo]. Tambahkan dana transfer terlebih dahulu atau kurangi nominal realisasi."
- Jika hasil ≤ Total Transfer PDO: penyimpanan dilanjutkan.

**Catatan penting:** Aturan ini bersifat **level PDO** — over budget per item diizinkan selama kumulatif PDO masih dalam batas. Kerani bebas menggunakan saldo transfer item lain untuk menutupi item yang over budget.

**Contoh konkret:**
- Total Transfer PDO = Rp 10.000.000, Total Realisasi PDO saat ini = Rp 9.500.000
- Kerani mencoba input realisasi Rp 600.000 → Total menjadi Rp 10.100.000 > Rp 10.000.000 → **DIBLOKIR**
- Kerani mencoba input realisasi Rp 500.000 → Total menjadi Rp 10.000.000 = Rp 10.000.000 → **DIIZINKAN**

---

### BR-REAL-006: Over Budget per Item

**Deskripsi:** Over budget per item bersifat informatif — hanya peringatan, tidak memblokir (selama kumulatif PDO masih dalam batas).

**Kondisi / Trigger:** Total Realisasi item > Total Transfer item.

**Aksi / Konsekuensi:**
- Badge "Over Budget" ditampilkan pada baris item tersebut (warna kuning).
- Sistem tidak memblokir penambahan entri realisasi selama Total Realisasi PDO ≤ Total Transfer PDO.
- Item dengan badge "Over Budget" muncul di tab filter "Over Budget per Item".

**Contoh konkret (legal):**
- Transfer BBM Solar Rp 3.000.000, Realisasi Rp 2.000.000 (under budget Rp 1.000.000)
- Transfer Lembur Rp 2.000.000, Realisasi Rp 3.000.000 (over budget Rp 1.000.000)
- Total Transfer PDO = Rp 5.000.000, Total Realisasi PDO = Rp 5.000.000 → **VALID** (kumulatif seimbang)
- Badge "Over Budget" muncul pada item Lembur — informatif saja.

---

### BR-REAL-007: Threshold Wajib Bukti

**Deskripsi:** Upload bukti transaksi menjadi wajib jika nominal realisasi melebihi threshold.

**Threshold:** Berlaku **global** untuk semua item biaya — satu nilai yang dikonfigurasi oleh Admin di halaman Pengaturan. Nilai default: **Rp 1.000.000**. Tidak ada konfigurasi threshold per item.

**Decision Table:**

| Nominal Realisasi | Threshold Bukti Global | Upload Bukti |
|------------------|----------------------|--------------|
| ≤ Threshold | — | Opsional |
| > Threshold | — | **Wajib** |

**Aturan:** Jika upload wajib namun belum ada file yang diunggah, tombol "Simpan" untuk baris tersebut dinonaktifkan.

**Format file yang diterima:** PDF, JPG, PNG. Ukuran maksimum: **5 MB** per file.

---

### BR-REAL-008: Threshold Wajib Penjelasan Selisih

**Deskripsi:** Penjelasan selisih wajib diisi jika selisih antara Total Transfer item dan Total Realisasi item melebihi threshold.

**Threshold:** Berlaku **global** untuk semua item biaya — satu nilai yang dikonfigurasi oleh Admin di halaman Pengaturan. Nilai default: **Rp 500.000**. Tidak ada konfigurasi threshold per item.

**Formula selisih:**
```
Selisih = |Total Transfer (item) − Total Realisasi (item)|
```

**Decision Table:**

| Selisih vs Threshold Global | Penjelasan Selisih |
|-----------------------------|-------------------|
| ≤ Threshold | Opsional |
| > Threshold | **Wajib** |

**Aturan:** Validasi bersifat per item — tidak bergantung pada kondisi kumulatif PDO. Jika penjelasan wajib namun kosong, tombol "Simpan" untuk baris tersebut dinonaktifkan.

---

### BR-REAL-009: Edit Entri Realisasi

**Deskripsi:** Kerani dapat mengedit entri realisasi yang sudah disimpan, dengan batasan waktu dan validasi kumulatif.

**Kondisi / Trigger:** Kerani memilih "Edit" pada entri realisasi tertentu.

**Yang dapat diedit:** Nominal, Tanggal Transaksi, Metode Pembayaran, Nomor Bukti, Sumber Dana, file Bukti, Penjelasan Selisih.

**Aturan:**
1. Edit hanya dapat dilakukan selama PDO belum berstatus **Closed**.
2. Jika hasil edit menyebabkan Total Realisasi PDO > Total Transfer PDO: sistem memblokir perubahan (sama seperti BR-REAL-005).
3. Setiap perubahan yang berhasil disimpan dicatat dalam audit log: nilai lama, nilai baru, diubah oleh siapa, timestamp.

**Pengecualian:** Staff Purchasing tidak dapat mengedit entri realisasi — hanya Kerani yang dapat mengedit.

---

### BR-REAL-010: Kalkulasi Saldo dan Persentase

**Formula:**

```
--- Per Item ---
Total Transfer (item)   = Σ(entri transfer item)
Total Realisasi (item)  = Σ(entri realisasi item)
Saldo (item)            = Total Transfer (item) − Total Realisasi (item)
% Realisasi (item)      = (Total Realisasi (item) / Total Transfer (item)) × 100
                          → Tampilkan 0% jika Total Transfer (item) = 0

--- Level PDO ---
Total Transfer (PDO)    = Σ(Total Transfer semua item)
Total Realisasi (PDO)   = Σ(Total Realisasi semua item)
Saldo (PDO)             = Total Transfer (PDO) − Total Realisasi (PDO)
% Realisasi (PDO)       = (Total Realisasi (PDO) / Total Transfer (PDO)) × 100
                          → Tampilkan 0% jika Total Transfer (PDO) = 0
```

**Aturan tampilan:** Semua nilai ditampilkan sebagai bilangan bulat Rupiah dengan pemisah ribuan (contoh: Rp 3.500.000). Persentase ditampilkan satu desimal (contoh: 85,7%).

---

### BR-REAL-011: Status Realisasi per Item

**Status yang ditampilkan pada setiap item di halaman Realisasi:**

| Status | Kondisi | Warna Badge |
|--------|---------|-------------|
| Belum Realisasi | Total Realisasi (item) = 0 | Abu |
| Partial | 0 < Total Realisasi (item) < Total Transfer (item) | Biru |
| Sesuai | Total Realisasi (item) = Total Transfer (item) | Hijau |
| Over Budget | Total Realisasi (item) > Total Transfer (item) | Kuning |
| Belum Ada Bukti | Ada entri realisasi nominal > threshold tanpa file bukti | Oranye |
| Butuh Penjelasan | Selisih > threshold dan penjelasan kosong | Merah |

**Catatan:** Satu item dapat memiliki lebih dari satu status sekaligus (misal: Over Budget + Belum Ada Bukti). Dalam kasus multi-status, badge ditampilkan semua — tidak ada yang disembunyikan. Untuk keperluan filter tab, item muncul di semua tab yang statusnya terpenuhi.

**Urutan prioritas badge** (dari kiri ke kanan jika ditampilkan berjajar):

| Urutan | Status | Alasan Prioritas |
|--------|--------|-----------------|
| 1 | Butuh Penjelasan | Paling menghambat — wajib diisi sebelum simpan |
| 2 | Belum Ada Bukti | Menghambat — wajib diisi sebelum simpan |
| 3 | Over Budget | Perlu perhatian Finance |
| 4 | Partial | Informatif |
| 5 | Sesuai | Informatif positif |
| 6 | Belum Realisasi | Informatif — belum ada tindakan |

---

## 9. Modul 7: Master Data (BR-MASTER)

### Ringkasan Modul

| ID | Judul | Prioritas |
|----|-------|-----------|
| BR-MASTER-001 | Hierarki Tiga Level | Kritikal |
| BR-MASTER-002 | Integritas Referensial | Tinggi |
| BR-MASTER-003 | Kode Unik | Tinggi |
| BR-MASTER-004 | Nonaktifkan vs Hapus | Kritikal |
| BR-MASTER-005 | Field Rutin | Tinggi |
| BR-MASTER-006 | Field Mode Input | Tinggi |
| BR-MASTER-007 | Tarif Default | Sedang |
| BR-MASTER-008 | Nomor WhatsApp User | Tinggi |

---

### BR-MASTER-001: Hierarki Tiga Level

**Deskripsi:** Struktur master data biaya terdiri dari tiga level hierarki yang wajib diikuti.

```
Kategori Biaya (Level 1)
  └─ Sub-Kategori Biaya (Level 2)
       └─ Item Biaya (Level 3)
```

**Aturan:**
- Item Biaya tidak dapat berdiri sendiri tanpa Sub-Kategori induk.
- Sub-Kategori tidak dapat berdiri sendiri tanpa Kategori induk.
- Satu Sub-Kategori hanya memiliki satu Kategori induk.
- Satu Item Biaya hanya memiliki satu Sub-Kategori induk.

---

### BR-MASTER-002: Integritas Referensial

**Deskripsi:** Entitas yang masih memiliki turunan aktif tidak dapat dihapus.

**Decision Table:**

| Aksi | Kondisi | Hasil |
|------|---------|-------|
| Hapus Kategori | Masih ada Sub-Kategori aktif | ❌ Ditolak |
| Hapus Kategori | Tidak ada Sub-Kategori aktif | ✅ Diizinkan |
| Hapus Sub-Kategori | Masih ada Item Biaya aktif | ❌ Ditolak |
| Hapus Sub-Kategori | Tidak ada Item Biaya aktif | ✅ Diizinkan |
| Hapus Item Biaya | Sudah pernah dipakai dalam PDO | ❌ Ditolak (soft delete saja) |
| Hapus Item Biaya | Belum pernah dipakai | ✅ Diizinkan (hard delete) |

---

### BR-MASTER-003: Kode Unik

**Deskripsi:** Setiap entitas master data memiliki kode yang harus unik dalam scope-nya.

| Entitas | Scope Keunikan |
|---------|----------------|
| Kode Kategori | Global (seluruh sistem) |
| Kode Sub-Kategori | Per Kategori induk |
| Kode Item Biaya | Per Sub-Kategori induk |

**Aksi:** Jika kode yang dimasukkan sudah ada, sistem menampilkan pesan error dan mencegah penyimpanan.

---

### BR-MASTER-004: Nonaktifkan vs Hapus

**Deskripsi:** Item biaya yang sudah pernah dipakai dalam PDO tidak dapat dihapus secara permanen.

**Aturan:**
- **Hard delete** (penghapusan permanen): hanya untuk item yang belum pernah dipakai dalam PDO manapun.
- **Soft delete** (nonaktifkan): untuk item yang sudah pernah dipakai — status diubah menjadi `Nonaktif`.

**Efek nonaktifkan:**
- Item nonaktif **tidak muncul** di dropdown form PDO baru.
- Item nonaktif **tetap tampil** di PDO yang sudah dibuat sebelumnya (referensi historis tetap valid).

**Aturan yang sama berlaku untuk Kategori dan Sub-Kategori:** Kategori/Sub-Kategori yang masih direferensikan oleh PDO hanya dapat dinonaktifkan, tidak dihapus.

---

### BR-MASTER-005: Field Rutin

**Deskripsi:** Atribut `rutin` pada Item Biaya menentukan apakah item masuk template PDO Bulanan secara otomatis.

**Nilai valid:** `Ya` atau `Tidak` — tidak ada nilai lain.

**Aturan perubahan:**
- Field `rutin` dapat diubah kapan saja oleh Admin.
- Perubahan **hanya berlaku untuk PDO yang dibuat setelah perubahan** — PDO yang sudah ada tidak terpengaruh.
- Contoh: Item "Gaji Kontrak" diubah dari `rutin = Tidak` menjadi `rutin = Ya` pada 10 Juni → mulai PDO Juli 2026, item ini masuk template otomatis.

---

### BR-MASTER-006: Field Mode Input

**Deskripsi:** Atribut `mode_input` menentukan bagaimana nilai item diisi pada form PDO.

**Nilai valid:** `manual` atau `auto_external`.

| Nilai | Perilaku di Form PDO |
|-------|---------------------|
| `manual` | Kolom Jumlah dapat diisi langsung oleh Kerani |
| `auto_external` | Kolom Jumlah read-only; tombol "Ambil Biaya" muncul |

**Tampilan di Master Data:** Badge `manual` (abu) atau badge `auto_external` (ungu) pada kolom Mode Input.

---

### BR-MASTER-007: Tarif Default

**Deskripsi:** Tarif default dari master data dapat di-override oleh Kerani saat mengisi PDO.

**Aturan:**
- Saat item dipilih pada form PDO, Tarif diisi otomatis dari master data.
- Kerani boleh mengubah nilai Tarif untuk PDO tersebut tanpa mengubah master data.
- Perubahan Tarif di master data **tidak memengaruhi** PDO yang sudah dibuat — snapshot tarif tersimpan di level PDO saat item dipilih.

---

### BR-MASTER-008: Nomor WhatsApp User

**Deskripsi:** Setiap user harus memiliki nomor WhatsApp yang terdaftar untuk keperluan notifikasi.

**Aturan:**
- Nomor WhatsApp adalah field wajib saat membuat atau mengedit akun user.
- Hanya Admin yang dapat mengisi atau mengubah nomor WhatsApp user.
- Format: nomor internasional tanpa tanda + dan spasi (contoh: `628123456789`).
- Nomor WhatsApp digunakan oleh sistem untuk mengirim notifikasi via WhatsApp gateway.

---

## 10. Modul 8: Validasi & Integritas Data (BR-VAL)

### Ringkasan Modul

| ID | Judul | Prioritas |
|----|-------|-----------|
| BR-VAL-001 | Larangan Nilai Negatif | Kritikal |
| BR-VAL-002 | Periode Valid | Tinggi |
| BR-VAL-003 | Konsistensi Kalkulasi | Kritikal |
| BR-VAL-004 | Integritas Transfer dan Realisasi | Kritikal |

---

### BR-VAL-001: Larangan Nilai Negatif

**Deskripsi:** Nilai negatif tidak diizinkan pada semua field numerik yang berhubungan dengan nominal uang atau kuantitas.

**Field yang terdampak:** Jumlah (PDO), Qty, Tarif, Nominal Transfer, Nominal Realisasi.

**Aturan:** Sistem menolak input dengan nilai < 0 di semua field tersebut, baik di frontend maupun backend.

---

### BR-VAL-002: Periode Valid

**Deskripsi:** PDO hanya dapat dibuat untuk periode yang valid.

**Aturan:**
- Format periode: YYYY-MM (contoh: 2026-06).
- PDO **hanya dapat dibuat** untuk bulan berjalan atau bulan-bulan sebelumnya.
- PDO **tidak dapat dibuat** untuk periode di masa depan (bulan depan atau lebih).

**Contoh (hari ini = 18 Juni 2026):**
- ✅ Periode Juni 2026 → Valid (bulan berjalan)
- ✅ Periode Mei 2026 → Valid (bulan sebelumnya)
- ✅ Periode Januari 2026 → Valid (bulan-bulan lalu)
- ❌ Periode Juli 2026 → Ditolak (bulan depan — belum boleh)
- ❌ Periode Agustus 2026 → Ditolak (masa depan)

**Alasan:** PDO dibuat berdasarkan kebutuhan riil bulan berjalan atau koreksi bulan sebelumnya — bukan proyeksi masa depan.

---

### BR-VAL-003: Konsistensi Kalkulasi

**Deskripsi:** Sistem memastikan kalkulasi di frontend dan backend selalu konsisten.

**Aturan:**
- Grand Total PDO yang dikirim saat submit divalidasi ulang oleh backend.
- Jika Grand Total dari frontend tidak sesuai dengan penjumlahan baris yang diterima, backend menggunakan hasil kalkulasi ulangnya sendiri.
- Total Transfer kumulatif dan Total Realisasi kumulatif selalu dihitung dari tabel entri (bukan dari field cache) untuk mencegah inkonsistensi.

---

### BR-VAL-004: Integritas Transfer dan Realisasi

**Deskripsi:** Sistem memastikan constraint utama antara transfer dan realisasi selalu terjaga.

**Constraint utama (hard — memblokir):**
```
Total Realisasi PDO ≤ Total Transfer PDO
```

**Constraint informatif (soft — hanya peringatan):**
```
Total Transfer (per item) ≠ Jumlah Pengajuan → Badge "Selisih Transfer"
Total Realisasi (per item) > Total Transfer (per item) → Badge "Over Budget"
```

---

## 11. Modul 9: Laporan & Rekapitulasi (BR-LAP)

### Ringkasan Modul

| ID | Judul | Prioritas |
|----|-------|-----------|
| BR-LAP-001 | Struktur Rekapitulasi | Tinggi |
| BR-LAP-002 | Kolom Data Laporan | Tinggi |
| BR-LAP-003 | Filter Laporan | Tinggi |
| BR-LAP-004 | Retensi Data | Sedang |
| BR-LAP-005 | Format Export | Tinggi |

---

### BR-LAP-001: Struktur Rekapitulasi

**Deskripsi:** Halaman Rekapitulasi menampilkan data dalam struktur hierarkis dengan subtotal otomatis.

**Struktur tampilan:**
```
[Kategori A] ─────────────────── Subtotal A
  [Sub-Kategori A.1] ─────────── Subtotal A.1
    Item A.1.1
    Item A.1.2
  [Sub-Kategori A.2] ─────────── Subtotal A.2
    Item A.2.1
[Kategori B] ─────────────────── Subtotal B
  ...
[GRAND TOTAL] ─────────────────── Grand Total
```

**Aturan:** Subtotal Kategori = jumlah semua Sub-Kategori di dalamnya. Subtotal Sub-Kategori = jumlah semua item di dalamnya. Grand Total = jumlah semua Kategori.

---

### BR-LAP-002: Kolom Data Laporan

**Rekapitulasi menampilkan kolom:**

| Kolom | Keterangan |
|-------|-----------|
| Nomor | Nomor urut |
| Kategori | Nama Kategori |
| Sub-Kategori | Nama Sub-Kategori |
| Kode | Kode Item Biaya |
| Jumlah Pengajuan | Nominal yang diajukan |
| Total Transfer | Kumulatif semua entri transfer |
| Total Realisasi | Kumulatif semua entri realisasi |
| Saldo | Total Transfer − Total Realisasi |

**Laporan Realisasi Dana menampilkan kolom tambahan:** Status per item (Belum Realisasi / Partial / Sesuai / Over Budget / Belum Ada Bukti / Butuh Penjelasan).

---

### BR-LAP-003: Filter Laporan

**Filter yang tersedia pada semua laporan:**

| Filter | Opsi |
|--------|------|
| Periode | Bulan dan tahun (YYYY-MM) |
| Unit Kebun | Dropdown semua unit (untuk role lintas unit) atau unit sendiri (untuk Kerani/Asisten) |
| Kategori Biaya | Multi-select dari daftar Kategori |
| Sub-Kategori Biaya | Multi-select, cascading dari Kategori yang dipilih |
| Status PDO | Draft / Submitted / In Review / Final / Closed |

---

### BR-LAP-004: Retensi Data

**Deskripsi:** Sistem menampilkan data PDO untuk 12 bulan terakhir (1 tahun anggaran) secara default.

**Aturan:**
- Data di luar 12 bulan terakhir diarsipkan dan tidak tampil di filter default.
- Data yang diarsipkan tetap dapat diakses melalui filter periode lanjutan (pilih bulan/tahun secara manual).
- Data tidak pernah dihapus dari database.

---

### BR-LAP-005: Format Export

**Format yang didukung:**

| Format | Status |
|--------|--------|
| Excel (.xlsx) | Must Have — semua laporan |
| PDF | Should Have — semua laporan |

**Aturan:** Filter yang aktif saat export diterapkan pada data yang diekspor. File Excel harus menyertakan header kolom, subtotal per kategori, dan grand total.

---

## 12. Modul 10: Notifikasi & Audit Trail (BR-NOTIF)

### Ringkasan Modul

| ID | Judul | Prioritas |
|----|-------|-----------|
| BR-NOTIF-001 | Channel Notifikasi | Kritikal |
| BR-NOTIF-002 | Konfigurasi WhatsApp Gateway | Kritikal |
| BR-NOTIF-003 | Template Pesan WhatsApp | Tinggi |
| BR-NOTIF-004 | Reminder Bulanan | Tinggi |
| BR-NOTIF-005 | Audit Log — Cakupan | Kritikal |
| BR-NOTIF-006 | Audit Log — Immutability | Kritikal |

---

### BR-NOTIF-001: Channel Notifikasi

**Deskripsi:** Sistem menggunakan dua channel notifikasi secara bersamaan.

| Channel | Kapan Digunakan |
|---------|----------------|
| In-system (toast) | Semua aksi yang berhasil atau gagal, real-time saat user aktif |
| WhatsApp | Semua event penting (submit, approve, reject, SLA, reminder) — lihat BR-APPR-008 |

**Aturan:** Kedua channel bersifat **Must Have**. Jika WhatsApp gateway tidak terhubung, sistem tetap mengirim notifikasi in-system dan mencatat error pengiriman WhatsApp di log.

---

### BR-NOTIF-002: Konfigurasi WhatsApp Gateway

**Deskripsi:** Admin mengkonfigurasi WhatsApp gateway melalui halaman Pengaturan.

**Field konfigurasi:**

| Field | Tipe | Keterangan |
|-------|------|-----------|
| URL Endpoint Gateway | String (URL) | Base URL WhatsApp gateway. Sistem menambahkan `/send/message` secara otomatis. Mendukung URL dengan atau tanpa path tersebut. |
| Username | String | Username untuk autentikasi Basic Auth ke WhatsApp gateway |
| Password | String (enkripsi) | Password Basic Auth. Disimpan terenkripsi, tidak ditampilkan dalam plaintext |
| Status Koneksi | Read-only | Hasil test connection |

**Aturan:**
- Konfigurasi dapat diubah tanpa deployment ulang.
- Sistem menggunakan HTTP Basic Auth (Authorization: Basic base64(username:password)) saat memanggil gateway.
- Request body ke gateway: `{ "phone": "628xxx", "message": "..." }`. Nomor WhatsApp user dikonversi otomatis ke format internasional (628xx) jika disimpan dalam format lokal (08xx).
- Tombol "Test Koneksi" mengirim pesan test ke nomor WhatsApp Admin yang sedang login.
- Jika konfigurasi tidak valid, sistem menampilkan error dan notifikasi WhatsApp tidak terkirim (in-system tetap berjalan).

---

### BR-NOTIF-003: Template Pesan WhatsApp

**Deskripsi:** Sistem menyediakan template pesan WhatsApp yang dapat dikonfigurasi per jenis notifikasi.

**Variabel dinamis yang tersedia:**

| Variabel | Nilai |
|----------|-------|
| `{{nama_user}}` | Nama lengkap penerima notifikasi |
| `{{nomor_pdo}}` | Nomor PDO (contoh: PDO-2026-06-KP-001) |
| `{{periode}}` | Periode PDO (contoh: Juni 2026) |
| `{{unit_kebun}}` | Nama unit kebun |
| `{{alasan_reject}}` | Alasan penolakan (untuk notifikasi reject) |
| `{{deadline}}` | Tanggal deadline SLA |
| `{{total_pengajuan}}` | Nominal grand total pengajuan |

**Jenis template yang tersedia:** PDO disubmit, PDO di-approve (per tahap), PDO ditolak, SLA terlampaui, reminder bulanan, PDO Final.

---

### BR-NOTIF-004: Reminder Bulanan

**Deskripsi:** Sistem mengirim reminder otomatis jika PDO Bulanan belum disubmit pada tanggal yang dikonfigurasi.

**Default:** Tanggal 1 setiap bulan.

**Penerima:** Kerani, Asisten Kebun, Manajer Kebun, Manajer Keuangan, dan Direktur Keuangan dari unit terkait.

**Kondisi pengiriman reminder:**

| Kondisi PDO Bulanan bulan berjalan | Reminder Dikirim? |
|------------------------------------|------------------|
| Belum ada PDO sama sekali | ✅ Ya |
| Ada PDO, status Draft | ✅ Ya (belum disubmit) |
| Ada PDO, status Submitted / In Review | ❌ Tidak (sudah dalam proses) |
| Ada PDO, status Final | ❌ Tidak (sudah selesai) |
| Ada PDO, status Closed | ❌ Tidak |

**Konfigurasi:** Admin dapat mengubah tanggal dan jam pengiriman dari halaman Pengaturan.

---

### BR-NOTIF-005: Audit Log — Cakupan

**Deskripsi:** Sistem mencatat audit log untuk setiap operasi yang mengubah data penting.

**Event yang wajib di-log:**

| Event | Data yang Dicatat |
|-------|------------------|
| Perubahan status PDO | PDO ID, status lama, status baru, user, timestamp, catatan |
| Approve / Reject PDO | PDO ID, tahap, aksi, user, timestamp, alasan (jika reject) |
| Tambah entri transfer | Item ID, nominal, tanggal, nomor referensi, user, timestamp |
| Koreksi entri transfer | Entri ID, nilai lama (semua field), nilai baru, user, timestamp |
| Tambah entri realisasi | Item ID, nominal, tanggal, metode, sumber dana, user, timestamp |
| Edit entri realisasi | Entri ID, nilai lama (semua field), nilai baru, user, timestamp |
| Penutupan PDO | PDO ID, jenis (SYSTEM/MANUAL), user/SYSTEM, tanggal, catatan |
| Perubahan master data | Entitas, field yang berubah, nilai lama, nilai baru, user, timestamp |
| Login gagal | Username yang dicoba, IP address, timestamp |

---

### BR-NOTIF-006: Audit Log — Immutability

**Deskripsi:** Audit log bersifat append-only — tidak dapat diubah atau dihapus oleh siapapun.

**Aturan:**
- Tidak ada endpoint API untuk update atau delete audit log.
- Tidak ada halaman UI untuk mengubah audit log.
- Bahkan Admin tidak dapat mengubah atau menghapus audit log.
- Audit log disimpan dalam tabel terpisah dengan hak tulis yang dibatasi di level database.

---

*Dokumen ini adalah turunan dari PRD-Dana-Operasional-Kebun-v1.7 dan merupakan sumber kebenaran tunggal untuk implementasi aturan bisnis sistem PDO PT Barumun Palma Nauli.*
