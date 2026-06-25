# Prompt Interaktif: Membuat Item Biaya Baru di PDO

Gunakan prompt ini untuk membimbing user/asisten dalam membuat item biaya baru dengan akurat.

---

## 🎯 PROMPT UTAMA

```
Anda akan membantu saya membuat item biaya baru di sistem PDO (Dana Operasional Kebun).

Ikuti langkah-langkah berikut dan minta input saya di setiap tahap:

### TAHAP 1: INFORMASI DASAR
1. Tanyakan: Apa **nama item biaya** yang ingin dibuat?
   - Contoh: "Pupuk Urea 46%", "Biaya Listrik Pusat", "Konsultasi Teknis"

2. Tanyakan: Apa **kode item**-nya? (format: A1.001 atau P1.002)
   - Pastikan unik dan konsisten dengan kategori

3. Tanyakan: Pilih **sub-kategori induk**:
   - Pupuk & Bahan Kimia
   - Pestisida & Fungisida
   - Biaya Tenaga Kerja
   - Biaya Operasional
   - Jasa Profesional
   - Lainnya (sebutkan)

### TAHAP 2: DETAIL OPSIONAL
4. Tanyakan: Apa **satuan**-nya? (contoh: kg, liter, jam, orang)
   - Boleh kosongkan jika tidak perlu

5. Tanyakan: Berapa **tarif/rate** per satuan? (dalam Rp)
   - Boleh kosongkan jika belum ada

6. Tanyakan: Apa **no. akun**-nya? (contoh: 6-1001)
   - Boleh kosongkan jika belum terintegrasi akuntansi

### TAHAP 3: FITUR PENTING - ITEM RUTIN
7. Tanyakan: Apakah ini **item rutin**? (Y/N)
   - Jelaskan: Item rutin = akan otomatis muncul di PDO tanpa perlu input manual
   - Contoh kasus: Upah tetap, pemeliharaan rutin, pupuk dengan jadwal teratur
   
   **JIKA YA:**
   - Tanyakan: Berlaku untuk **semua kebun** atau **kebun tertentu saja**?
     - Jika semua: Tunggu, akan auto-apply
     - Jika tertentu: Minta user pilih mana saja (Kebun Utama, Kebun Utara, Kebun Barat, Kebun Timur)

### TAHAP 4: FITUR PENTING - SPLIT TRANSFER
8. Tanyakan: Apakah perlu fitur **split transfer**? (Y/N)
   - Jelaskan: Split transfer = bisa alokasi biaya ke 2 tujuan berbeda
   - Contoh kasus: Biaya listrik pusat yang dibagi, biaya jalan bersama, office pusat
   
   **JIKA YA:**
   - Tanyakan: Berlaku untuk **semua kebun** atau **kebun tertentu saja**?
     - Jika semua: Tunggu, akan auto-apply
     - Jika tertentu: Minta user pilih mana saja

### TAHAP 5: INFORMASI TAMBAHAN
9. Tanyakan: Apa **catatan**-nya? (opsional)
   - Gunakan untuk keterangan khusus, peringatan, atau supplier preferensi

10. Tanyakan: Apakah item ini **aktif**? (Y/N)
    - Default: YA (aktif dan bisa langsung dipakai)
    - Gunakan TIDAK jika item sudah tidak dipakai lagi

### TAHAP 6: RINGKASAN & KONFIRMASI
11. Tampilkan **RINGKASAN LENGKAP** dari semua data yang diinput
    Contoh format:
    ```
    ═════════════════════════════════════════════════════════
    RINGKASAN ITEM BIAYA YANG AKAN DIBUAT
    ═════════════════════════════════════════════════════════
    Nama Item:              Pupuk Urea 46%
    Kode:                   P1.001
    Sub-Kategori:           Pupuk & Bahan Kimia
    Satuan:                 kg
    Tarif:                  Rp 15.000/kg
    No. Akun:               (kosong)
    Mode Input:             Manual
    
    📌 FITUR KHUSUS:
    ├─ Item Rutin:          ✅ YA (untuk semua kebun)
    ├─ Split Transfer:      ❌ TIDAK
    ├─ Aktif:               ✅ YA
    └─ Catatan:             Pupuk Nitrogen, gunakan saat pengolahan tanah
    
    ═════════════════════════════════════════════════════════
    ```

12. Tanyakan: **Apakah data sudah benar?** (Y/N)
    - Jika YA → "Item siap disimpan! Klik tombol SIMPAN di form PDO"
    - Jika TIDAK → "Item mana yang ingin diubah? Masukkan nomor tahap (1-10)"

### TAHAP 7: GUIDANCE PENGGUNAAN
13. Berikan guidance penggunaan item setelah dibuat:
    - Jika Item Rutin: "Item ini akan otomatis muncul di PDO baru. Tinggal isi jumlah biayanya."
    - Jika Split Transfer: "Saat entry transfer, pilih mode split untuk alokasi ke 2 kebun."
    - Jika keduanya: "Item akan auto-muncul DAN bisa di-split saat transfer."

### TIPS AKHIR
14. Ingatkan best practices:
    ✅ Gunakan kode yang konsisten (P=Pupuk, O=Operasional, J=Jasa)
    ✅ Isi satuan & tarif untuk estimasi biaya yang akurat
    ✅ Gunakan "Item Rutin" untuk biaya berulang/periodik
    ✅ Gunakan "Split Transfer" untuk biaya bersama yang perlu dialokasi
    ✅ Jangan duplikat kode item
    ❌ Jangan unchecked "Aktif" jika belum yakin, ubah tarif ke 0 dulu
```

---

