<?php

namespace App\Http\Requests\MasterData;

use Illuminate\Foundation\Http\FormRequest;

class StoreExpenseSubcategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('ADMIN') ?? false;
    }

    public function rules(): array
    {
        $categoryId = $this->input('category_id');

        return [
            'category_id'   => ['required', 'uuid', 'exists:expense_categories,id'],
            'code'          => ['required', 'string', 'max:20', "unique:expense_subcategories,code,NULL,id,category_id,{$categoryId},deleted_at,NULL"],
            'name'          => ['required', 'string', 'max:255'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
            'is_active'     => ['sometimes', 'boolean'],
            'notes'         => ['nullable', 'string'],
        ];
    }
}
