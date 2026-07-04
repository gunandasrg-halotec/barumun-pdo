<?php

namespace App\Http\Requests\Users;

use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(Role::ADMIN) ?? false;
    }

    public function rules(): array
    {
        $companyId = $this->user()->company_id;
        $userId    = $this->route('user');

        return [
            'role_id'            => ['sometimes', 'uuid', 'exists:roles,id'],
            'plantation_unit_id' => ['nullable', 'uuid', 'exists:plantation_units,id'],
            'full_name'          => ['sometimes', 'string', 'max:255'],
            'email'              => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->where('company_id', $companyId)->whereNull('deleted_at')->ignore($userId)],
            'password'           => ['sometimes', Password::min(8)->letters()->numbers()],
            'whatsapp_number'    => ['nullable', 'string', 'max:20'],
            'is_active'          => ['sometimes', 'boolean'],
        ];
    }

    /**
     * BR-USER-001: konsistensi role <-> unit kebun (lihat StoreUserRequest).
     * Karena field bersifat 'sometimes', nilai efektif role/unit digabung
     * dengan data user yang sudah ada (mendukung partial update).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('role_id')) {
                return;
            }

            $user = User::find($this->route('user'));

            $roleId = $this->has('role_id') ? $this->input('role_id') : $user?->role_id;
            $role   = $roleId ? Role::find($roleId) : null;
            if (! $role) {
                return;
            }

            $hasUnit = $this->has('plantation_unit_id')
                ? ! empty($this->input('plantation_unit_id'))
                : ! empty($user?->plantation_unit_id);

            if (in_array($role->code, Role::CROSS_UNIT_ROLES, true) && $hasUnit) {
                $validator->errors()->add('plantation_unit_id', "Role {$role->name} adalah role lintas unit dan tidak boleh diikat ke unit kebun tertentu.");
            }

            if (! in_array($role->code, Role::CROSS_UNIT_ROLES, true) && ! $hasUnit) {
                $validator->errors()->add('plantation_unit_id', "Unit Kebun wajib diisi untuk role {$role->name}.");
            }
        });
    }
}
