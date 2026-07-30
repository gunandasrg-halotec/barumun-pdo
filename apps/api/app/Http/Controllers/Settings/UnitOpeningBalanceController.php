<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\PlantationUnit;
use App\Models\Role;
use App\Models\UnitOpeningBalance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UnitOpeningBalanceController extends Controller
{
    /** GET /unit-opening-balances — daftar saldo awal per unit kebun (company scope). */
    public function index(Request $request): JsonResponse
    {
        $this->authorize($request);

        $units = PlantationUnit::where('company_id', $request->user()->company_id)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $balances = UnitOpeningBalance::whereIn('plantation_unit_id', $units->pluck('id'))
            ->get()
            ->keyBy('plantation_unit_id');

        $data = $units->map(function ($unit) use ($balances) {
            $balance = $balances->get($unit->id);

            return [
                'plantation_unit_id' => $unit->id,
                'unit_code'          => $unit->code,
                'unit_name'          => $unit->name,
                'amount'             => (int) ($balance?->amount ?? 0),
                'as_of_date'         => $balance?->as_of_date?->toDateString(),
                'notes'              => $balance?->notes,
                'updated_at'         => $balance?->updated_at?->toIso8601String(),
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    /** PUT /unit-opening-balances/{unit} — set/update saldo awal 1 unit kebun. */
    public function update(Request $request, string $unit): JsonResponse
    {
        $this->authorize($request);

        $plantationUnit = PlantationUnit::where('company_id', $request->user()->company_id)
            ->findOrFail($unit);

        $data = $request->validate([
            'amount'     => ['required', 'integer'],
            'as_of_date' => ['required', 'date'],
            'notes'      => ['nullable', 'string', 'max:1000'],
        ]);

        $balance = UnitOpeningBalance::updateOrCreate(
            ['plantation_unit_id' => $plantationUnit->id],
            [
                'amount'     => $data['amount'],
                'as_of_date' => $data['as_of_date'],
                'notes'      => $data['notes'] ?? null,
                'updated_by' => $request->user()->id,
            ]
        );

        return response()->json(['success' => true, 'data' => $balance, 'message' => 'Saldo awal berhasil disimpan.']);
    }

    private function authorize(Request $request): void
    {
        $allowed = [Role::MANAJER_KEUANGAN, Role::DIREKTUR_KEUANGAN];

        if (! in_array($request->user()->role?->code, $allowed, true)) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'FORBIDDEN', 'message' => 'Hanya Manajer Keuangan / Direktur Keuangan yang dapat mengubah saldo awal kas kebun.'],
            ], 403));
        }
    }
}
