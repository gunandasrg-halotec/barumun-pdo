<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1a1a1a; margin: 0; }
  .page { padding: 28px 36px; }

  .kop { text-align: center; border-bottom: 2px solid #2d6a2d; padding-bottom: 10px; margin-bottom: 14px; }
  .kop .company { font-size: 15px; font-weight: bold; letter-spacing: 0.5px; margin: 0; }
  .kop .unit { font-size: 10px; color: #444; margin: 2px 0 0; }

  .title-box { border: 1.5px solid #2d6a2d; text-align: center; padding: 6px 0; margin-bottom: 14px; }
  .title-box .title { font-size: 14px; font-weight: bold; letter-spacing: 3px; }

  .identity { width: 100%; margin-bottom: 12px; }
  .identity td { vertical-align: top; padding: 2px 0; font-size: 10px; }
  .identity .label { width: 110px; color: #444; }
  .identity .right { text-align: right; }

  table.items { width: 100%; border-collapse: collapse; margin-top: 6px; }
  table.items thead { display: table-header-group; }
  table.items th { background: #d9ead3; font-weight: bold; text-align: center; padding: 6px; border: 1px solid #aacfaa; font-size: 9.5px; }
  table.items td { padding: 6px; border: 1px solid #ddd; height: 22px; }
  table.items .num { text-align: right; }
  table.items .center { text-align: center; }
  table.items .empty-row td { height: 22px; }

  .terbilang { margin-top: 10px; font-style: italic; font-size: 10px; }
  .terbilang .label { font-style: normal; font-weight: bold; }

  table.jumlah { width: 100%; margin-top: 8px; border-collapse: collapse; }
  table.jumlah td { padding: 7px 8px; border: 1px solid #aacfaa; background: #d9ead3; font-weight: bold; }
  table.jumlah .num { text-align: right; }

  table.signatures { width: 100%; table-layout: fixed; margin-top: 26px; border-collapse: collapse; page-break-inside: avoid; }
  table.signatures td { width: 25%; vertical-align: top; text-align: center; padding: 0 6px; font-size: 9px; }
  table.signatures .sig-title { font-weight: bold; margin-bottom: 4px; }
  table.signatures .sig-space { height: 70px; border-bottom: 1px solid #999; margin-bottom: 4px; }
  table.signatures .sig-name { margin-top: 2px; }
  table.signatures .sig-date { margin-top: 2px; color: #555; }

  .footer { margin-top: 20px; font-size: 8px; color: #888; text-align: center; }
</style>
</head>
<body>
<div class="page">

  <div class="kop">
    <p class="company">PERKEBUNAN BARUMUN PALMA NAULI</p>
    <p class="unit">{{ $unit->name }}</p>
  </div>

  <div class="title-box">
    <span class="title">PETTY CASH VOUCHER</span>
  </div>

  <table class="identity">
    <tr>
      <td class="label">Dibayarkan Kepada</td>
      <td>: {{ $voucher->paid_to }}</td>
      <td class="right label">No.</td>
      <td class="right">: {{ $voucher->voucher_number }}</td>
    </tr>
    <tr>
      <td class="label"></td>
      <td></td>
      <td class="right label">TGL</td>
      <td class="right">: {{ $voucher->voucher_date->format('d/m/Y') }}</td>
    </tr>
  </table>

  <table class="items">
    <thead>
      <tr>
        <th style="width:32px">NO</th>
        <th>KETERANGAN</th>
        <th style="width:130px">JUMLAH (Rp)</th>
      </tr>
    </thead>
    <tbody>
      @foreach($rows as $i => $row)
      <tr>
        <td class="center">{{ $i + 1 }}</td>
        <td>{{ $row->description }}</td>
        <td class="num">{{ number_format($row->amount, 0, ',', '.') }}</td>
      </tr>
      @endforeach
      @for($i = $rows->count(); $i < 5; $i++)
      <tr class="empty-row">
        <td class="center">{{ $i + 1 }}</td>
        <td>&nbsp;</td>
        <td>&nbsp;</td>
      </tr>
      @endfor
    </tbody>
  </table>

  <div class="terbilang">
    <span class="label">Terbilang:</span> {{ $terbilang }}
  </div>

  <table class="jumlah">
    <tr>
      <td>JUMLAH PENERIMAAN</td>
      <td class="num" style="width:150px">Rp {{ number_format($total, 0, ',', '.') }}</td>
    </tr>
  </table>

  <table class="signatures">
    <tr>
      <td>
        <div class="sig-title">Dibuat Oleh (Kasir)</div>
        <div class="sig-space"></div>
        <div class="sig-name">( ......................... )</div>
        <div class="sig-date">Tgl. .........................</div>
      </td>
      <td>
        <div class="sig-title">Diperiksa Oleh</div>
        <div class="sig-space"></div>
        <div class="sig-name">( ......................... )</div>
        <div class="sig-date">Tgl. .........................</div>
      </td>
      <td>
        <div class="sig-title">Diketahui &amp; disetujui Oleh</div>
        <div class="sig-space"></div>
        <div class="sig-name">( ......................... )</div>
        <div class="sig-date">Tgl. .........................</div>
      </td>
      <td>
        <div class="sig-title">Penerima</div>
        <div class="sig-space"></div>
        <div class="sig-name">( ......................... )</div>
        <div class="sig-date">Tgl. .........................</div>
      </td>
    </tr>
  </table>

  <div class="footer">PDO: {{ $pdoNumber }} &middot; Dicetak: {{ now()->setTimezone('Asia/Jakarta')->format('d/m/Y H:i') }} WIB</div>
</div>
</body>
</html>
