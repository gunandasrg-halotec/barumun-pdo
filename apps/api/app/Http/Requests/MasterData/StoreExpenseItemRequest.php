<?php

namespace App\Http\Requests\MasterData;

use App\Models\ExpenseItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExpenseItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole(['ADMIN', 'STAFF_KEUANGAN']) ?? false;
    }

    public function rules(): array
    {
        $subcategoryId = $this->input('subcategory_id');

        return [
            'subcategory_id'         => ['required', 'uuid', 'exists:expense_subcategories,id'],
            'code'                   => ['required', 'string', 'max:30', "unique:expense_items,code,NULL,id,subcategory_id,{$subcategoryId},deleted_at,NULL"],
            'name'                   => ['required', 'string', 'max:255'],
            'default_account_number' => ['nullable', 'string', 'max:50'],
            'default_unit'           => ['nullable', 'string', 'max:50'],
            'default_rate'           => ['sometimes', 'integer', 'min:0'],
            'mode_input'                    => ['sometimes', Rule::in([ExpenseItem::MODE_MANUAL, ExpenseItem::MODE_AUTO_EXTERNAL])],
            'split_transfer'                       => ['sometimes', 'boolean'],
            'split_transfer_plantation_unit_ids'   => ['nullable', 'array'],
            'split_transfer_plantation_unit_ids.*' => ['uuid', 'exists:plantation_units,id'],
            'is_routine'                           => ['sometimes', 'boolean'],
            'routine_plantation_unit_ids'  => ['nullable', 'array'],
            'routine_plantation_unit_ids.*'=> ['uuid', 'exists:plantation_units,id'],
            'is_active'                    => ['sometimes', 'boolean'],
            'notes'                        => ['nullable', 'string'],
        ];
    }
}
