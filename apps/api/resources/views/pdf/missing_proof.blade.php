<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #1a1a1a; margin: 0; }
  .page { padding: 24px 32px; }
  .header { text-align: center; margin-bottom: 18px; border-bottom: 2px solid #b8860b; padding-bottom: 10px; }
  .header h1 { font-size: 14px; font-weight: bold; margin: 0 0 2px; }
  .header p  { font-size: 9px; margin: 0; color: #555; }
  table { width: 100%; border-collapse: collapse; margin-top: 8px; }
  th { background: #fff3cd; font-weight: bold; text-align: center; padding: 5px 6px; border: 1px solid #f5c518; font-size: 8.5px; }
  td { padding: 4px 6px; border: 1px solid #ddd; vertical-align: top; }
  .num { text-align: right; }
  tr:nth-child(even) td { background: #fffdf0; }
  .footer { margin-top: 16px; font-size: 8px; color: #888; text-align: right; }
</style>
</head>
<body>
<div class="page">
  <div class="header">
    <h1>LAPORAN BUKTI BELUM LENGKAP &mdash; DANA OPERASIONAL KEBUN</h1>
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
        <th>#</th>
        <th>No. PDO</th>
        <th>Unit</th>
        <th>Item Biaya</th>
        <th>Keterangan</th>
        <th>Tgl Transaksi</th>
        <th>Nominal</th>
        <th>Dicatat Oleh</th>
      </tr>
    </thead>
    <tbody>
      @foreach($rows as $i => $r)
      <tr>
        <td style="text-align:center">{{ $i + 1 }}</td>
        <td>{{ $r->pdo_number }}</td>
        <td>{{ $r->unit_name }}</td>
        <td>{{ $r->item_name }}</td>
        <td>{{ $r->keterangan }}</td>
        <td style="text-align:center">{{ $r->transaction_date }}</td>
        <td class="num">{{ number_format($r->amount, 0, ',', '.') }}</td>
        <td>{{ $r->recorded_by }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>

  <p style="margin-top:12px;font-size:8px;color:#666">
    Total: {{ $rows->count() }} transaksi belum dilengkapi bukti.
  </p>

  <div class="footer">Sistem Dana Operasional Kebun &mdash; Laporan ini digenerate secara otomatis</div>
</div>
</body>
</html>
