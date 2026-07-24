<?php

namespace App\Http\Requests\Realization;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ExportJournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canExportJournal() ?? false;
    }

    public function rules(): array
    {
        return [
            'entry_ids'               => ['required', 'array', 'min:1'],
            'entry_ids.*'             => ['uuid', 'exists:realization_entries,id'],
            'preview'                 => ['nullable', 'boolean'],
            'include_inventory_usage' => ['nullable', 'boolean'],
        ];
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'error'   => [
                'code'    => 'FORBIDDEN',
                'message' => 'Anda tidak memiliki izin untuk export jurnal.',
            ],
        ], 403));
    }
}
