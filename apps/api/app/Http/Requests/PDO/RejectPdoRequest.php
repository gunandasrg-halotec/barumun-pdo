<?php

namespace App\Http\Requests\PDO;

use Illuminate\Foundation\Http\FormRequest;

class RejectPdoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canApprove() ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string'],
        ];
    }
}
