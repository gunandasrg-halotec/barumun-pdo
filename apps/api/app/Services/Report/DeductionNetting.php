<?php

namespace App\Services\Report;

/**
 * Aturan tunggal untuk menetkan item Potongan Panjar (is_deduction) terhadap
 * realisasi, dipakai bersama oleh Rekap Buku Kas, Buku Kas Harian, Dashboard,
 * dan Daftar PDO supaya keempatnya selalu menampilkan angka yang sama.
 *
 * LATAR: Potongan Panjar = uang muka yang SUDAH dibayarkan periode sebelumnya,
 * direpresentasikan sebagai TransferEntry NEGATIF (tidak pernah punya
 * RealizationEntry). Kerani tetap mencatat realisasi PENUH sesuai anggaran —
 * termasuk bagian yang dananya berasal dari panjar itu — sehingga realisasi
 * mentah melebihi kas yang benar-benar keluar BARU periode ini. Selisihnya
 * persis sebesar potongan, jadi potongan dikurangkan dari realisasi.
 *
 * TAPI hanya SEBATAS realisasi yang sudah benar-benar tercatat. Dulu kredit
 * potongan diberikan penuh tanpa syarat, sehingga saat PDO baru final dan
 * belum ada realisasi sama sekali, Realisasi tampil negatif dan Saldo naik
 * keliru (mis. PDO Agustus SS: transfer bersih 4.394.864, potongan 4.500.000
 * belum direalisasi, Saldo tampil 8.894.864 padahal seharusnya 4.394.864).
 *
 * Sempat "diperbaiki" dengan menghapus netting sepenuhnya — itu justru merusak
 * periode yang realisasinya sudah lengkap (PDO Juli: KP jadi -7.026.778 dari
 * seharusnya 4.073.222, SS jadi -4.499.827 dari seharusnya 173). Clamp di bawah
 * memenuhi kedua kasus sekaligus.
 */
class DeductionNetting
{
    /**
     * Realisasi yang dilaporkan (posisi kas), setelah dinetkan dengan potongan
     * namun dibatasi agar kredit potongan tidak pernah melebihi realisasi yang
     * benar-benar ada.
     *
     * @param  int  $rawRealization  total realisasi mentah dalam 1 (PDO, kantong)
     * @param  int  $deduction       total transfer item potongan — NEGATIF — dalam (PDO, kantong) yang sama
     */
    public static function effectiveRealization(int $rawRealization, int $deduction): int
    {
        return max(0, $rawRealization + $deduction);
    }

    /**
     * Besarnya kredit potongan yang benar-benar terpakai (NEGATIF atau 0).
     * Dipakai saat kredit perlu dibagikan ke baris/grup tampilan, supaya
     * penjumlahan baris tetap sama dengan KPI.
     */
    public static function usableCredit(int $rawRealization, int $deduction): int
    {
        return max($deduction, -$rawRealization);
    }
}
