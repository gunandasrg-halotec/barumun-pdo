<?php

namespace App\Services\Realization;

use App\Models\RealizationEntry;
use App\Models\User;

class RealizationJournalExportService
{
    /**
     * Label Tag per kode unit kebun, dipakai untuk mengelompokkan transaksi
     * jurnal umum berdasarkan kebun di Jurnal by Mekari.
     */
    private const UNIT_TAGS = [
        'BN' => 'Kebun Binanga',
        'JM' => 'Kebun JM',
        'KP' => 'Kebun KP',
        'SS' => 'Kebun Sosa',
    ];

    /**
     * Kode akun untuk baris kredit saat funding_source = rekening_utama
     * (bukan kas kebun per unit, tapi rekening utama perusahaan).
     */
    private const REKENING_UTAMA_ACCOUNT_CODE = '1-10019';

    /**
     * Bangun baris-baris jurnal (debit + kredit) untuk setiap realisasi terpilih.
     * Tidak ada entry yang di-skip — AccountCode dikosongkan bila belum tersedia
     * di master data, agar user bisa mengisinya manual sebelum import ke Jurnal.
     */
    public function buildRows(array $entryIds, User $actor): array
    {
        $entries = RealizationEntry::whereIn('id', $entryIds)
            ->whereHas('pdoDetail.pdoHeader', fn ($q) => $q->where('company_id', $actor->company_id))
            ->with(['pdoDetail.expenseItem', 'pdoDetail.pdoHeader.plantationUnit'])
            ->get()
            ->keyBy('id');

        // Pertahankan urutan sesuai entry_ids yang diminta agar output konsisten dengan seleksi user.
        $ordered = collect($entryIds)
            ->map(fn ($id) => $entries->get($id))
            ->filter();

        $rows = [];

        foreach ($ordered as $entry) {
            $pdoDetail = $entry->pdoDetail;
            $pdoHeader = $pdoDetail?->pdoHeader;
            $expenseItem = $pdoDetail?->expenseItem;
            $plantationUnit = $pdoHeader?->plantationUnit;

            $pdoNumber = $pdoHeader?->pdo_number ?? '—';
            $itemCode  = $expenseItem?->code ?? $pdoDetail?->id;
            $itemName  = $expenseItem?->name ?? $pdoDetail?->description ?? '—';
            $unitName  = $plantationUnit?->name ?? '—';
            $tag       = self::UNIT_TAGS[$plantationUnit?->code] ?? '';

            $transactionNumber = $entry->proof_number;
            $transactionDate   = $entry->transaction_date->format('d/m/Y');

            $memo = "{$pdoNumber} - {$itemCode}  {$itemName}";
            if ($entry->explanation) {
                $memo .= " - {$entry->explanation}";
            }

            $isRekeningUtama   = $entry->funding_source === RealizationEntry::FUNDING_REKENING_UTAMA;
            $creditAccountCode = $isRekeningUtama
                ? self::REKENING_UTAMA_ACCOUNT_CODE
                : ($plantationUnit?->account_code_kas_kebun ?: null);
            $creditDescription = $isRekeningUtama
                ? 'Bank BRI 01220101002851560 (an Sofi Hana Nasution)'
                : "Kas Kebun {$unitName}";

            $rows[] = [
                'realization_entry_id' => $entry->id,
                'transaction_number'   => $transactionNumber,
                'transaction_date'     => $transactionDate,
                'debit_row' => [
                    'account_code' => $pdoDetail?->account_number ?: null,
                    'description'  => "{$itemCode} - {$itemName}",
                    'debit'        => $entry->amount,
                    'credit'       => null,
                ],
                'credit_row' => [
                    'account_code' => $creditAccountCode,
                    'description'  => $creditDescription,
                    'debit'        => null,
                    'credit'       => $entry->amount,
                ],
                'memo'                    => $memo,
                'tag'                     => $tag,
                'already_exported'       => $entry->exported_to_journal_at !== null,
                'already_exported_at'    => $entry->exported_to_journal_at?->toIso8601String(),
            ];
        }

        return $rows;
    }

    /**
     * Konversi baris-baris jurnal menjadi konten CSV sesuai template Jurnal by Mekari.
     * Prefix ' dipakai pada TransactionNumber/TransactionDate agar Excel tidak
     * mengubah leading zero / format tanggal saat file dibuka.
     */
    public function toCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, [
            '*TransactionNumber', '*TransactionDate', '*AccountCode', 'Description',
            '*Debit', '*Credit', 'Memo', 'Tags', 'Currency', 'RateToBase',
        ]);

        foreach ($rows as $row) {
            foreach (['debit_row', 'credit_row'] as $side) {
                $line = $row[$side];
                fputcsv($handle, [
                    "'" . $row['transaction_number'],
                    "'" . $row['transaction_date'],
                    $line['account_code'] ?? '',
                    $line['description'],
                    $line['debit'] ?? '',
                    $line['credit'] ?? '',
                    $row['memo'],
                    $row['tag'],
                    '', // Currency
                    '', // RateToBase
                ]);
            }
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Tandai entry sebagai sudah di-export ke jurnal. Dipanggil hanya saat
     * download final (bukan saat preview).
     */
    public function markExported(array $entryIds, User $actor): void
    {
        RealizationEntry::whereIn('id', $entryIds)
            ->whereHas('pdoDetail.pdoHeader', fn ($q) => $q->where('company_id', $actor->company_id))
            ->update([
                'exported_to_journal_at' => now(),
                'exported_to_journal_by' => $actor->id,
            ]);
    }
}
