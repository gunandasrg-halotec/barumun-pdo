# User Manual: Membuat Item Biaya Baru di PDO

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
- Contoh: Pupuk, Pestisida, Biaya Tenaga Kerja, dll
- **Validasi:** Harus memilih salah satu dari daftar yang tersedia

#### **Kode Item** ⭐
- Kode unik untuk identifikasi item
- Format: Maksimal 3-4 karakter + nomor urut (contoh: `A1.001`, `B2.003`)
- **Ketentuan:** Harus unik, tidak boleh sama dengan item lain
- **Validasi:** Wajib diisi, minimal 1 karakter

#### **Nama Item** ⭐
- Nama deskriptif item biaya
- Contoh: "Pupuk Urea 46%", "Obat Rumput Jantung", "Upah Panen Manual"
- **Validasi:** Wajib diisi, minimal 1 karakter

---

### Field Opsional (Optional)

#### **No. Akun**
- Nomor rekening/akun untuk keperluan pencatatan akuntansi
- Format: Biasanya mengikuti standar COA (Chart of Accounts)
- Contoh: `6-1001` (Biaya Operasional), `6-1002` (Biaya Pemeliharaan)
- **Catatan:** Biarkan kosong jika belum ada integrasi akuntansi

#### **Satuan**
- Unit pengukuran untuk item ini
- Contoh: `kg`, `liter`, `jam`, `orang`, `ton`, dll
- **Fungsi:** Membantu dalam pencatatan dan estimasi biaya

#### **Tarif (Rate)**
- Nilai standar/default biaya per satuan
- Contoh: Jika satuan `kg`, tarif bisa `50000` (Rp 50.000/kg)
- **Tipe:** Angka desimal
- **Catatan:** Dapat diubah saat melakukan entry biaya aktual

#### **Mode Input**
- Pilihan metode input data biaya
- **Manual:** Data diinput langsung oleh user
- **Auto External:** Data diimport otomatis dari sistem eksternal
- **Default:** Manual

#### **Catatan**
- Deskripsi tambahan atau keterangan spesial tentang item
- Contoh: "Untuk pengolahan tanah awal musim", "Supplier preferensi dari PT XYZ"
- **Format:** Text area, bisa multiple line

---

## Field Penting: Fitur Rutin & Split Transfer

### 🔄 **Field Rutin (Item Rutin)**

#### **Apa itu Item Rutin?**
Item rutin adalah item biaya yang secara otomatis muncul di dalam form PDO (Dana Operasional) berdasarkan schedule/jadwal pabrik yang ditetapkan. Item ini tidak perlu diinput manual setiap kali membuat PDO, tetapi akan muncul secara otomatis.

#### **Kapan Menggunakan?**
Gunakan fitur "Item Rutin" untuk biaya-biaya yang bersifat **berulang dan periodik**, seperti:
- ✅ Upah tenaga kerja tetap
- ✅ Sewa alat/mesin yang kontrak bulanan
- ✅ Pemeliharaan rutin fasilitas
- ✅ Biaya operasional standar per bulan
- ✅ Pupuk/pestisida dengan jadwal aplikasi tetap

#### **Cara Menggunakan:**

1. **Centang checkbox "Item Rutin"**
   - Checkbox akan berubah menjadi checked (✓)

2. **Pilih Kebun/Unit Pabrik yang Berlaku**
   - Setelah centang, akan muncul section baru berwarna hijau muda
   - Opsi yang tersedia:
     - **Semua Kebun** (default jika tidak ada pilihan spesifik)
     - **Pilih kebun tertentu** (centang checkbox masing-masing kebun)
   
   **Contoh:**
   ```
   ☑ Semua Kebun
   ─────────────────
   ☐ Kebun Utama (KU)
   ☐ Kebun Utara (KT)
   ☐ Kebun Barat (KB)
   ☐ Kebun Timur (KR)
   ```

3. **Hasil:**
   - Item ini akan **otomatis muncul** di form PDO untuk kebun yang dipilih
   - Pengguna tidak perlu menambahkan item ini secara manual
   - User hanya perlu **mengisi nilai/jumlah biaya aktual** di PDO

#### **Contoh Kasus:**
Anda ingin membuat item "Upah Tenaga Kerja Harian":
- ☑ Item Rutin
- ☑ Semua Kebun (maka item akan muncul untuk semua kebun)
- Setiap kali membuat PDO baru, item "Upah Tenaga Kerja Harian" sudah otomatis ada, tinggal isi jumlahnya

