<?php

namespace App\Http\Requests\MasterData;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

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
            'account_code_kas_kebun'     => ['nullable', 'string', 'max:50'],
        ];
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'error' => [
                'code' => 'FORBIDDEN',
                'message' => 'Anda tidak memiliki izin untuk memperbarui Payroll Estate Mapping.',
            ],
        ], 403));
    }
}
