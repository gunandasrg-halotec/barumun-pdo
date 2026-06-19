<?php

namespace App\Http\Middleware;

use App\Models\PdoHeader;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPdoStatus
{
    /**
     * Blokir write operations pada PDO yang sudah closed.
     * BR-CLOSE-003: Setelah closed tidak bisa tambah/edit realisasi atau transfer.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Hanya blokir write operations
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'])) {
            return $next($request);
        }

        // Cari PDO dari route parameter (bisa 'pdo' atau 'id')
        $pdoId = $request->route('pdo') ?? $request->route('id');

        if (! $pdoId) {
            return $next($request);
        }

        $pdoId = $pdoId instanceof PdoHeader ? $pdoId->id : $pdoId;
        $pdo   = PdoHeader::find($pdoId);

        if (! $pdo || ! $pdo->isClosed()) {
            return $next($request);
        }

        // BR-CLOSE-003: kembalikan error PDO_IS_CLOSED
        $closedAt = $pdo->closed_at?->setTimezone('Asia/Jakarta')->format('d/m/Y');

        return response()->json([
            'success' => false,
            'error'   => [
                'code'    => 'PDO_IS_CLOSED',
                'message' => "PDO ini sudah ditutup pada {$closedAt} dan tidak dapat diubah.",
            ],
        ], 422);
    }
}
