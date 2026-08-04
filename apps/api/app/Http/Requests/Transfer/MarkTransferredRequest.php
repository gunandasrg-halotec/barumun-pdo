<?php

namespace App\Http\Requests\Transfer;

use Illuminate\Foundation\Http\FormRequest;

class MarkTransferredRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canMarkTransferExecuted() ?? false;
    }

    public function rules(): array
    {
        return [
            'entry_ids'      => ['required', 'array', 'min:1'],
            'entry_ids.*'    => ['uuid', 'distinct', 'exists:transfer_entries,id'],
            'is_transferred' => ['required', 'boolean'],
            // pdo_detail_id => vehicle_id — hanya dipakai saat menandai item BBM/suku
            // cadang sebagai sudah ditransfer (lihat RealizationJournalExportService::INVENTORY_ITEM_CODES).
            'vehicles'       => ['sometimes', 'array'],
            'vehicles.*'     => ['uuid', 'exists:vehicles,id'],
        ];
    }
}
