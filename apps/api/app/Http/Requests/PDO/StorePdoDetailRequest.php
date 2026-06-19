<?php

namespace App\Http\Requests\PDO;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;

class StorePdoDetailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole([Role::KERANI, Role::ADMIN]) ?? false;
    }

    public function rules(): array
    {
        return [
            'expense_item_id' => ['required', 'uuid', 'exists:expense_items,id'],
            'description'     => ['required', 'string'],
            'quantity'        => ['nullable', 'numeric', 'min:0'],
            'unit'            => ['nullable', 'string', 'max:50'],
            'rate'            => ['nullable', 'integer', 'min:0'],
            'amount'          => ['required', 'integer', 'min:0'],
            'notes'           => ['nullable', 'string'],
            'display_order'   => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
