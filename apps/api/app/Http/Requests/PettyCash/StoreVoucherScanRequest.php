<?php

namespace App\Http\Requests\PettyCash;

use App\Models\RealizationEntry;
use Illuminate\Foundation\Http\FormRequest;

class StoreVoucherScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user
            && $user->canRecordRealization()
            && $user->realizationSettlementGroup() === RealizationEntry::SETTLEMENT_KEBUN;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf'],
        ];
    }
}
