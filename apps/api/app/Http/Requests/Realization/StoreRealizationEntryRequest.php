<?php

namespace App\Http\Requests\Realization;

use App\Models\PdoDetail;
use App\Models\RealizationEntry;
use App\Services\Realization\RealizationEntryService;
use App\Services\Realization\RealizationJournalExportService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRealizationEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canRecordRealization() ?? false;
    }

    public function rules(): array
    {
        return [
            // 'FUND_RETURN' = sentinel untuk item PENGEMBALIAN SISA DANA BULAN LALU
            // (belum tentu punya baris pdo_details, dibuat saat store()).
            'pdo_detail_id'    => [
                'required', 'string',
                function ($attribute, $value, $fail) {
                    if ($value === RealizationEntryService::FUND_RETURN_SENTINEL) {
                        return;
                    }
                    if (! \Illuminate\Support\Str::isUuid($value) || ! PdoDetail::where('id', $value)->exists()) {
                        $fail('pdo_detail_id yang dipilih tidak valid.');
                    }
                },
            ],
            // Hanya wajib saat pdo_detail_id = sentinel FUND_RETURN — dipakai
            // untuk cari PdoHeader karena belum ada pdo_details untuk item ini.
            'pdo_header_id'    => [
                Rule::requiredIf(fn () => $this->input('pdo_detail_id') === RealizationEntryService::FUND_RETURN_SENTINEL),
                'nullable', 'uuid', 'exists:pdo_headers,id',
            ],
            'vehicle_id'       => ['nullable', 'uuid', 'exists:vehicles,id'],
            'transaction_date' => ['required', 'date'],
            'amount'           => ['required', 'integer', 'min:1'],
            'payment_method'   => ['required', Rule::in([RealizationEntry::PAYMENT_TUNAI, RealizationEntry::PAYMENT_TRANSFER, RealizationEntry::PAYMENT_KAS_KECIL])],
            // Boleh kosong — RealizationEntryService::store() akan auto-generate
            // {PDO_Number}/{Item_code}/{seq} jika kosong. Duplikat divalidasi di
            // service (butuh konteks PDO header yang belum tentu tersedia di sini).
            'proof_number'     => ['nullable', 'string', 'max:100'],
            'funding_source'   => ['required', Rule::in([RealizationEntry::FUNDING_KAS_KEBUN, RealizationEntry::FUNDING_REKENING_KEBUN, RealizationEntry::FUNDING_REKENING_UTAMA])],
            'explanation'      => ['nullable', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $pdoDetailId = $this->input('pdo_detail_id');
            if (! $pdoDetailId || $pdoDetailId === RealizationEntryService::FUND_RETURN_SENTINEL) {
                return;
            }

            $detail = PdoDetail::with('expenseItem')->find($pdoDetailId);
            $code   = $detail?->expenseItem?->code;

            if (in_array($code, RealizationJournalExportService::INVENTORY_ITEM_CODES, true) && ! $this->filled('vehicle_id')) {
                $validator->errors()->add('vehicle_id', 'Kendaraan wajib dipilih untuk item biaya ini.');
            }
        });
    }
}
