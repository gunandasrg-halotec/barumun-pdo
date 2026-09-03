<?php

namespace Database\Factories;

use App\Models\PdoDetail;
use App\Models\TransferEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

class TransferEntryFactory extends Factory
{
    protected $model = TransferEntry::class;

    public function definition(): array
    {
        return [
            'pdo_detail_id'    => PdoDetail::factory(),
            'recorded_by'      => User::factory(),
            'entry_source'     => TransferEntry::SOURCE_MANUAL,
            'is_auto_generated'=> false,
            'transfer_date'    => now()->toDateString(),
            'amount'           => $this->faker->numberBetween(100000, 5000000),
            'reference_number' => 'TRF-' . $this->faker->numerify('######'),
            'notes'            => null,
        ];
    }

    /**
     * Tanggal transfer WAJIB berada di dalam periode PDO-nya — di produksi memang
     * selalu begitu (diverifikasi: nol transfer bertanggal sebelum awal periode
     * PDO-nya). Kalau tanggalnya jatuh SEBELUM awal periode, transfer itu ikut
     * terhitung sebagai SALDO AWAL kumulatif (CashBookQueryService::
     * cumulativeBalanceBefore()) SEKALIGUS sebagai transfer periode ini, sehingga
     * plafon kantong (RealizationEntryService::remainingKantongForGroup()) dan
     * kolom Saldo Daftar PDO jadi dobel.
     *
     * Default `now()` di definition() bertabrakan dengan PdoHeaderFactory yang
     * memilih bulan/tahun ACAK 2024-2026: begitu periodenya jatuh setelah hari ini,
     * test yang menguji plafon/saldo gagal secara acak. Hook ini menarik tanggal yang
     * di LUAR periode ke awal periode, jadi test tidak perlu mengingat memasang
     * transfer_date sendiri. Tanggal yang sudah berada di dalam periode — termasuk
     * yang di-set eksplisit oleh test — tidak disentuh.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (TransferEntry $entry) {
            $header = PdoDetail::withoutGlobalScopes()
                ->with('pdoHeader')
                ->find($entry->pdo_detail_id)?->pdoHeader;

            if (! $header) {
                return;
            }

            $start = Carbon::createFromDate($header->period_year, $header->period_month, 1)->startOfMonth();
            $date  = Carbon::parse($entry->transfer_date);

            if ($date->lt($start) || $date->gt($start->copy()->endOfMonth())) {
                $entry->transfer_date = $start->toDateString();
            }
        });
    }
}
