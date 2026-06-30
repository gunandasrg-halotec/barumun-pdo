<?php

namespace App\Services\PDO;

use App\Models\AuditLog;
use App\Models\ExpenseItem;
use App\Models\PdoDetail;
use App\Models\PdoHeader;
use App\Models\PlantationUnit;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PdoService
{
    // ─────────────────────────────────────────────────────
    // PDO HEADER
    // ─────────────────────────────────────────────────────

    public function listPdo(array $filters = []): LengthAwarePaginator
    {
        return PdoHeader::with(['plantationUnit', 'creator'])
            ->addSelect([
                'total_amount' => \App\Models\PdoDetail::selectRaw('COALESCE(SUM(amount), 0)')
                    ->whereColumn('pdo_header_id', 'pdo_headers.id'),
                'total_transferred' => \DB::table('transfer_entries')
                    ->selectRaw('COALESCE(SUM(transfer_entries.amount), 0)')
                    ->join('pdo_details', 'pdo_details.id', '=', 'transfer_entries.pdo_detail_id')
                    ->whereColumn('pdo_details.pdo_header_id', 'pdo_headers.id'),
                'total_realized' => \DB::table('realization_entries')
                    ->selectRaw('COALESCE(SUM(realization_entries.amount), 0)')
                    ->join('pdo_details', 'pdo_details.id', '=', 'realization_entries.pdo_detail_id')
                    ->whereColumn('pdo_details.pdo_header_id', 'pdo_headers.id'),
            ])
            ->when(!empty($filters['search']), fn ($q) => $q->where('pdo_number', 'ilike', '%' . $filters['search'] . '%'))
            ->when(!empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(!empty($filters['period_year']), fn ($q) => $q->where('period_year', $filters['period_year']))
            ->when(!empty($filters['period_month']), fn ($q) => $q->where('period_month', $filters['period_month']))
            ->when(!empty($filters['plantation_unit_id']), fn ($q) => $q->where('plantation_unit_id', $filters['plantation_unit_id']))
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->paginate(20);
    }

    public function findPdo(string $id): PdoHeader
    {
        $pdo = PdoHeader::with(['plantationUnit', 'creator', 'details.expenseItem'])->findOrFail($id);
        $this->hydrateDetailsExternalState($pdo->details, $pdo);

        return $pdo;
    }

    /**
     * [E] Detail PDO dikelompokkan by Kategori → Sub-Kategori → Item.
     * Digunakan oleh show() untuk response hierarkis ke frontend.
     */
    public function findPdoGrouped(string $id): array
    {
        $pdo = PdoHeader::with([
            'plantationUnit',
            'creator',
            'details.expenseItem.subcategory.category',
        ])->findOrFail($id);

        $this->hydrateDetailsExternalState($pdo->details, $pdo);

        // Group details by kategori → sub-kategori
        $grouped = [];
        foreach ($pdo->details as $detail) {
            $sub = $detail->expenseItem?->subcategory;
            $cat = $sub?->category;

            $catKey = $cat?->id ?? 'uncategorized';
            $subKey = $sub?->id ?? 'uncategorized';

            if (! isset($grouped[$catKey])) {
                $grouped[$catKey] = [
                    'category'       => $cat ? $cat->only(['id', 'code', 'name', 'display_order']) : null,
                    'subcategories'  => [],
                    'subtotal_amount'=> 0,
                ];
            }
            if (! isset($grouped[$catKey]['subcategories'][$subKey])) {
                $grouped[$catKey]['subcategories'][$subKey] = [
                    'subcategory'    => $sub ? $sub->only(['id', 'code', 'name', 'display_order']) : null,
                    'details'        => [],
                    'subtotal_amount'=> 0,
                ];
            }

            $grouped[$catKey]['subcategories'][$subKey]['details'][]        = $detail;
            $grouped[$catKey]['subcategories'][$subKey]['subtotal_amount'] += $detail->amount;
            $grouped[$catKey]['subtotal_amount']                           += $detail->amount;
        }

        // Re-index dan urutkan by display_order
        $categoriesArray = collect(array_values($grouped))
            ->map(fn ($c) => array_merge($c, ['subcategories' => array_values($c['subcategories'])]))
            ->sortBy(fn ($c) => $c['category']['display_order'] ?? 999)
            ->values()
            ->all();

        return [
            'pdo'         => $pdo->makeHidden('details'),
            'categories'  => $categoriesArray,
            'grand_total' => $pdo->details->sum('amount'),
        ];
    }

    /**
     * Buat PDO Bulanan baru + otomatis isi baris dari item rutin.
     * BR-PDO-001: Satu PDO per unit per bulan/tahun.
     * BR-PDO-002: Template otomatis dari expense_items is_routine=true.
     */
    public function createPdo(array $data, User $actor): PdoHeader
    {
        $unit = PlantationUnit::findOrFail($data['plantation_unit_id']);

        // BR-PDO-001: duplikat (unit, bulan, tahun) → error
        $exists = PdoHeader::withoutGlobalScopes()
            ->where('plantation_unit_id', $data['plantation_unit_id'])
            ->where('period_month', $data['period_month'])
            ->where('period_year', $data['period_year'])
            ->exists();

        if ($exists) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'PDO_ALREADY_EXISTS', 'message' => 'PDO untuk periode dan unit ini sudah ada.'],
            ], 409));
        }

        return DB::transaction(function () use ($data, $actor, $unit) {
            $pdo = PdoHeader::create([
                'company_id'         => $actor->company_id,
                'plantation_unit_id' => $data['plantation_unit_id'],
                'created_by'         => $actor->id,
                'pdo_number'         => PdoHeader::generateNumber($unit->code, $data['period_year'], $data['period_month']),
                'period_month'       => $data['period_month'],
                'period_year'        => $data['period_year'],
                'status'             => PdoHeader::STATUS_DRAFT,
                'notes'              => $data['notes'] ?? null,
            ]);

            // BR-PDO-002: isi template otomatis dari item rutin aktif
            $this->fillRoutineTemplate($pdo);

            AuditLog::record(
                actor: $actor,
                entityType: 'pdo_headers',
                entityId: $pdo->id,
                action: 'INSERT',
                oldValues: null,
                newValues: $pdo->toArray()
            );

            return $pdo->load(['plantationUnit', 'creator', 'details.expenseItem']);
        });
    }

    public function updatePdo(PdoHeader $pdo, array $data, User $actor): PdoHeader
    {
        // BR-PDO-003: hanya bisa edit saat draft
        $this->assertDraft($pdo);

        return DB::transaction(function () use ($pdo, $data, $actor) {
            $now     = now();
            $request = app(\Illuminate\Http\Request::class);
            $ip      = $request->ip();
            $ua      = $request->userAgent();

            // Kumpulkan semua baris audit, tulis sekaligus di akhir (bulk insert).
            $auditRows = [];
            $audit = function (string $entityType, string $entityId, string $action, ?array $old, ?array $new)
                use (&$auditRows, $actor, $ip, $ua) {
                $auditRows[] = [
                    'actor_user_id' => $actor?->id,
                    'entity_type'   => $entityType,
                    'entity_id'     => $entityId,
                    'action'        => $action,
                    'old_values'    => $old !== null ? json_encode($old) : null,
                    'new_values'    => $new !== null ? json_encode($new) : null,
                    'ip_address'    => $ip,
                    'user_agent'    => $ua,
                ];
            };

            // ── Header ────────────────────────────────────────
            $oldHeader = $this->auditAttrs($pdo);
            $pdo->update(['notes' => $data['notes'] ?? $pdo->notes]);
            $audit('pdo_headers', $pdo->id, 'UPDATE', $oldHeader, $this->auditAttrs($pdo));

            // ── Sync details: create / update / delete ─────────
            if (array_key_exists('details', $data)) {
                $sentIds = collect($data['details'])->pluck('id')->filter()->values();

                // Preload existing details (1 query), index by id.
                $existing = $pdo->details()->get()->keyBy('id');

                // DELETE: baris yang tidak ada di payload → satu bulk delete.
                $toDelete = $existing->keys()->diff($sentIds);
                if ($toDelete->isNotEmpty()) {
                    foreach ($toDelete as $delId) {
                        $audit('pdo_details', (string) $delId, 'DELETE', $this->auditAttrs($existing[$delId]), null);
                    }
                    PdoDetail::whereIn('id', $toDelete->all())->delete();
                }

                // Preload semua ExpenseItem untuk item baru (1 query).
                $newItemIds = collect($data['details'])
                    ->filter(fn ($d) => empty($d['id']))
                    ->pluck('expense_item_id')->filter()->unique();
                $items = $newItemIds->isNotEmpty()
                    ? ExpenseItem::whereIn('id', $newItemIds->all())->get()->keyBy('id')
                    : collect();

                $maxOrder = (int) ($existing->max('display_order') ?? 0);
                $inserts  = [];

                foreach ($data['details'] as $detailData) {
                    if (empty($detailData['id'])) {
                        // ── CREATE (dikumpulkan untuk bulk insert) ──
                        $item = $items[$detailData['expense_item_id']] ?? null;
                        if (! $item) continue;

                        $isExt = $item->mode_input === ExpenseItem::MODE_AUTO_EXTERNAL;
                        $id    = (string) \Illuminate\Support\Str::orderedUuid();
                        $row   = [
                            'id'                     => $id,
                            'pdo_header_id'          => $pdo->id,
                            'expense_item_id'        => $item->id,
                            'account_number'         => $item->default_account_number,
                            'description'            => $detailData['description'] ?? $item->name,
                            'quantity'               => $detailData['quantity'] ?? null,
                            'unit'                   => $detailData['unit'] ?? $item->default_unit,
                            'rate'                   => $detailData['rate'] ?? $item->default_rate,
                            'amount'                 => $detailData['amount'] ?? 0,
                            'external_source_system' => $isExt ? $item->external_source_system : null,
                            'external_component'     => $isExt ? $item->external_component : null,
                            'external_component_key' => $isExt ? $item->external_component_key : null,
                            'notes'                  => $detailData['notes'] ?? null,
                            'display_order'          => $detailData['display_order'] ?? (++$maxOrder),
                            'created_at'             => $now,
                            'updated_at'             => $now,
                        ];
                        $inserts[] = $row;
                        $audit('pdo_details', $id, 'CREATE', null, $row);
                    } else {
                        // ── UPDATE (dari data yang sudah di-preload) ──
                        $detail = $existing[$detailData['id']] ?? null;
                        if (! $detail) continue;

                        $oldDetail = $this->auditAttrs($detail);
                        $detail->fill([
                            'description'   => $detailData['description']   ?? $detail->description,
                            'quantity'      => array_key_exists('quantity', $detailData) ? $detailData['quantity'] : $detail->quantity,
                            'unit'          => array_key_exists('unit', $detailData) ? $detailData['unit'] : $detail->unit,
                            'rate'          => array_key_exists('rate', $detailData) ? $detailData['rate'] : $detail->rate,
                            'amount'        => $detailData['amount'] ?? $detail->amount,
                            'notes'         => $detailData['notes'] ?? $detail->notes,
                            'display_order' => $detailData['display_order'] ?? $detail->display_order,
                        ]);

                        // Hanya simpan + audit bila benar-benar berubah.
                        if ($detail->isDirty()) {
                            $detail->save();
                            $audit('pdo_details', $detail->id, 'UPDATE', $oldDetail, $this->auditAttrs($detail));
                        }
                    }
                }

                if (! empty($inserts)) {
                    PdoDetail::insert($inserts); // satu bulk insert
                }
            }

            $this->syncGrandTotal($pdo);

            // Tulis semua audit sekaligus.
            if (! empty($auditRows)) {
                AuditLog::insert($auditRows);
            }

            return $pdo->fresh();
        });
    }

    /**
     * Atribut model untuk audit (nilai sudah ter-cast) tanpa memicu
     * accessor `$appends` yang melakukan lazy-load query ke DB.
     */
    private function auditAttrs(\Illuminate\Database\Eloquent\Model $model): array
    {
        return (clone $model)->setAppends([])->attributesToArray();
    }

    public function deletePdo(PdoHeader $pdo, User $actor): void
    {
        // BR-PDO-003: hanya boleh hapus saat draft
        $this->assertDraft($pdo);

        $old = $pdo->toArray();
        $pdo->delete();

        AuditLog::record(
            actor: $actor,
            entityType: 'pdo_headers',
            entityId: $pdo->id,
            action: 'DELETE',
            oldValues: $old,
            newValues: null
        );
    }

    // ─────────────────────────────────────────────────────
    // PDO DETAILS
    // ─────────────────────────────────────────────────────

    public function listDetails(PdoHeader $pdo)
    {
        $details = $pdo->details()->with(['expenseItem.subcategory.category', 'transferEntries', 'realizationEntries'])->get();

        return $this->hydrateDetailsExternalState($details, $pdo);
    }

    public function addDetail(PdoHeader $pdo, array $data, User $actor): PdoDetail
    {
        $this->assertDraft($pdo);

        $item = ExpenseItem::findOrFail($data['expense_item_id']);

        $detail = PdoDetail::create([
            'pdo_header_id'  => $pdo->id,
            'expense_item_id'=> $item->id,
            'account_number' => $item->default_account_number, // snapshot
            'description'    => $data['description'],
            'quantity'       => $data['quantity'] ?? null,
            'unit'           => $data['unit'] ?? $item->default_unit, // snapshot
            'rate'           => $data['rate'] ?? $item->default_rate, // snapshot
            'amount'         => $data['amount'],
            'external_source_system' => $item->mode_input === ExpenseItem::MODE_AUTO_EXTERNAL ? $item->external_source_system : null,
            'external_component' => $item->mode_input === ExpenseItem::MODE_AUTO_EXTERNAL ? $item->external_component : null,
            'external_component_key' => $item->mode_input === ExpenseItem::MODE_AUTO_EXTERNAL ? $item->external_component_key : null,
            'notes'          => $data['notes'] ?? null,
            'display_order'  => $data['display_order'] ?? $this->nextDisplayOrder($pdo),
        ]);

        AuditLog::record(
            actor: $actor,
            entityType: 'pdo_details',
            entityId: $detail->id,
            action: 'INSERT',
            oldValues: null,
            newValues: $detail->toArray()
        );

        $this->syncGrandTotal($pdo);

        return $this->hydrateDetailExternalState($detail->load('expenseItem'), $pdo);
    }

    public function updateDetail(PdoHeader $pdo, PdoDetail $detail, array $data, User $actor): PdoDetail
    {
        // Verify detail belongs to the PDO in the URL
        if ($detail->pdo_header_id !== $pdo->id) {
            abort(404);
        }

        $this->assertDraft($pdo);

        $old = $detail->toArray();
        $detail->update($data);

        AuditLog::record(
            actor: $actor,
            entityType: 'pdo_details',
            entityId: $detail->id,
            action: 'UPDATE',
            oldValues: $old,
            newValues: $detail->fresh()->toArray()
        );

        $this->syncGrandTotal($pdo);

        return $this->hydrateDetailExternalState($detail->fresh()->load('expenseItem'), $pdo);
    }

    public function deleteDetail(PdoHeader $pdo, PdoDetail $detail, User $actor): void
    {
        // Verify detail belongs to the PDO in the URL
        if ($detail->pdo_header_id !== $pdo->id) {
            abort(404);
        }

        $this->assertDraft($pdo);

        $old = $detail->toArray();
        $detail->delete();

        AuditLog::record(
            actor: $actor,
            entityType: 'pdo_details',
            entityId: $detail->id,
            action: 'DELETE',
            oldValues: $old,
            newValues: null
        );

        $this->syncGrandTotal($pdo);
    }

    public function pullExternalCost(PdoHeader $pdo, PdoDetail $detail, User $actor): PdoDetail
    {
        $this->assertDraft($pdo);
        $this->assertDetailBelongsToPdo($pdo, $detail);

        $detail->loadMissing('expenseItem');

        $item = $detail->expenseItem;
        $logContext = $this->externalPullLogContext($pdo, $detail, $actor, $item instanceof ExpenseItem ? $item : null);

        Log::info('PDO external pull started', $logContext);

        if (! $item instanceof ExpenseItem || $item->mode_input !== ExpenseItem::MODE_AUTO_EXTERNAL) {
            Log::warning('PDO external pull rejected: detail not auto external', $logContext);

            throw ValidationException::withMessages([
                'expense_item_id' => 'Item ini bukan Auto External sehingga tidak bisa Ambil Data.',
            ]);
        }

        if (! filled($pdo->plantationUnit?->payroll_estate_external_id)) {
            Log::warning('PDO external pull rejected: payroll estate mapping missing', $logContext);

            throw ValidationException::withMessages([
                'plantation_unit_id' => 'Payroll Estate Mapping belum diatur untuk kebun ini.',
            ]);
        }

        if (! filled($item->external_source_system) || ! filled($item->external_component)) {
            Log::warning('PDO external pull rejected: cost mapping missing', $logContext);

            throw ValidationException::withMessages([
                'expense_item_id' => 'Cost Mapping Payroll belum diatur untuk item biaya ini.',
            ]);
        }

        $componentKey = ExpenseItem::supportsExternalOption($item->external_component)
            ? $this->resolveCanonicalExternalComponentKeyFromItem($item)
            : null;
        $storedComponentKey = $item->external_component_key;

        $payrollPeriod = Carbon::create($pdo->period_year, $pdo->period_month, 1)->subMonth();

        $response = $this->requestPayrollCost(
            year: $payrollPeriod->year,
            month: $payrollPeriod->month,
            estateExternalId: $pdo->plantationUnit->payroll_estate_external_id,
            component: $item->external_component,
            componentKey: $componentKey,
            role: null,
        );

        if ($response->successful()) {
            return DB::transaction(function () use ($actor, $detail, $item, $pdo, $response, $componentKey, $storedComponentKey) {
                $old = $detail->toArray();
                $payload = array_merge($response->json(), $detail->currentExternalMappingFingerprint());

                $detail->update([
                    'amount' => (int) data_get($payload, 'amount', 0),
                    'quantity' => (float) data_get($payload, 'volume', 0),
                    'unit' => data_get($payload, 'unit'),
                    'external_source_system' => $item->external_source_system,
                    'external_component' => $item->external_component,
                    'external_component_key' => $componentKey ?? $storedComponentKey,
                    'external_amount_pulled_at' => Carbon::now(),
                    'external_payload' => $payload,
                ]);

                $this->syncGrandTotal($pdo);

                AuditLog::record(
                    actor: $actor,
                    entityType: 'pdo_details',
                    entityId: $detail->id,
                    action: 'EXTERNAL_PULL',
                    oldValues: $old,
                    newValues: $detail->fresh()->toArray()
                );

                Log::info('PDO external pull succeeded', $this->externalPullLogContext(
                    $pdo,
                    $detail->fresh(),
                    $actor,
                    $item,
                    [
                        'amount' => (int) data_get($payload, 'amount', 0),
                        'quantity' => (float) data_get($payload, 'volume', 0),
                        'unit' => data_get($payload, 'unit'),
                        'payroll_status' => data_get($payload, 'status'),
                        'http_status' => $response->status(),
                    ]
                ));

                return $this->hydrateDetailExternalState($detail->fresh()->load('expenseItem'), $pdo);
            });
        }

        $message = (string) data_get($response->json(), 'error', 'Payroll tidak dapat dihubungi saat ini.');

        Log::warning('PDO external pull failed', $this->externalPullLogContext(
            $pdo,
            $detail,
            $actor,
            $item,
            [
                'http_status' => $response->status(),
                'error_message' => $message,
            ]
        ));

        if (in_array($response->status(), [404, 422], true)) {
            throw ValidationException::withMessages([
                'expense_item_id' => $message,
            ]);
        }

        abort(response()->json([
            'success' => false,
            'error' => [
                'code' => 'PAYROLL_UNAVAILABLE',
                'message' => $message,
            ],
        ], 503));
    }

    // ─────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────

    /**
     * BR-PDO-002: Isi baris PDO dari semua expense_item is_routine=true, is_active=true.
     * Nilai amount=0, diisi nanti oleh KERANI.
     */
    private function fillRoutineTemplate(PdoHeader $pdo): void
    {
        $unitId = $pdo->plantation_unit_id;

        $routineItems = ExpenseItem::with('subcategory')
            ->where('is_routine', true)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($unitId) {
                // NULL = berlaku untuk semua kebun; atau unit ada di dalam array
                $q->whereNull('routine_plantation_unit_ids')
                  ->orWhereRaw("routine_plantation_unit_ids @> ARRAY[?]::uuid[]", [$unitId]);
            })
            ->orderBy('code')
            ->get();

        foreach ($routineItems as $order => $item) {
            PdoDetail::create([
                'pdo_header_id'  => $pdo->id,
                'expense_item_id'=> $item->id,
                'account_number' => $item->default_account_number,
                'description'    => $item->name,
                'unit'           => $item->default_unit,
                'rate'           => $item->default_rate,
                'amount'         => 0,
                'external_source_system' => $item->mode_input === ExpenseItem::MODE_AUTO_EXTERNAL ? $item->external_source_system : null,
                'external_component' => $item->mode_input === ExpenseItem::MODE_AUTO_EXTERNAL ? $item->external_component : null,
                'external_component_key' => $item->mode_input === ExpenseItem::MODE_AUTO_EXTERNAL ? $item->external_component_key : null,
                'display_order'  => $order + 1,
            ]);
        }
    }

    /** BR-PDO-003 */
    private function assertDraft(PdoHeader $pdo): void
    {
        if (! $pdo->isDraft()) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'PDO_NOT_EDITABLE', 'message' => 'PDO hanya bisa diubah saat status draft.'],
            ], 409));
        }
    }

    private function nextDisplayOrder(PdoHeader $pdo): int
    {
        return ($pdo->details()->max('display_order') ?? 0) + 1;
    }

    private function assertDetailBelongsToPdo(PdoHeader $pdo, PdoDetail $detail): void
    {
        if ($detail->pdo_header_id !== $pdo->id) {
            abort(404);
        }
    }

    private function resolveCanonicalExternalComponentKeyFromItem(ExpenseItem $item): ?string
    {
        if (filled($item->external_component_key)) {
            return $item->external_component_key;
        }

        if (ExpenseItem::supportsPayrollRole($item->external_component) && filled($item->external_role)) {
            return $item->external_role;
        }

        return null;
    }

    private function requestPayrollCost(
        int $year,
        int $month,
        string $estateExternalId,
        string $component,
        ?string $componentKey,
        ?string $role,
    ): Response {
        $baseUrl = rtrim((string) config('services.payroll_internal_api.base_url', ''), '/');
        $token = (string) config('services.payroll_internal_api.token', '');

        if ($baseUrl === '' || $token === '') {
            Log::error('PDO external pull config missing', [
                'year' => $year,
                'month' => $month,
                'estate_external_id' => $estateExternalId,
                'component' => $component,
                'component_key' => $componentKey,
                'role' => $role,
            ]);

            abort(response()->json([
                'success' => false,
                'error' => [
                    'code' => 'PAYROLL_UNAVAILABLE',
                    'message' => 'Konfigurasi Payroll internal API belum lengkap.',
                ],
            ], 503));
        }

        try {
        return Http::acceptJson()
                ->asJson()
                ->withToken($token)
                ->timeout(15)
                ->get($baseUrl.'/internal/payroll-costs', array_filter([
                    'year' => $year,
                    'month' => $month,
                    'estate_external_id' => $estateExternalId,
                    'component' => $component,
                    'component_key' => $componentKey,
                    'role' => $role,
                ], static fn (mixed $value): bool => $value !== null && $value !== ''));
        } catch (ConnectionException) {
            Log::error('PDO external pull connection failed', [
                'year' => $year,
                'month' => $month,
                'estate_external_id' => $estateExternalId,
                'component' => $component,
                'component_key' => $componentKey,
                'role' => $role,
            ]);

            abort(response()->json([
                'success' => false,
                'error' => [
                    'code' => 'PAYROLL_UNAVAILABLE',
                    'message' => 'Payroll tidak dapat dihubungi saat ini.',
                ],
            ], 503));
        }
    }

    private function syncGrandTotal(PdoHeader $pdo): void
    {
        $pdo->updateQuietly([
            'grand_total_amount' => $pdo->details()->sum('amount'),
        ]);
    }

    private function externalPullLogContext(
        PdoHeader $pdo,
        PdoDetail $detail,
        User $actor,
        ?ExpenseItem $item,
        array $extra = [],
    ): array {
        return array_merge([
            'pdo_id' => $pdo->id,
            'pdo_detail_id' => $detail->id,
            'actor_user_id' => $actor->id,
            'plantation_unit_id' => $pdo->plantation_unit_id,
            'period_year' => $pdo->period_year,
            'period_month' => $pdo->period_month,
            'expense_item_id' => $detail->expense_item_id,
            'payroll_estate_external_id' => $pdo->plantationUnit?->payroll_estate_external_id,
            'external_source_system' => $item?->external_source_system,
            'external_component' => $item?->external_component,
            'external_component_key' => $item?->external_component_key,
            'external_role' => ExpenseItem::supportsPayrollRole($item?->external_component) ? $item?->external_role : null,
        ], $extra);
    }

    private function hydrateDetailsExternalState(iterable $details, PdoHeader $pdo): iterable
    {
        foreach ($details as $detail) {
            $this->hydrateDetailExternalState($detail, $pdo);
        }

        return $details;
    }

    private function hydrateDetailExternalState(PdoDetail $detail, PdoHeader $pdo): PdoDetail
    {
        $detail->setRelation('pdoHeader', $pdo);

        return $detail;
    }
}
