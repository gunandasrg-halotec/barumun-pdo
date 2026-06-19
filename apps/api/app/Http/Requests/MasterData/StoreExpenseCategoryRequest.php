<?php

namespace App\Http\Requests\MasterData;

use Illuminate\Foundation\Http\FormRequest;

class StoreExpenseCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('ADMIN') ?? false;
    }

    public function rules(): array
    {
        $companyId = $this->user()->company_id;

        return [
            'code'             => ['required', 'string', 'max:20', "unique:expense_categories,code,NULL,id,company_id,{$companyId},deleted_at,NULL"],
            'name'             => ['required', 'string', 'max:255'],
            'display_order'    => ['sometimes', 'integer', 'min:0'],
            'include_in_recap' => ['sometimes', 'boolean'],
            'is_active'        => ['sometimes', 'boolean'],
            'notes'            => ['nullable', 'string'],
        ];
    }
}
