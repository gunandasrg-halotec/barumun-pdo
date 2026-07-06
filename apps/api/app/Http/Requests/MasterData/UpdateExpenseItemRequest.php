<?php

namespace App\Http\Requests\MasterData;

use App\Models\ExpenseItem;
use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExpenseItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole([Role::ADMIN, Role::STAFF_KEUANGAN]) ?? false;
    }

    public function rules(): array
    {
        $item          = $this->route('expense_item');
        $itemId        = is_string($item) ? $item : $item?->id;
        $resolved      = is_string($item) ? \App\Models\ExpenseItem::find($item) : $item;
        $subcategoryId = $this->input('subcategory_id', $resolved?->subcategory_id);

        return [
            'subcategory_id'                    => ['sometimes', 'uuid', 'exists:expense_subcategories,id'],
            'code'                              => ['sometimes', 'string', 'max:30', "unique:expense_items,code,{$itemId},id,subcategory_id,{$subcategoryId},deleted_at,NULL"],
            'name'                              => ['sometimes', 'string', 'max:255'],
            'default_account_number'             => ['nullable', 'string', 'max:50'],
            'default_unit'                      => ['nullable', 'string', 'max:50'],
            'default_rate'                      => ['nullable', 'integer', 'min:0'],
            'mode_input'                        => ['sometimes', Rule::in([ExpenseItem::MODE_MANUAL, ExpenseItem::MODE_AUTO_EXTERNAL])],
            'external_source_system'             => ['nullable', Rule::in(ExpenseItem::payrollSourceSystems())],
            'external_component'                 => ['nullable', Rule::in(ExpenseItem::payrollComponents())],
            'external_component_key'             => ['nullable', 'string', 'max:100'],
            'external_component_keys'            => ['nullable', 'array'],
            'external_component_keys.*'          => ['nullable', 'string', 'max:100'],
            'external_block_keys'                => ['nullable', 'array'],
            'external_block_keys.*'              => ['nullable', 'string', 'max:100'],
            'external_block_scopes'              => ['nullable', 'array'],
            'external_block_scopes.*.plantation_unit_id' => ['required_with:external_block_scopes', 'uuid', 'exists:plantation_units,id'],
            'external_block_scopes.*.block_keys' => ['required_with:external_block_scopes', 'array'],
            'external_block_scopes.*.block_keys.*' => ['nullable', 'string', 'max:100'],
            'external_role'                      => ['nullable', 'string', 'max:100'],
            'split_transfer'                    => ['sometimes', 'boolean'],
            'split_transfer_plantation_unit_ids' => ['nullable', 'array'],
            'split_transfer_plantation_unit_ids.*' => ['uuid', 'exists:plantation_units,id'],
            'is_routine'                        => ['sometimes', 'boolean'],
            'routine_plantation_unit_ids'       => ['nullable', 'array'],
            'routine_plantation_unit_ids.*'=> ['uuid', 'exists:plantation_units,id'],
            'is_active'                         => ['sometimes', 'boolean'],
            'is_deduction'                      => ['sometimes', 'boolean'],
            'notes'                             => ['nullable', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $item          = $this->route('expense_item');
            $resolved      = is_string($item) ? \App\Models\ExpenseItem::find($item) : $item;
            $currentMode   = $resolved?->mode_input ?? ExpenseItem::MODE_MANUAL;
            $requestMode   = $this->input('mode_input', $currentMode);
            $hasMappingField = $this->has('external_source_system') || $this->has('external_component') || $this->has('external_component_key') || $this->has('external_component_keys') || $this->has('external_block_keys') || $this->has('external_block_scopes') || $this->has('external_role');
            $isAutoExternal = $requestMode === ExpenseItem::MODE_AUTO_EXTERNAL;
            $isAdmin        = $this->user()?->hasRole(Role::ADMIN) ?? false;
            $component = $this->input('external_component', $resolved?->external_component);

            if ($isAutoExternal) {
                if (! $isAdmin) {
                    $validator->errors()->add('mode_input', 'Hanya ADMIN yang dapat menyetel mapping auto external.');

                    return;
                }

                if (! $this->filled('external_source_system')) {
                    $validator->errors()->add('external_source_system', 'external_source_system wajib diisi untuk mode auto_external.');
                }

                if (! $this->filled('external_component')) {
                    $validator->errors()->add('external_component', 'external_component wajib diisi untuk mode auto_external.');
                }

                if ($this->input('external_component') === ExpenseItem::PAYROLL_COMPONENT_ADDITIONAL_WAGE_TYPE_TOTAL && ! $this->filled('external_component_key')) {
                    $componentKeys = array_filter($this->input('external_component_keys', []), fn ($value) => is_string($value) && trim($value) !== '');

                    if ($componentKeys === []) {
                        $validator->errors()->add('external_component_key', 'external_component_key wajib diisi untuk component additional_wage_type_total.');
                    }
                }

                if ($this->filled('external_role') && ! ExpenseItem::supportsPayrollRole($component)) {
                    $validator->errors()->add('external_role', 'external_role hanya boleh diisi untuk component payroll.');
                }

                return;
            }

            if ($currentMode === ExpenseItem::MODE_AUTO_EXTERNAL && ! $this->filled('mode_input') && ! $hasMappingField) {
                return;
            }

            if ($currentMode === ExpenseItem::MODE_AUTO_EXTERNAL && ! $isAdmin && $this->has('mode_input')) {
                $validator->errors()->add('mode_input', 'Hanya ADMIN yang dapat mengubah mode auto_external.');
            }

            if ($hasMappingField) {
                $validator->errors()->add('mode_input', 'Mode manual tidak dapat menyimpan mapping sumber eksternal.');
            }
        });
    }
}
