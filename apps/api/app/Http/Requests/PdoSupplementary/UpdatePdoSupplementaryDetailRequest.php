<?php

namespace App\Http\Requests\PdoSupplementary;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePdoSupplementaryDetailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(Role::KERANI) ?? false;
    }

    public function rules(): array
    {
        return [
            'description'   => ['sometimes', 'string'],
            'quantity'      => ['nullable', 'numeric', 'min:0'],
            'unit'          => ['nullable', 'string', 'max:50'],
            'rate'          => ['nullable', 'integer', 'min:0'],
            'amount'        => ['sometimes', 'integer', 'min:1'],
            'notes'         => ['nullable', 'string'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
