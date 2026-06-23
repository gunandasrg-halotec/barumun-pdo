<?php

namespace App\Http\Requests\MasterData;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePlantationUnitPayrollMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(Role::ADMIN) ?? false;
    }

    public function rules(): array
    {
        return [
            'payroll_estate_external_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}
