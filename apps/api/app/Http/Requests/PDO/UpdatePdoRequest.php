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
            'notes' => ['nullable', 'string'],
        ];
    }
}
