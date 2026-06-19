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
  .row-cat  { font-weight: bold; background: #eaf4ea; }
  .row-sub  { background: #f4fbf4; }
  .row-item { }
  .row-total { font-weight: bold; background: #d9ead3; }
  .indent1 { padding-left: 12px; }
  .indent2 { padding-left: 24px; }
  .footer { margin-top: 16px; font-size: 8px; color: #888; text-align: right; }
</style>
</head>
<body>
<div class="page">
  <div class="header">
    <h1>REKAPITULASI DANA OPERASIONAL KEBUN</h1>
    <p>
      @if($filters['period_year'] ?? null)
        Periode: {{ $filters['period_year'] }}{{ isset($filters['period_month']) ? '-' . str_pad($filters['period_month'], 2, '0', STR_PAD_LEFT) : '' }} &nbsp;|&nbsp;
      @endif
      @if($filters['unit_name'] ?? null) Unit: {{ $filters['unit_name'] }} &nbsp;|&nbsp; @endif
      Dicetak: {{ now()->setTimezone('Asia/Jakarta')->format('d/m/Y H:i') }} WIB
    </p>
  </div>

  <table>
    <thead>
      <tr>
        <th style="width:30px">No</th>
        <th style="width:80px">Kode Akun</th>
        <th>Uraian</th>
        <th style="width:100px">Anggaran</th>
        <th style="width:100px">Transfer</th>
        <th style="width:100px">Realisasi</th>
        <th style="width:100px">Saldo</th>
      </tr>
    </thead>
    <tbody>
      @foreach($recap['categories'] as $cat)
      <tr class="row-cat">
        <td style="text-align:center">{{ $cat['no'] }}</td>
        <td>{{ $cat['category_code'] }}</td>
        <td>{{ strtoupper($cat['category_name']) }}</td>
        <td class="num">{{ number_format($cat['subtotal_amount'], 0, ',', '.') }}</td>
        <td class="num">{{ number_format($cat['subtotal_transfer'], 0, ',', '.') }}</td>
        <td class="num">{{ number_format($cat['subtotal_realization'], 0, ',', '.') }}</td>
        <td class="num">{{ number_format($cat['subtotal_saldo'], 0, ',', '.') }}</td>
      </tr>
      @foreach($cat['subcategories'] as $sub)
      <tr class="row-sub">
        <td></td>
        <td>{{ $sub['subcategory_code'] }}</td>
        <td class="indent1">{{ $sub['subcategory_name'] }}</td>
        <td class="num">{{ number_format($sub['subtotal_amount'], 0, ',', '.') }}</td>
        <td class="num">{{ number_format($sub['subtotal_transfer'], 0, ',', '.') }}</td>
        <td class="num">{{ number_format($sub['subtotal_realization'], 0, ',', '.') }}</td>
        <td class="num">{{ number_format($sub['subtotal_saldo'], 0, ',', '.') }}</td>
      </tr>
      @foreach($sub['items'] as $item)
      <tr class="row-item">
        <td></td>
        <td>{{ $item['account_number'] }}</td>
        <td class="indent2">{{ $item['item_name'] }}</td>
        <td class="num">{{ number_format($item['amount'], 0, ',', '.') }}</td>
        <td class="num">{{ number_format($item['total_transfer'], 0, ',', '.') }}</td>
        <td class="num">{{ number_format($item['total_realization'], 0, ',', '.') }}</td>
        <td class="num">{{ number_format($item['saldo'], 0, ',', '.') }}</td>
      </tr>
      @endforeach
      @endforeach
      @endforeach
    </tbody>
    <tfoot>
      <tr class="row-total">
        <td colspan="3">JUMLAH TOTAL</td>
        <td class="num">{{ number_format($recap['grand_total_amount'], 0, ',', '.') }}</td>
        <td class="num">{{ number_format($recap['grand_total_transfer'], 0, ',', '.') }}</td>
        <td class="num">{{ number_format($recap['grand_total_realization'], 0, ',', '.') }}</td>
        <td class="num">{{ number_format($recap['grand_total_saldo'], 0, ',', '.') }}</td>
      </tr>
    </tfoot>
  </table>

  <div class="footer">Sistem Dana Operasional Kebun &mdash; Laporan ini digenerate secara otomatis</div>
</div>
</body>
</html>
