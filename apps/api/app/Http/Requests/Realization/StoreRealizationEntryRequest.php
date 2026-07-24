<?php

namespace App\Http\Requests\Realization;

use App\Models\PdoDetail;
use App\Models\RealizationEntry;
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
            'pdo_detail_id'    => ['required', 'uuid', 'exists:pdo_details,id'],
            'vehicle_id'       => ['nullable', 'uuid', 'exists:vehicles,id'],
            'transaction_date' => ['required', 'date'],
            'amount'           => ['required', 'integer', 'min:1'],
            'payment_method'   => ['required', Rule::in([RealizationEntry::PAYMENT_TUNAI, RealizationEntry::PAYMENT_TRANSFER, RealizationEntry::PAYMENT_KAS_KECIL])],
            'proof_number'     => ['required', 'string', 'max:100'],
            'funding_source'   => ['required', Rule::in([RealizationEntry::FUNDING_KAS_KEBUN, RealizationEntry::FUNDING_REKENING_KEBUN, RealizationEntry::FUNDING_REKENING_UTAMA])],
            'explanation'      => ['nullable', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $pdoDetailId = $this->input('pdo_detail_id');
            if (! $pdoDetailId) {
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
