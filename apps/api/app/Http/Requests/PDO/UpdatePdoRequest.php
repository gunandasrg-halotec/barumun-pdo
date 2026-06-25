<?php

namespace App\Http\Requests\PDO;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePdoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole([Role::KERANI, Role::ADMIN]) ?? false;
    }

    public function rules(): array
    {
        return [
            'notes'                    => ['nullable', 'string'],
            'details'                  => ['sometimes', 'array'],
            'details.*.id'             => ['required_with:details', 'uuid'],
            'details.*.description'    => ['sometimes', 'string'],
            'details.*.quantity'       => ['nullable', 'numeric', 'min:0'],
            'details.*.unit'           => ['nullable', 'string', 'max:50'],
            'details.*.rate'           => ['nullable', 'numeric', 'min:0'],
            'details.*.amount'         => ['sometimes', 'integer', 'min:0'],
            'details.*.notes'          => ['nullable', 'string'],
            'details.*.display_order'  => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
