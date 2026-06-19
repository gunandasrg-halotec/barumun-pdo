<?php

namespace App\Http\Requests\PDO;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;

class StorePdoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // KERANI yang membuat PDO untuk unit-nya
        return $this->user()?->hasRole(Role::KERANI) ?? false;
    }

    public function rules(): array
    {
        return [
            'plantation_unit_id' => ['required', 'uuid', 'exists:plantation_units,id'],
            'period_month'       => ['required', 'integer', 'min:1', 'max:12'],
            'period_year'        => ['required', 'integer', 'min:2020', 'max:2099'],
            'notes'              => ['nullable', 'string'],
        ];
    }
}
