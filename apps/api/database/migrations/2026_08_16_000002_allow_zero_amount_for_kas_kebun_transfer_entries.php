<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PDO Tambahan "Gunakan Kas Kebun": dananya diambil dari saldo kas kebun yang SUDAH
 * ada, tidak ada transfer baru dari HO. TransferEntry auto-generated-nya tetap dibuat
 * (lihat PdoSupplementaryApprovalService::mergeIntoParent()) karena dipakai sebagai
 * penanda bahwa item ini milik kantong rek_kebun — tanpa entri itu,
 * RealizationEntryService::availableItemsForActor() tidak akan memunculkannya di
 * dropdown realisasi. Tapi NOMINALNYA harus 0, bukan sebesar pengajuan.
 *
 * Mengisi nominal sebesar pengajuan (perilaku lama) membuat "dana yang ditransfer"
 * tampak bertambah padahal tidak ada uang masuk: KPI Transfer di Rekap dan kolom
 * "Sudah Ditransfer" di halaman Detail Transfer ikut menggelembung, sehingga tiap
 * halaman pelaporan harus menambal dengan logika pengecualian sendiri-sendiri.
 * Dengan nominal 0, backend dan tampilan otomatis sinkron tanpa tambalan.
 *
 * Constraint lama melarang 0 untuk SEMUA baris. Dilonggarkan: transfer manual tetap
 * wajib > 0, entri auto-generated bebas (0 untuk kas kebun, negatif untuk potongan).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE transfer_entries DROP CONSTRAINT IF EXISTS chk_transfer_entries_amount');
            DB::statement('ALTER TABLE transfer_entries ADD CONSTRAINT chk_transfer_entries_amount CHECK (is_auto_generated OR amount > 0)');
        }

        // Backfill entri kas kebun lama (dibuat sebelum perubahan ini) jadi 0 supaya
        // PDO existing ikut konsisten. Dibatasi ke entri auto-generated ke rek_kebun
        // milik pdo_details ber-funding_option kas_kebun — entri transfer HO biasa
        // dan entri potongan negatif tidak tersentuh.
        DB::table('transfer_entries')
            ->whereIn('pdo_detail_id', function ($q) {
                $q->select('id')->from('pdo_details')->where('funding_option', 'kas_kebun');
            })
            ->where('is_auto_generated', true)
            ->where('transfer_destination', 'rek_kebun')
            ->update(['amount' => 0]);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        // Kembalikan nominal = anggaran item DULU, supaya constraint lama
        // (amount <> 0) tidak langsung gagal saat dipasang kembali.
        DB::statement("
            UPDATE transfer_entries te
            SET amount = pd.amount
            FROM pdo_details pd
            WHERE pd.id = te.pdo_detail_id
              AND pd.funding_option = 'kas_kebun'
              AND te.is_auto_generated = TRUE
              AND te.transfer_destination = 'rek_kebun'
        ");

        DB::statement('ALTER TABLE transfer_entries DROP CONSTRAINT IF EXISTS chk_transfer_entries_amount');
        DB::statement('ALTER TABLE transfer_entries ADD CONSTRAINT chk_transfer_entries_amount CHECK (amount <> 0 AND (is_auto_generated OR amount > 0))');
    }
};
