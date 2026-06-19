<?php

namespace App\Http\Requests\PdoSupplementary;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;

class StorePdoSupplementaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(Role::KERANI) ?? false;
    }

    public function rules(): array
    {
        return [
            'parent_pdo_header_id' => ['required', 'uuid', 'exists:pdo_headers,id'],
            'notes'                => ['nullable', 'string'],
        ];
    }
}
