<?php

namespace App\Http\Requests\Transfer;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTransferEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canRecordTransfer() ?? false;
    }

    public function rules(): array
    {
        return [
            'transfer_date'    => ['sometimes', 'date'],
            'amount'           => ['sometimes', 'integer', 'min:1'],
            'reference_number' => ['sometimes', 'string', 'max:100'],
            'notes'            => ['nullable', 'string'],
        ];
    }
}
