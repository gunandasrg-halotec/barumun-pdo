<?php

namespace App\Http\Requests\MasterData;

use App\Models\ExpenseItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExpenseItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole(['ADMIN', 'STAFF_KEUANGAN']) ?? false;
    }

    public function rules(): array
    {
        $item          = $this->route('expense_item');
        $itemId        = is_string($item) ? $item : $item?->id;
        $resolved      = is_string($item) ? \App\Models\ExpenseItem::find($item) : $item;
        $subcategoryId = $this->input('subcategory_id', $resolved?->subcategory_id);

        return [
            'subcategory_id'         => ['sometimes', 'uuid', 'exists:expense_subcategories,id'],
            'code'                   => ['sometimes', 'string', 'max:30', "unique:expense_items,code,{$itemId},id,subcategory_id,{$subcategoryId},deleted_at,NULL"],
            'name'                   => ['sometimes', 'string', 'max:255'],
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
