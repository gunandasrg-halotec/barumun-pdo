<?php

namespace App\Http\Requests\PettyCash;

use App\Models\PdoDetail;
use App\Models\RealizationEntry;
use App\Services\Realization\RealizationJournalExportService;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePettyCashVoucherRequest extends FormRequest
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
            'paid_to'                => ['sometimes', 'string', 'max:255'],
            'voucher_date'           => ['sometimes', 'date'],
            'lines'                  => ['sometimes', 'array', 'min:1'],
            'lines.*.pdo_detail_id'  => ['required_with:lines', 'uuid', 'exists:pdo_details,id'],
            'lines.*.description'    => ['required_with:lines', 'string', 'max:255'],
            'lines.*.amount'         => ['required_with:lines', 'integer', 'min:1'],
            'lines.*.vehicle_id'     => ['nullable', 'uuid', 'exists:vehicles,id'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (! $this->has('lines')) {
                return;
            }

            foreach ((array) $this->input('lines', []) as $i => $line) {
                $detailId = $line['pdo_detail_id'] ?? null;
                if (! $detailId) {
                    continue;
                }

                $detail = PdoDetail::with('expenseItem')->find($detailId);
                $item   = $detail?->expenseItem;
                if (! $item) {
                    continue;
                }

                if (in_array($item->code, RealizationJournalExportService::INVENTORY_ITEM_CODES, true) && empty($line['vehicle_id'])) {
                    $validator->errors()->add("lines.{$i}.vehicle_id", 'Kendaraan wajib dipilih untuk item biaya ini.');
                }

                if ($item->is_fund_return) {
                    $validator->errors()->add("lines.{$i}.pdo_detail_id", 'Pengembalian sisa dana bulan lalu tidak melalui voucher — catat langsung di form Input Realisasi.');
                }

                if ($item->is_deduction) {
                    $validator->errors()->add("lines.{$i}.pdo_detail_id", 'Item potongan tidak bisa dimasukkan ke voucher.');
                }
            }
        });
    }
}
