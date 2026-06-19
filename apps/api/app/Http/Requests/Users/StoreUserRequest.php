<?php

namespace App\Http\Requests\Users;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(Role::ADMIN) ?? false;
    }

    public function rules(): array
    {
        $companyId = $this->user()->company_id;

        return [
            'role_id'            => ['required', 'uuid', 'exists:roles,id'],
            'plantation_unit_id' => ['nullable', 'uuid', 'exists:plantation_units,id'],
            'full_name'          => ['required', 'string', 'max:255'],
            'email'              => ['required', 'email', 'max:255', Rule::unique('users', 'email')->where('company_id', $companyId)->whereNull('deleted_at')],
            'password'           => ['required', Password::min(8)->letters()->numbers()],
            'whatsapp_number'    => ['nullable', 'string', 'max:20'],
            'is_active'          => ['sometimes', 'boolean'],
        ];
    }
}
