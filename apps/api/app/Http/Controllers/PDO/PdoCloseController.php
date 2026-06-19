<?php

namespace App\Http\Controllers\PDO;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PdoHeader;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PdoCloseController extends Controller
{
    /**
     * Tutup PDO yang sudah Final (status: final → closed).
     * Hanya MANAJER_KEUANGAN atau DIREKTUR_KEUANGAN.
     */
    public function close(Request $request, PdoHeader $pdo): JsonResponse
    {
        if (! $request->user()->hasAnyRole([Role::MANAJER_KEUANGAN, Role::DIREKTUR_KEUANGAN])) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'FORBIDDEN', 'message' => 'Hanya Manajer/Direktur Keuangan yang bisa menutup PDO.'],
            ], 403);
        }

        if (! $pdo->isFinal()) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'INVALID_STATUS', 'message' => 'Hanya PDO berstatus final yang bisa ditutup.'],
            ], 409);
        }

        $request->validate([
            'closure_type'  => ['required', 'in:system,manual'],
            'closure_notes' => ['nullable', 'string'],
        ]);

        $old = $pdo->toArray();
        $pdo->update([
            'status'        => PdoHeader::STATUS_CLOSED,
            'closed_by'     => $request->user()->id,
            'closed_at'     => now(),
            'closure_type'  => $request->input('closure_type'),
            'closure_notes' => $request->input('closure_notes'),
        ]);

        AuditLog::append(
            actor: $request->user(),
            entityType: 'pdo_headers',
            entityId: $pdo->id,
            action: 'STATUS_CHANGE',
            oldValues: $old,
            newValues: $pdo->fresh()->toArray()
        );

        return response()->json(['success' => true, 'data' => $pdo->fresh(), 'message' => 'PDO berhasil ditutup.']);
    }
}
