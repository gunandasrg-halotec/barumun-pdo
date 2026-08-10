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
            'plantation_unit_id' => [
                'required', 'uuid', 'exists:plantation_units,id',
                function ($attribute, $value, $fail) {
                    // KERANI hanya boleh buat PDO untuk unit yang di-resolve
                    // EnsureUnitAccess (unit sendiri + unit yang di-link, mis.
                    // Sosa Replanting) — cegah kirim plantation_unit_id unit lain.
                    if (app()->bound('current_unit_ids') && ! in_array($value, app('current_unit_ids'), true)) {
                        $fail('Anda tidak memiliki akses untuk membuat PDO pada unit ini.');
                    }
                },
            ],
            'period_month'       => ['required', 'integer', 'min:1', 'max:12'],
            'period_year'        => ['required', 'integer', 'min:2020', 'max:2099'],
            'notes'              => ['nullable', 'string'],
            'source_pdo_id'      => ['nullable', 'uuid', 'exists:pdo_headers,id'],
            'copy_amounts'       => ['nullable', 'boolean'],
        ];
    }
}
