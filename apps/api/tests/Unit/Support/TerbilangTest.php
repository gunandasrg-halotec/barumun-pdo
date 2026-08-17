<?php

namespace Tests\Unit\Support;

use App\Support\Terbilang;
use PHPUnit\Framework\TestCase;

class TerbilangTest extends TestCase
{
    public function test_nol(): void
    {
        $this->assertEquals('Nol Rupiah', Terbilang::rupiah(0));
    }

    public function test_satuan(): void
    {
        $this->assertEquals('Satu Rupiah', Terbilang::rupiah(1));
        $this->assertEquals('Sembilan Rupiah', Terbilang::rupiah(9));
    }

    public function test_sepuluh_dan_sebelas(): void
    {
        $this->assertEquals('Sepuluh Rupiah', Terbilang::rupiah(10));
        $this->assertEquals('Sebelas Rupiah', Terbilang::rupiah(11));
    }

    public function test_belasan(): void
    {
        $this->assertEquals('Dua Belas Rupiah', Terbilang::rupiah(12));
        $this->assertEquals('Lima Belas Rupiah', Terbilang::rupiah(15));
        $this->assertEquals('Sembilan Belas Rupiah', Terbilang::rupiah(19));
    }

    public function test_puluhan(): void
    {
        $this->assertEquals('Dua Puluh Rupiah', Terbilang::rupiah(20));
        $this->assertEquals('Dua Puluh Satu Rupiah', Terbilang::rupiah(21));
        $this->assertEquals('Sembilan Puluh Sembilan Rupiah', Terbilang::rupiah(99));
    }

    public function test_ratusan(): void
    {
        $this->assertEquals('Seratus Rupiah', Terbilang::rupiah(100));
        $this->assertEquals('Seratus Sepuluh Rupiah', Terbilang::rupiah(110));
        $this->assertEquals('Dua Ratus Rupiah', Terbilang::rupiah(200));
        $this->assertEquals('Sembilan Ratus Sembilan Puluh Sembilan Rupiah', Terbilang::rupiah(999));
    }

    public function test_ribuan(): void
    {
        $this->assertEquals('Seribu Rupiah', Terbilang::rupiah(1000));
        $this->assertEquals('Seribu Lima Ratus Rupiah', Terbilang::rupiah(1500));
        $this->assertEquals('Dua Ribu Rupiah', Terbilang::rupiah(2000));
        $this->assertEquals('Dua Puluh Satu Ribu Rupiah', Terbilang::rupiah(21000));
    }

    public function test_jutaan(): void
    {
        $this->assertEquals('Satu Juta Rupiah', Terbilang::rupiah(1_000_000));
        $this->assertEquals('Seratus Lima Juta Rupiah', Terbilang::rupiah(105_000_000));
        $this->assertEquals(
            'Satu Juta Dua Ratus Tiga Puluh Empat Ribu Lima Ratus Enam Puluh Tujuh Rupiah',
            Terbilang::rupiah(1_234_567)
        );
    }

    public function test_miliaran(): void
    {
        $this->assertEquals('Satu Miliar Rupiah', Terbilang::rupiah(1_000_000_000));
    }

    public function test_negatif(): void
    {
        $this->assertEquals('Minus Lima Ribu Rupiah', Terbilang::rupiah(-5000));
    }

    public function test_tidak_ada_spasi_ganda(): void
    {
        $this->assertStringNotContainsString('  ', Terbilang::rupiah(1_234_567));
        $this->assertStringNotContainsString('  ', Terbilang::rupiah(105_000_000));
        $this->assertStringNotContainsString('  ', Terbilang::rupiah(1000));
    }
}
