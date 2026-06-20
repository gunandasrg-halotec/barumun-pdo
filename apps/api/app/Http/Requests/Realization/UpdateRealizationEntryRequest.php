<?php

namespace App\Http\Requests\Realization;

use App\Models\RealizationEntry;
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
            'transaction_date' => ['sometimes', 'date'],
            'amount'           => ['sometimes', 'integer', 'min:1'],
            'payment_method'   => ['sometimes', Rule::in([RealizationEntry::PAYMENT_TUNAI, RealizationEntry::PAYMENT_TRANSFER, RealizationEntry::PAYMENT_KAS_KECIL])],
            'proof_number'     => ['sometimes', 'string', 'max:100'],
            'funding_source'   => ['sometimes', Rule::in([RealizationEntry::FUNDING_KAS_KEBUN, RealizationEntry::FUNDING_REKENING_KEBUN, RealizationEntry::FUNDING_REKENING_UTAMA])],
            'explanation'      => ['nullable', 'string'],
        ];
    }
}
