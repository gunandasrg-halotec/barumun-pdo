<?php

namespace App\Http\Requests\PdoSupplementary;

use App\Models\PdoSupplementaryHeader;
use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePdoSupplementaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(Role::KERANI) ?? false;
    }

    public function rules(): array
    {
        return [
            'funding_option' => ['nullable', Rule::in([
                PdoSupplementaryHeader::FUNDING_HO_TRANSFER,
                PdoSupplementaryHeader::FUNDING_KAS_KEBUN,
            ])],
            'notes' => ['nullable', 'string'],
        ];
    }
}
