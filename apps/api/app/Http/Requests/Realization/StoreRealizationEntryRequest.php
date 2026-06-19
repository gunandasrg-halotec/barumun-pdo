<?php

namespace App\Http\Requests\Realization;

use App\Models\RealizationEntry;
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
            'transaction_date' => ['required', 'date'],
            'amount'           => ['required', 'integer', 'min:1'],
            'payment_method'   => ['required', Rule::in([RealizationEntry::PAYMENT_TUNAI, RealizationEntry::PAYMENT_TRANSFER, RealizationEntry::PAYMENT_KAS_KECIL])],
            'reference_number' => ['required', 'string', 'max:100'],
            'funding_source'   => ['required', Rule::in([RealizationEntry::FUNDING_KAS_KEBUN, RealizationEntry::FUNDING_REKENING_KEBUN, RealizationEntry::FUNDING_REKENING_UTAMA])],
            'explanation'      => ['nullable', 'string'],
        ];
    }
}
