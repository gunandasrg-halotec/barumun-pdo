<?php

namespace App\Http\Requests\PdoSupplementary;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePdoSupplementaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(Role::KERANI) ?? false;
    }

    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string'],
        ];
    }
}
