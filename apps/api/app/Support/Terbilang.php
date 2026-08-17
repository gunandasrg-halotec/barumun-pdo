<?php

namespace App\Support;

final class Terbilang
{
    private const SATUAN = [
        '', 'Satu', 'Dua', 'Tiga', 'Empat', 'Lima', 'Enam', 'Tujuh', 'Delapan', 'Sembilan',
        'Sepuluh', 'Sebelas',
    ];

    public static function rupiah(int $n): string
    {
        if ($n === 0) {
            return 'Nol Rupiah';
        }

        if ($n < 0) {
            return 'Minus ' . self::rupiah(-$n);
        }

        return trim(self::convert($n)) . ' Rupiah';
    }

    private static function convert(int $n): string
    {
        if ($n === 0) {
            return '';
        }

        if ($n < 12) {
            return self::SATUAN[$n];
        }

        if ($n < 20) {
            return trim(self::convert($n - 10) . ' Belas');
        }

        if ($n < 100) {
            return trim(self::convert(intdiv($n, 10)) . ' Puluh ' . self::convert($n % 10));
        }

        if ($n < 200) {
            return trim('Seratus ' . self::convert($n - 100));
        }

        if ($n < 1000) {
            return trim(self::convert(intdiv($n, 100)) . ' Ratus ' . self::convert($n % 100));
        }

        if ($n < 2000) {
            return trim('Seribu ' . self::convert($n - 1000));
        }

        if ($n < 1_000_000) {
            return trim(self::convert(intdiv($n, 1000)) . ' Ribu ' . self::convert($n % 1000));
        }

        if ($n < 1_000_000_000) {
            return trim(self::convert(intdiv($n, 1_000_000)) . ' Juta ' . self::convert($n % 1_000_000));
        }

        if ($n < 1_000_000_000_000) {
            return trim(self::convert(intdiv($n, 1_000_000_000)) . ' Miliar ' . self::convert($n % 1_000_000_000));
        }

        return trim(self::convert(intdiv($n, 1_000_000_000_000)) . ' Triliun ' . self::convert($n % 1_000_000_000_000));
    }
}