---

### 💱 **Field Split Transfer**

#### **Apa itu Split Transfer?**
Split Transfer adalah fitur yang memungkinkan **satu item biaya dipecah dan ditransfer ke 2 tujuan berbeda** dengan persentase/jumlah yang berbeda. Misalnya, sebuah biaya dialokasikan sebagian untuk Kebun A dan sebagian untuk Kebun B.

#### **Kapan Menggunakan?**
Gunakan fitur "Split Transfer" untuk biaya bersama yang perlu dialokasikan ke beberapa unit/kebun, seperti:
- ✅ Biaya pemeliharaan jalan bersama (antara kebun)
- ✅ Biaya operasional office pusat (dibebankan ke semua kebun)
- ✅ Biaya transportasi produk (dari multiple kebun ke 1 pabrik)
- ✅ Biaya utilitas listrik/air (yang dipakai bersama)
- ✅ Konsultasi teknis (untuk multiple kebun)

#### **Cara Menggunakan:**

1. **Centang checkbox "Split Transfer"**
   - Checkbox akan berubah menjadi checked (✓)
   - Catatan kecil muncul: "(transfer ke 2 tujuan berbeda)"

2. **Pilih Kebun yang Bisa Menggunakan Split**
   - Setelah centang, akan muncul section baru berwarna biru muda
   - Opsi yang tersedia:
     - **Semua Kebun** (default jika tidak ada pilihan spesifik)
     - **Pilih kebun tertentu** (centang checkbox masing-masing kebun)

   **Contoh:**
   ```
   ☑ Semua Kebun
   ─────────────────
   ☐ Kebun Utama (KU)
   ☐ Kebun Utara (KT)
   ☐ Kebun Barat (KB)
   ☐ Kebun Timur (KR)
   ```

3. **Hasil di Halaman Transfer:**
   - Item ini akan menampilkan **mode split** di halaman transfer
   - User bisa memilih untuk mentransfer dengan mode normal atau mode split (ke 2 tujuan)
   - Jika mode split dipilih, user akan input alokasi persentase untuk masing-masing kebun

#### **Contoh Kasus:**
Anda ingin membuat item "Biaya Listrik Gardu Bersama":
- ☑ Split Transfer
- ☑ Semua Kebun (maka semua kebun bisa melakukan split untuk item ini)
- Saat entry transfer, user bisa pilih:
  - Transfer 100% ke Kebun Utama saja, ATAU
  - Split: 60% ke Kebun Utama, 40% ke Kebun Utara

---

## Checkbox Lainnya

### **Aktif (is_active)**
- **Fungsi:** Menentukan apakah item ini bisa digunakan atau tidak
- **Checked (✓):** Item aktif dan bisa dipilih saat input PDO/Transfer
- **Unchecked (☐):** Item tidak aktif (tersembunyi dari list)
- **Default:** Checked (✓)
- **Catatan:** Gunakan uncheck jika item sudah tidak digunakan lagi, jangan dihapus agar history tetap valid

---

## Workflow Lengkap: Contoh Praktis

### **Contoh 1: Membuat Item "Pupuk Urea" (Item Rutin)**

```
Formulir:
├─ Sub-Kategori Induk: Pupuk & Bahan Kimia
├─ Kode: P1.001
├─ Nama Item: Pupuk Urea 46%
├─ Satuan: kg
├─ Tarif: 15000 (Rp 15.000/kg)
├─ Mode Input: Manual
├─ ☑ Item Rutin
│  └─ ☑ Semua Kebun (akan auto-muncul di semua PDO)
├─ ☐ Split Transfer (tidak perlu, ini item individual)
├─ ☑ Aktif
└─ Catatan: "Pupuk Nitrogen, gunakan saat pengolahan tanah awal musim"
```

**Hasil:** Item "Pupuk Urea 46%" akan otomatis muncul di setiap PDO baru untuk semua kebun.

---

### **Contoh 2: Membuat Item "Biaya Listrik Pusat" (Split Transfer)**

```
Formulir:
├─ Sub-Kategori Induk: Biaya Operasional
├─ Kode: O1.005
├─ Nama Item: Biaya Listrik Gardu Utama
├─ Satuan: kWh (opsional)
├─ Tarif: (kosongkan)
├─ Mode Input: Manual
├─ ☐ Item Rutin (tidak rutin, hanya saat ada tagihan listrik)
├─ ☑ Split Transfer
│  └─ ☑ Semua Kebun (semua kebun bisa split item ini)
├─ ☑ Aktif
└─ Catatan: "Biaya listrik yang dibebankan bersama-sama ke semua kebun"
```

