<?php

namespace App\Http\Requests\Users;

use App\Models\Role;
use Illuminate\Contracts\Validation\Validator;
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

    /**
     * BR-USER-001: konsistensi role <-> unit kebun.
     * Role unit-bound (KERANI, ASISTEN_KEBUN) WAJIB punya plantation_unit_id,
     * agar row-level security & pembatasan realisasi per unit (BR-REAL-005)
     * benar-benar terjaga. Role lintas unit (Role::CROSS_UNIT_ROLES) TIDAK
     * BOLEH punya plantation_unit_id, agar tidak tanpa sengaja terkunci ke
     * satu unit saja.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('role_id')) {
                return;
            }

            $role = Role::find($this->input('role_id'));
            if (! $role) {
                return;
            }

            $hasUnit = ! empty($this->input('plantation_unit_id'));

            if (in_array($role->code, Role::CROSS_UNIT_ROLES, true) && $hasUnit) {
                $validator->errors()->add('plantation_unit_id', "Role {$role->name} adalah role lintas unit dan tidak boleh diikat ke unit kebun tertentu.");
            }

            if (! in_array($role->code, Role::CROSS_UNIT_ROLES, true) && ! $hasUnit) {
                $validator->errors()->add('plantation_unit_id', "Unit Kebun wajib diisi untuk role {$role->name}.");
            }
        });
    }
}
