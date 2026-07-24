<?php

namespace App\Http\Requests\Realization;

use App\Models\RealizationEntry;
use App\Services\Realization\RealizationJournalExportService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRealizationEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canRecordRealization() ?? false;
    }

    public function rules(): array
    {
        return [
            'vehicle_id'       => ['nullable', 'uuid', 'exists:vehicles,id'],
            'transaction_date' => ['sometimes', 'date'],
            'amount'           => ['sometimes', 'integer', 'min:1'],
            'payment_method'   => ['sometimes', Rule::in([RealizationEntry::PAYMENT_TUNAI, RealizationEntry::PAYMENT_TRANSFER, RealizationEntry::PAYMENT_KAS_KECIL])],
            'proof_number'     => ['sometimes', 'string', 'max:100'],
            'funding_source'   => ['sometimes', Rule::in([RealizationEntry::FUNDING_KAS_KEBUN, RealizationEntry::FUNDING_REKENING_KEBUN, RealizationEntry::FUNDING_REKENING_UTAMA])],
            'explanation'      => ['nullable', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            /** @var RealizationEntry|null $entry */
            $entry = $this->route('entry');
            if (! $entry) {
                return;
            }

            $code = $entry->pdoDetail?->expenseItem?->code;

            if (in_array($code, RealizationJournalExportService::INVENTORY_ITEM_CODES, true) && $this->has('vehicle_id') && ! $this->filled('vehicle_id')) {
                $validator->errors()->add('vehicle_id', 'Kendaraan wajib dipilih untuk item biaya ini.');
            }
        });
    }
}
