<?php

namespace App\Http\Requests\MasterData;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole([Role::ADMIN, Role::STAFF_KEUANGAN]) ?? false;
    }

    public function rules(): array
    {
        $id = $this->route('vehicle');

        return [
            'nomor_polisi'    => ['sometimes', 'string', 'max:20', "unique:vehicles,nomor_polisi,{$id},id,deleted_at,NULL"],
            'nama'            => ['sometimes', 'string', 'max:100'],
            'expense_item_id' => ['nullable', 'uuid', 'exists:expense_items,id'],
            'is_active'       => ['sometimes', 'boolean'],
        ];
    }
}
