<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #1a1a1a; margin: 0; }
  .page { padding: 24px 32px; }
  .header { text-align: center; margin-bottom: 18px; border-bottom: 2px solid #2d6a2d; padding-bottom: 10px; }
  .header h1 { font-size: 14px; font-weight: bold; margin: 0 0 2px; }
  .header p  { font-size: 9px; margin: 0; color: #555; }
  table { width: 100%; border-collapse: collapse; margin-top: 8px; }
  th { background: #d9ead3; font-weight: bold; text-align: center; padding: 5px 6px; border: 1px solid #aacfaa; font-size: 8.5px; }
  td { padding: 4px 6px; border: 1px solid #ddd; vertical-align: top; }
  .num { text-align: right; }
  .cen { text-align: center; }
  tr:nth-child(even) td { background: #f4fbf4; }
  .badge { display: inline-block; padding: 1px 5px; border-radius: 3px; font-size: 7.5px; font-weight: bold; }
  .badge-ok    { background: #d4edda; color: #155724; }
  .badge-over  { background: #f8d7da; color: #721c24; }
  .badge-proof { background: #fff3cd; color: #856404; }
  .badge-none  { background: #e2e3e5; color: #383d41; }
  .badge-part  { background: #cce5ff; color: #004085; }
  tfoot td    { font-weight: bold; background: #d9ead3; }
  .footer { margin-top: 16px; font-size: 8px; color: #888; text-align: right; }
</style>
</head>
<body>
<div class="page">
  <div class="header">
    <h1>LAPORAN REALISASI DANA OPERASIONAL KEBUN</h1>
    <p>
      @if($filters['period_year'] ?? null)
        Periode: {{ $filters['period_year'] }}{{ isset($filters['period_month']) ? '-' . str_pad($filters['period_month'], 2, '0', STR_PAD_LEFT) : '' }} &nbsp;|&nbsp;
      @endif
      Dicetak: {{ now()->setTimezone('Asia/Jakarta')->format('d/m/Y H:i') }} WIB
    </p>
  </div>

  <table>
    <thead>
      <tr>
        <th>No. PDO</th>
        <th>Unit</th>
        <th>Kategori</th>
        <th>Item Biaya</th>
        <th>No. Akun</th>
        <th>Anggaran</th>
        <th>Transfer</th>
        <th>Realisasi</th>
        <th>Saldo</th>
        <th>%</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      @php $totAnggaran=0; $totTransfer=0; $totReal=0; $totSaldo=0; @endphp
      @foreach($rows as $r)
      @php
        $totAnggaran += $r->amount;
        $totTransfer += $r->total_transfer;
        $totReal     += $r->total_realization;
        $totSaldo    += $r->saldo;
        $badgeClass = match($r->status) {
          'sesuai'          => 'badge-ok',
          'over_budget'     => 'badge-over',
          'belum_bukti'     => 'badge-proof',
          'belum_realisasi' => 'badge-none',
          default           => 'badge-part',
        };
        $badgeLabel = match($r->status) {
          'sesuai'          => 'Sesuai',
          'over_budget'     => 'Over',
          'belum_bukti'     => 'Bukti',
          'belum_realisasi' => 'Belum',
          default           => 'Parsial',
        };
      @endphp
      <tr>
        <td>{{ $r->pdo_number }}</td>
        <td>{{ $r->unit_name }}</td>
        <td>{{ $r->category_name }}</td>
        <td>{{ $r->item_name }}</td>
        <td class="cen">{{ $r->account_number }}</td>
        <td class="num">{{ number_format($r->amount, 0, ',', '.') }}</td>
        <td class="num">{{ number_format($r->total_transfer, 0, ',', '.') }}</td>
        <td class="num">{{ number_format($r->total_realization, 0, ',', '.') }}</td>
        <td class="num">{{ number_format($r->saldo, 0, ',', '.') }}</td>
        <td class="cen">{{ $r->realization_pct }}%</td>
        <td class="cen"><span class="badge {{ $badgeClass }}">{{ $badgeLabel }}</span></td>
      </tr>
      @endforeach
    </tbody>
    <tfoot>
      <tr>
        <td colspan="5">TOTAL</td>
        <td class="num">{{ number_format($totAnggaran, 0, ',', '.') }}</td>
        <td class="num">{{ number_format($totTransfer, 0, ',', '.') }}</td>
        <td class="num">{{ number_format($totReal, 0, ',', '.') }}</td>
        <td class="num">{{ number_format($totSaldo, 0, ',', '.') }}</td>
        <td colspan="2"></td>
      </tr>
    </tfoot>
  </table>

  <div class="footer">Sistem Dana Operasional Kebun &mdash; Laporan ini digenerate secara otomatis</div>
</div>
</body>
</html>
