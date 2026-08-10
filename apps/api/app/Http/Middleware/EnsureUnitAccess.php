<?php

namespace App\Http\Middleware;

use App\Models\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Row-level security: Kerani dan Asisten Kebun hanya bisa
 * mengakses data unit kebun mereka sendiri.
 *
 * TAD 5.2 — Authorization: Row-Level Security
 * BRD — Matriks Hak Akses Role per Halaman
 */
class EnsureUnitAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'FORBIDDEN', 'message' => 'Akses tidak diizinkan.'],
            ], 403);
        }

        // Role lintas unit: tidak perlu filter, lanjutkan
        if (in_array($user->role?->code, Role::CROSS_UNIT_ROLES)) {
            return $next($request);
        }

        // Kerani & Asisten: bind daftar unit_id (unit sendiri + unit yang di-link,
        // mis. "Sosa Replanting" di-link dari "Sosa") ke container untuk Global Scope.
        if ($user->plantation_unit_id) {
            $unitIds = array_values(array_filter([
                $user->plantation_unit_id,
                $user->plantationUnit?->linked_unit_id,
            ]));
            app()->instance('current_unit_ids', $unitIds);
        }

        return $next($request);
    }
}
