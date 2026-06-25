<?php

namespace App\Http\Requests\MasterData;

use App\Models\ExpenseItem;
use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExpenseItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole([Role::ADMIN, Role::STAFF_KEUANGAN]) ?? false;
    }

    public function rules(): array
    {
        $subcategoryId = $this->input('subcategory_id');

        return [
            'subcategory_id'                    => ['required', 'uuid', 'exists:expense_subcategories,id'],
            'code'                              => ['required', 'string', 'max:30', "unique:expense_items,code,NULL,id,subcategory_id,{$subcategoryId},deleted_at,NULL"],
            'name'                              => ['required', 'string', 'max:255'],
            'default_account_number'             => ['nullable', 'string', 'max:50'],
            'default_unit'                      => ['nullable', 'string', 'max:50'],
            'default_rate'                      => ['nullable', 'integer', 'min:0'],
            'mode_input'                        => ['sometimes', Rule::in([ExpenseItem::MODE_MANUAL, ExpenseItem::MODE_AUTO_EXTERNAL])],
            'external_source_system'             => ['nullable', Rule::in(ExpenseItem::payrollSourceSystems())],
            'external_component'                 => ['nullable', Rule::in(ExpenseItem::payrollComponents())],
            'external_component_key'             => ['nullable', 'string', 'max:100'],
            'external_role'                      => ['nullable', Rule::in(ExpenseItem::payrollRoles())],
            'split_transfer'                    => ['sometimes', 'boolean'],
            'split_transfer_plantation_unit_ids' => ['nullable', 'array'],
            'split_transfer_plantation_unit_ids.*' => ['uuid', 'exists:plantation_units,id'],
            'is_routine'                        => ['sometimes', 'boolean'],
            'routine_plantation_unit_ids'       => ['nullable', 'array'],
            'routine_plantation_unit_ids.*'     => ['uuid', 'exists:plantation_units,id'],
            'is_active'                         => ['sometimes', 'boolean'],
            'notes'                             => ['nullable', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $modeInput = $this->input('mode_input', ExpenseItem::MODE_MANUAL);
            $isAdmin   = $this->user()?->hasRole(Role::ADMIN) ?? false;

            if (! $this->isAutoExternalMode($modeInput)) {
                if ($this->has('external_source_system') || $this->has('external_component') || $this->has('external_component_key') || $this->has('external_role')) {
                    $validator->errors()->add('mode_input', 'Mode manual tidak dapat menyimpan mapping sumber eksternal.');
                }

                return;
            }

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
                $validator->errors()->add('external_component_key', 'external_component_key wajib diisi untuk component additional_wage_type_total.');
            }

            if ($this->filled('external_role') && ! ExpenseItem::supportsPayrollRole($this->input('external_component'))) {
                $validator->errors()->add('external_role', 'external_role hanya boleh diisi untuk component base_payroll_total.');
            }
        });
    }

    private function isAutoExternalMode(string $modeInput): bool
    {
        return $modeInput === ExpenseItem::MODE_AUTO_EXTERNAL;
    }
}
