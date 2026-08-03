<?php

namespace App\Services\PDO;

use App\Exceptions\PdoNotFinalException;
use App\Models\AuditLog;
use App\Models\PdoHeader;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PdoCloseService
{
    /**
     * Tutup PDO secara manual oleh MANAJER_KEUANGAN.
     * BR-CLOSE-002
     */
    public function closeManual(string $pdoId, User $actor, array $data): PdoHeader
    {
        $pdo = PdoHeader::findOrFail($pdoId);

        // BR-CLOSE-002: hanya MANAJER_KEUANGAN
        if (! $actor->hasRole(Role::MANAJER_KEUANGAN)) {
            throw new AuthorizationException('Hanya Manajer Keuangan yang dapat menutup PDO.');
        }

        // BR-CLOSE-001: PDO harus berstatus final
        if (! $pdo->isFinal()) {
            throw new PdoNotFinalException($pdo->status);
        }

        // BR-CLOSE-002: tanggal penutupan tidak boleh mundur ke belakang.
        //
        // Batas "tidak boleh melewati akhir bulan periode PDO" sengaja DIHAPUS: ia
        // bertentangan dengan aturan ">= hari ini" begitu bulan periode terlewat,
        // sehingga rentang yang sah menjadi himpunan kosong dan PDO mustahil ditutup
        // manual. Contoh: menutup PDO Juli pada 3 Agustus butuh tanggal >= 3 Agustus
        // sekaligus <= 31 Juli. Penutupan memang lazim terjadi setelah periodenya
        // berakhir — job auto-close pun mencatat waktu jalannya (tanggal 1 bulan
        // berikutnya), yang juga melanggar batas lama tersebut.
        $closedDate = Carbon::parse($data['closed_date']);
        $today      = Carbon::today();

        if ($closedDate->lt($today)) {
            throw ValidationException::withMessages([
                'closed_date' => ['Tanggal penutupan tidak boleh sebelum hari ini.'],
            ]);
        }

        $oldValues = [
            'status'       => $pdo->status,
            'closed_by'    => $pdo->closed_by,
            'closed_at'    => $pdo->closed_at,
            'closure_type' => $pdo->closure_type,
        ];

        DB::transaction(function () use ($pdo, $actor, $data, $closedDate) {
            $pdo->update([
                'status'        => PdoHeader::STATUS_CLOSED,
                'closed_by'     => $actor->id,
                'closed_at'     => $closedDate->endOfDay(),
                'closure_type'  => 'manual',
                'closure_notes' => $data['closure_notes'] ?? null,
            ]);

            // BR-CLOSE-004: audit log penutupan manual
            AuditLog::record(
                actor: $actor,
                entityType: 'pdo_headers',
                entityId: $pdo->id,
                action: 'CLOSE',
                oldValues: ['status' => 'final'],
                newValues: [
                    'closure_type'  => 'manual',
                    'closed_date'   => $closedDate->toDateString(),
                    'closure_notes' => $data['closure_notes'] ?? null,
                    'closed_by'     => $actor->id,
                ],
            );
        });

        return $pdo->fresh(['closer']);
    }

    /**
     * Tutup semua PDO final milik periode BULAN LALU.
     * Dipanggil oleh Artisan command pdo:auto-close, dijadwalkan setiap
     * tanggal 1 pukul 01:00 WIB (lihat bootstrap/app.php).
     *
     * BR-CLOSE-001. Penutupan sengaja dilakukan di awal bulan berikutnya
     * (bukan di detik-detik akhir bulan berjalan) supaya kerani punya
     * kelonggaran waktu memasukkan realisasi terakhir sampai hari terakhir
     * bulan itu benar-benar habis.
     */
    public function closeAutomatic(): int
    {
        $now = Carbon::now('Asia/Jakarta');

        // Jalankan hanya pada tanggal 1 — pengaman bila scheduler terlambat
        // atau command dipanggil manual di hari lain.
        if ($now->day !== 1) {
            Log::info('[AutoClose] Bukan tanggal 1, tidak ada PDO yang ditutup.');
            return 0;
        }

        // Target = periode bulan sebelumnya (mis. dijalankan 1 Agustus → tutup Juli).
        $target = $now->copy()->subMonthNoOverflow();

        $pdos = PdoHeader::where('status', PdoHeader::STATUS_FINAL)
            ->where('period_year', $target->year)
            ->where('period_month', $target->month)
            ->get();

        Log::info("[AutoClose] Target periode {$target->month}/{$target->year}: {$pdos->count()} PDO final ditemukan.");

        $closedCount = 0;

        foreach ($pdos as $pdo) {
            try {
                DB::transaction(function () use ($pdo, $now) {
                    $pdo->update([
                        'status'        => PdoHeader::STATUS_CLOSED,
                        'closed_by'     => null,
                        'closed_at'     => $now,
                        'closure_type'  => 'system',
                        'closure_notes' => null,
                    ]);

                    // BR-CLOSE-004: audit log auto-close — actor NULL = SYSTEM
                    AuditLog::record(
                        actor: null,
                        entityType: 'pdo_headers',
                        entityId: $pdo->id,
                        action: 'CLOSE',
                        oldValues: ['status' => 'final'],
                        newValues: [
                            'closure_type' => 'system',
                            'closed_at'    => $now->toIso8601String(),
                        ],
                    );
                });

                $closedCount++;
                Log::info("[AutoClose] PDO {$pdo->id} berhasil ditutup secara otomatis.");
            } catch (\Throwable $e) {
                Log::error("[AutoClose] Gagal menutup PDO {$pdo->id}: {$e->getMessage()}");
            }
        }

        Log::info("[AutoClose] Selesai. Total PDO ditutup: {$closedCount}");
        return $closedCount;
    }
}
