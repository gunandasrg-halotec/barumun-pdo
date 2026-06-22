<?php

namespace App\Http\Requests\Transfer;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;

class StoreTransferEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canRecordTransfer() ?? false;
    }

    public function rules(): array
    {
        return [
            'transfer_date'        => ['required', 'date'],
            'amount'               => ['required', 'integer', 'min:1'],
            'reference_number'     => ['nullable', 'string', 'max:100'],
            'notes'                => ['nullable', 'string'],
            'transfer_destination' => ['sometimes', 'string', 'in:rek_kebun,pribadi,vendor'],
        ];
    }
}
