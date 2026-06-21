<?php

namespace App\Http\Requests\MasterData;

use Illuminate\Foundation\Http\FormRequest;

class UpdateExpenseSubcategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('ADMIN') ?? false;
    }

    public function rules(): array
    {
        $subcategory   = $this->route('expense_subcategory');
        $subcategoryId = $subcategory?->id;
        $categoryId    = $this->input('category_id', $subcategory?->category_id);

        return [
            'category_id'   => ['sometimes', 'uuid', 'exists:expense_categories,id'],
            'code'          => ['sometimes', 'string', 'max:20', "unique:expense_subcategories,code,{$subcategoryId},id,category_id,{$categoryId},deleted_at,NULL"],
            'name'          => ['sometimes', 'string', 'max:255'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
            'is_active'     => ['sometimes', 'boolean'],
            'notes'         => ['nullable', 'string'],
        ];
    }
}
