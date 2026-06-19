<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #1a1a1a; margin: 0; }
  .page { padding: 24px 32px; }
  .header { text-align: center; margin-bottom: 18px; border-bottom: 2px solid #c0392b; padding-bottom: 10px; }
  .header h1 { font-size: 14px; font-weight: bold; margin: 0 0 2px; }
  .header p  { font-size: 9px; margin: 0; color: #555; }
  table { width: 100%; border-collapse: collapse; margin-top: 8px; }
  th { background: #fce8e6; font-weight: bold; text-align: center; padding: 5px 6px; border: 1px solid #e8a8a5; font-size: 8.5px; }
  td { padding: 4px 6px; border: 1px solid #ddd; vertical-align: top; }
  .num { text-align: right; }
  .red { color: #c0392b; font-weight: bold; }
  tr:nth-child(even) td { background: #fff5f5; }
  tfoot td { font-weight: bold; background: #fce8e6; }
  .footer { margin-top: 16px; font-size: 8px; color: #888; text-align: right; }
</style>
</head>
<body>
<div class="page">
  <div class="header">
    <h1>LAPORAN OVER BUDGET &mdash; DANA OPERASIONAL KEBUN</h1>
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
        <th>Periode</th>
        <th>Kategori</th>
        <th>Item Biaya</th>
        <th>Anggaran</th>
        <th>Transfer</th>
        <th>Realisasi</th>
        <th>Selisih Over</th>
      </tr>
    </thead>
    <tbody>
      @php $totTransfer=0; $totReal=0; $totOver=0; @endphp
      @foreach($rows as $r)
      @php
        $over = $r->total_realization - $r->total_transfer;
        $totTransfer += $r->total_transfer;
        $totReal     += $r->total_realization;
        $totOver     += $over;
      @endphp
      <tr>
        <td>{{ $r->pdo_number }}</td>
        <td>{{ $r->unit_name }}</td>
        <td>{{ $r->period_year }}-{{ str_pad($r->period_month, 2, '0', STR_PAD_LEFT) }}</td>
        <td>{{ $r->category_name }}</td>
        <td>{{ $r->item_name }}</td>
        <td class="num">{{ number_format($r->amount, 0, ',', '.') }}</td>
        <td class="num">{{ number_format($r->total_transfer, 0, ',', '.') }}</td>
        <td class="num">{{ number_format($r->total_realization, 0, ',', '.') }}</td>
        <td class="num red">{{ number_format($over, 0, ',', '.') }}</td>
      </tr>
      @endforeach
    </tbody>
    <tfoot>
      <tr>
        <td colspan="5">TOTAL</td>
        <td class="num"></td>
        <td class="num">{{ number_format($totTransfer, 0, ',', '.') }}</td>
        <td class="num">{{ number_format($totReal, 0, ',', '.') }}</td>
        <td class="num red">{{ number_format($totOver, 0, ',', '.') }}</td>
      </tr>
    </tfoot>
  </table>

  <div class="footer">Sistem Dana Operasional Kebun &mdash; Laporan ini digenerate secara otomatis</div>
</div>
</body>
</html>
