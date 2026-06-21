<?php

namespace App\Http\Requests\MasterData;

use Illuminate\Foundation\Http\FormRequest;

class UpdateExpenseCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('ADMIN') ?? false;
    }

    public function rules(): array
    {
        $companyId  = $this->user()->company_id;
        $categoryId = $this->route('expense_category')?->id;

        return [
            'code'             => ['sometimes', 'string', 'max:20', "unique:expense_categories,code,{$categoryId},id,company_id,{$companyId},deleted_at,NULL"],
            'name'             => ['sometimes', 'string', 'max:255'],
            'display_order'    => ['sometimes', 'integer', 'min:0'],
            'include_in_recap' => ['sometimes', 'boolean'],
            'is_active'        => ['sometimes', 'boolean'],
            'notes'            => ['nullable', 'string'],
        ];
    }
}
