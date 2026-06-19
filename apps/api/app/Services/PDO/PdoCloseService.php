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

        // BR-CLOSE-002: validasi tanggal — >= hari ini && <= hari terakhir bulan periode
        $closedDate  = Carbon::parse($data['closed_date']);
        $today       = Carbon::today();
        $lastDayOfPeriod = Carbon::create($pdo->period_year, $pdo->period_month)->endOfMonth()->startOfDay();

        if ($closedDate->lt($today)) {
            throw ValidationException::withMessages([
                'closed_date' => ['Tanggal penutupan tidak boleh sebelum hari ini.'],
            ]);
        }

        if ($closedDate->gt($lastDayOfPeriod)) {
            throw ValidationException::withMessages([
                'closed_date' => ['Tanggal penutupan tidak boleh melebihi akhir bulan periode PDO.'],
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
     * Tutup semua PDO final yang periode-nya berakhir hari ini.
     * Dipanggil oleh Artisan command pdo:auto-close.
     * BR-CLOSE-001
     */
    public function closeAutomatic(): int
    {
        $today = Carbon::now('Asia/Jakarta');

        // BR-CLOSE-001: jalankan hanya pada hari terakhir bulan
        $lastDayOfMonth = $today->copy()->endOfMonth()->day;
        if ($today->day !== $lastDayOfMonth) {
            Log::info('[AutoClose] Bukan hari terakhir bulan, tidak ada PDO yang ditutup.');
            return 0;
        }

        $pdos = PdoHeader::where('status', PdoHeader::STATUS_FINAL)
            ->where('period_year', $today->year)
            ->where('period_month', $today->month)
            ->get();

        $closedCount = 0;

        foreach ($pdos as $pdo) {
            try {
                DB::transaction(function () use ($pdo, $today) {
                    $pdo->update([
                        'status'        => PdoHeader::STATUS_CLOSED,
                        'closed_by'     => null,
                        'closed_at'     => $today,
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
                            'closed_at'    => $today->toIso8601String(),
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