## 💡 CONTOH EKSEKUSI PROMPT

### Skenario 1: Membuat Item "Pupuk Urea" (Rutin)

```
ASISTEN:
"Halo! Saya siap membantu Anda membuat item biaya baru di PDO.

TAHAP 1: INFORMASI DASAR
─────────────────────────
1️⃣ Apa nama item biaya yang ingin dibuat?
   (Contoh: Pupuk Urea 46%, Pestisida Dekamethrin, Upah Panen)"

USER: "Pupuk Urea 46%"

ASISTEN:
"Bagus! Nama: 'Pupuk Urea 46%'

2️⃣ Apa kode item-nya?
   (Format: 3-4 karakter + nomor, contoh: P1.001, P1.002)"

USER: "P1.001"

ASISTEN:
"Kode: 'P1.001' ✓

3️⃣ Pilih sub-kategori induk:
   • Pupuk & Bahan Kimia
   • Pestisida & Fungisida
   • Biaya Tenaga Kerja
   • Biaya Operasional
   • Jasa Profesional
   • Lainnya"

USER: "Pupuk & Bahan Kimia"

[... lanjut tahap 2-7 ...]

RINGKASAN:
═════════════════════════════════════════════════════════
RINGKASAN ITEM BIAYA YANG AKAN DIBUAT
═════════════════════════════════════════════════════════
Nama Item:              Pupuk Urea 46%
Kode:                   P1.001
Sub-Kategori:           Pupuk & Bahan Kimia
Satuan:                 kg
Tarif:                  Rp 15.000/kg
No. Akun:               -
Mode Input:             Manual

📌 FITUR KHUSUS:
├─ Item Rutin:          ✅ YA (untuk semua kebun)
├─ Split Transfer:      ❌ TIDAK
├─ Aktif:               ✅ YA
└─ Catatan:             Pupuk Nitrogen, pengolahan tanah awal musim

═════════════════════════════════════════════════════════

ASISTEN:
"Apakah data sudah benar? (Y/N)"
```

---

### Skenario 2: Membuat Item "Biaya Listrik Pusat" (Split Transfer)

```
[Proses sama seperti Skenario 1, tapi:
- Nama: Biaya Listrik Gardu Utama
- Kode: O1.005
- Sub-Kategori: Biaya Operasional
- Item Rutin: TIDAK
- Split Transfer: YA (untuk semua kebun)
- Satuan: kWh (opsional)
- Tarif: kosong
]

ASISTEN (saat guided split transfer):
"📌 Fitur Split Transfer Detected!

Penjelasan: Dengan split transfer, Anda bisa alokasikan biaya ini ke multiple kebun.
Contoh: Jika biaya listrik Rp 10.000.000, bisa split 60% ke Kebun Utama + 40% ke Kebun Utara.

Berlaku untuk:
  ☑ Semua Kebun (default - semua kebun bisa menggunakan split mode untuk item ini)
  ☐ Kebun Tertentu (pilih: Kebun Utama, Kebun Utara, Kebun Barat, Kebun Timur)

Mana yang dipilih?"

USER: "Semua Kebun"

ASISTEN:
"Bagus! Split Transfer berlaku untuk semua kebun. 
Nanti saat entry transfer, user bisa pilih mode:
  • Transfer normal: 100% ke 1 kebun
  • Transfer split: Bagi ke 2 kebun dengan persentase berbeda"
```

---

## 📋 TEMPLATE PERTANYAAN DINAMIS

Gunakan pertanyaan-pertanyaan ini sesuai kebutuhan:

### Untuk Klarifikasi Item Rutin:
```
"Apakah '{nama_item}' ini biaya yang berulang setiap bulan/periode?
Contoh biaya rutin: upah tetap, sewa alat kontrak, pemeliharaan rutin.
Jawab Y jika YA, N jika TIDAK"
```

### Untuk Klarifikasi Split Transfer:
```
"Apakah '{nama_item}' ini biaya yang perlu dialokasikan ke multiple kebun?
Contoh split: listrik pusat (dibagi semua kebun), jalan bersama, office pusat.
Jawab Y jika YA, N jika TIDAK"
```

### Untuk Validasi Duplikat:
```
"⚠️ Perhatian: Kode 'P1.001' mungkin sudah ada!
Apakah yakin ingin gunakan kode ini? (Y/N)"
```

---

## 🚀 CARA MENGGUNAKAN PROMPT INI

1. **Copy semua teks di atas** (dari "PROMPT UTAMA" hingga sebelum bagian ini)
2. **Paste ke chat AI / asisten** Anda
3. **Mulai interaksi:** Asisten akan membimbing Anda step-by-step
4. **Jawab setiap pertanyaan** dengan jelas
5. **Verifikasi ringkasan** sebelum simpan

---

## ✅ CHECKLIST SEBELUM KLIK SIMPAN

- [ ] Nama item sudah diisi (tidak kosong)
- [ ] Kode item sudah diisi (format A1.001 atau similar)
- [ ] Sub-kategori sudah dipilih
- [ ] Satuan & Tarif diisi (jika applicable)
- [ ] Sudah decide Item Rutin: YA atau TIDAK
- [ ] Sudah decide Split Transfer: YA atau TIDAK
- [ ] Sudah decide Aktif: YA atau TIDAK
- [ ] Catatan sudah diisi (opsional tapi recommended)
- [ ] Ringkasan sudah dicek: BENAR atau PERLU DIUBAH
- [ ] Siap klik tombol SIMPAN di form PDO

---

**Versi Prompt:** 1.0  
**Kompatibel dengan:** User Manual Item Biaya v1.0  
**Update:** 24 Juni 2026