**Hasil:** Saat entry transfer, user bisa memilih split mode untuk membagi biaya listrik antara kebun.

---

### **Contoh 3: Membuat Item "Konsultasi Agronomis" (Rutin & Split)**

```
Formulir:
├─ Sub-Kategori Induk: Jasa Profesional
├─ Kode: JP.001
├─ Nama Item: Konsultasi Teknis Agronomis
├─ Satuan: session
├─ Tarif: 5000000 (Rp 5.000.000/session)
├─ Mode Input: Manual
├─ ☑ Item Rutin
│  └─ ☑ Semua Kebun
├─ ☑ Split Transfer
│  └─ ☑ Pilih khusus: Kebun Utama, Kebun Utara (hanya kedua kebun ini yang bisa split)
├─ ☑ Aktif
└─ Catatan: "Konsultasi teknis lapangan, dapat dijadwalkan sesuai kebutuhan"
```

**Hasil:** 
- Item akan otomatis muncul di PDO semua kebun (Rutin)
- Saat transfer, hanya Kebun Utama & Kebun Utara yang bisa memilih mode split

---

## Validasi & Error Handling

### Pesan Error Umum:

| Pesan Error | Penyebab | Solusi |
|---|---|---|
| "Pilih sub-kategori" | Belum memilih sub-kategori | Pilih sub-kategori dari dropdown |
| "Kode wajib diisi" | Field Kode kosong | Isi kode item (contoh: A1.001) |
| "Nama wajib diisi" | Field Nama kosong | Isi nama item yang deskriptif |
| "Item gagal disimpan" | Error umum server | Cek koneksi internet, atau hubungi admin |

---

## Tips & Best Practices

✅ **Lakukan:**
- Gunakan kode yang **konsisten dan terstruktur** (contoh: P = Pupuk, O = Operasional, J = Jasa)
- **Satuan dan Tarif** diisi untuk memudahkan estimasi biaya
- **Centang "Item Rutin"** untuk biaya berulang yang pasti setiap bulan
- Gunakan **Catatan** untuk keterangan khusus atau peringatan penting
- Cek checkbox **"Aktif"** untuk item yang masih digunakan

❌ **Jangan Lakukan:**
- Jangan buat item dengan kode duplikat
- Jangan gunakan fitur "Split Transfer" untuk item yang individual/tidak dibagi
- Jangan lupa **isi nama item** yang deskriptif dan mudah dipahami
- Jangan unchecked "Aktif" jika belum yakin, lebih baik ubah tarif menjadi 0 dulu

---

## Kapan Setelah Simpan?

Setelah klik tombol **"Simpan"**, sistem akan:
1. ✅ Validasi data yang diinput
2. ✅ Simpan ke database
3. ✅ Tampilkan notifikasi "Item berhasil dibuat"
4. ✅ Redirect kembali ke halaman Master Data > Item Biaya

Item baru sekarang **siap digunakan** di form PDO dan Transfer!

---

## Pertanyaan yang Sering Diajukan

**P: Apakah item yang sudah dibuat bisa diedit?**
A: Ya, klik item di daftar, lalu pilih "Edit". Data lama akan ter-load dan bisa dimodifikasi.

**P: Bisakah satu item memiliki BOTH "Item Rutin" DAN "Split Transfer"?**
A: Ya, bisa sekaligus kedua-duanya! Item akan otomatis muncul di PDO DAN bisa di-split saat transfer.

**P: Apa bedanya unchecked "Aktif" vs Dihapus?**
A: Item yang unchecked "Aktif" tetap tersimpan di history (untuk laporan), tapi tidak muncul di dropdown. Dihapus berarti full remove dari sistem.

**P: Jika hanya select kebun tertentu di "Item Rutin", apakah kebun lain tidak bisa pakai item ini?**
A: Benar, item hanya otomatis muncul untuk kebun yang dipilih. Kebun lain bisa pakai item ini hanya dengan menambahkan manual.

---

**Versi:** 1.0  
**Terakhir diupdate:** 24 Juni 2026  
**Kontak Support:** Hubungi Admin PDO untuk pertanyaan lebih lanjut
