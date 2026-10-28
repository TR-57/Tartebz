@php
    $L = fn ($en, $arText) => $ar ? $arText : $en;
    $status = [
        'paid' => $L('Paid', 'مدفوع'), 'unpaid' => $L('Not paid yet', 'غير مدفوع'), 'failed' => $L('Not paid yet', 'غير مدفوع'),
        'refunded' => $L('Refunded', 'مسترجع'),
    ][$o->payment_status] ?? $o->payment_status;
@endphp
<!doctype html>
<html lang="{{ $lang }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $L('Invoice', 'فاتورة') }} {{ $o->number }}</title>
<style>
  @font-face { font-family: Alexandria; src: url('/assets/fonts/alexandria-arabic.woff2') format('woff2'); unicode-range: U+0600-06FF, U+0750-077F, U+FB50-FDFF, U+FE70-FEFF; font-weight: 300 800; }
  @font-face { font-family: Alexandria; src: url('/assets/fonts/alexandria-latin.woff2') format('woff2'); font-weight: 300 800; }
  :root { --ink: #1F2024; --muted: #6b6b73; --line: #e6e3da; --red: #E30613; }
  * { box-sizing: border-box; }
  body { margin: 0; background: #f4f2ec; color: var(--ink); font: 14px/1.55 Alexandria, system-ui, sans-serif; }
  .sheet { max-width: 800px; margin: 24px auto; background: #fff; padding: 36px 40px; border-radius: 14px; box-shadow: 0 10px 30px -18px rgba(0,0,0,.35); }
  header { display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; border-bottom: 3px solid var(--red); padding-bottom: 18px; }
  .brand img { max-height: 70px; max-width: 180px; }
  .brand b { display: block; font-size: 20px; }
  .shop { font-size: 12px; color: var(--muted); }
  h1 { margin: 0; font-size: 26px; letter-spacing: .02em; }
  .meta { text-align: end; }
  .meta div { font-size: 13px; }
  .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin: 22px 0; }
  .box h3 { margin: 0 0 6px; font-size: 12px; text-transform: uppercase; color: var(--muted); letter-spacing: .06em; }
  table { width: 100%; border-collapse: collapse; margin-top: 6px; }
  th { text-align: start; font-size: 12px; color: var(--muted); font-weight: 600; border-bottom: 1px solid var(--line); padding: 8px 6px; }
  td { padding: 10px 6px; border-bottom: 1px solid var(--line); vertical-align: top; }
  .num { text-align: end; white-space: nowrap; }
  bdi { unicode-bidi: isolate; }
  .totals { width: 320px; margin-inline-start: auto; margin-top: 14px; }
  .totals td { border: 0; padding: 5px 6px; }
  .totals .grand td { font-size: 18px; font-weight: 800; border-top: 2px solid var(--ink); padding-top: 10px; }
  .pill { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; background: #e9f7ee; color: #146c35; }
  .pill.no { background: #fff3d6; color: #8a5a00; }
  footer { margin-top: 28px; font-size: 12px; color: var(--muted); text-align: center; }
  .tools { max-width: 800px; margin: 16px auto 0; display: flex; gap: 10px; justify-content: center; }
  .tools button, .tools a { font: inherit; font-weight: 700; border: 0; border-radius: 999px; padding: 10px 22px; cursor: pointer; background: var(--red); color: #fff; text-decoration: none; }
  .tools a { background: #fff; color: var(--ink); border: 1px solid var(--line); }
  @media (max-width: 640px) { .sheet { margin: 0; border-radius: 0; padding: 22px 16px; } .grid { grid-template-columns: 1fr; } .totals { width: 100%; } header { flex-direction: column; } .meta { text-align: start; } }
  /* Receipt printers (80 mm): print with ?paper=receipt */
  body.receipt .sheet { max-width: 80mm; padding: 4mm; box-shadow: none; border-radius: 0; font-size: 11px; }
  body.receipt header, body.receipt .grid { display: block; } body.receipt .meta { text-align: start; margin-top: 8px; }
  body.receipt .totals { width: 100%; } body.receipt h1 { font-size: 16px; }
  @media print {
    body { background: #fff; } .sheet { margin: 0; box-shadow: none; border-radius: 0; max-width: none; padding: 0; } .tools { display: none; }
    @page { margin: 14mm; } body.receipt { width: 80mm; } body.receipt .sheet { padding: 0; }
  }
</style>
</head>
<body class="{{ request('paper') === 'receipt' ? 'receipt' : '' }}">
<div class="tools">
  <button type="button" onclick="window.print()">{{ $L('Print', 'طباعة') }}</button>
  <a href="?lang={{ $ar ? 'en' : 'ar' }}{{ request('paper') === 'receipt' ? '&paper=receipt' : '' }}">{{ $ar ? 'English' : 'العربية' }}</a>
  <a href="?lang={{ $lang }}{{ request('paper') === 'receipt' ? '' : '&paper=receipt' }}">{{ request('paper') === 'receipt' ? $L('A4 page', 'صفحة A4') : $L('Receipt printer', 'طابعة إيصالات') }}</a>
</div>
<main class="sheet">
  <header>
    <div class="brand">
      @if ($shop['logo'])<img src="{{ $shop['logo'] }}" alt="{{ $shop['name'] }}">@endif
      <b>{{ $shop['name'] }}</b>
      <div class="shop">
        {{ $shop['legal'] }}<br>
        @if ($shop['cr']){{ $L('CR', 'س.ت') }}: <bdi>{{ $shop['cr'] }}</bdi>@endif
        @if ($shop['vat']) · {{ $L('VAT no.', 'الرقم الضريبي') }}: <bdi>{{ $shop['vat'] }}</bdi>@endif<br>
        {{ $shop['address'] }}@if ($shop['area']), {{ $shop['area'] }}@endif<br>
        <bdi>{{ $shop['phone'] }}</bdi> · <bdi>{{ $shop['email'] }}</bdi>
      </div>
    </div>
    <div class="meta">
      <h1>{{ $L('Invoice', 'فاتورة') }}</h1>
      <div>{{ $L('Order', 'رقم الطلب') }}: <b><bdi>{{ $o->number }}</bdi></b></div>
      <div>{{ $L('Date', 'التاريخ') }}: <bdi>{{ \Illuminate\Support\Carbon::parse($o->created_at)->format('d/m/Y H:i') }}</bdi></div>
      <div><span class="pill {{ $o->payment_status === 'paid' ? '' : 'no' }}">{{ $status }}</span></div>
    </div>
  </header>

  <section class="grid">
    <div class="box">
      <h3>{{ $L('Bill to / Deliver to', 'العميل / عنوان التوصيل') }}</h3>
      <b>{{ trim($o->first_name.' '.$o->last_name) }}</b><br>
      <bdi>{{ $o->phone }}</bdi>@if ($o->email)<br><bdi>{{ $o->email }}</bdi>@endif<br>
      {{ $o->address1 }}@if ($o->address2), {{ $o->address2 }}@endif<br>
      {{ implode(', ', array_filter([$o->city, $o->state, $o->country])) }}
    </div>
    <div class="box">
      <h3>{{ $L('Payment', 'الدفع') }}</h3>
      {{ $o->payment_method === 'tap' ? $L('Card (online)', 'بطاقة (أونلاين)') : $L('WhatsApp order', 'طلب واتساب') }}<br>
      @if ($o->paid_at){{ $L('Paid on', 'تاريخ الدفع') }}: <bdi>{{ \Illuminate\Support\Carbon::parse($o->paid_at)->format('d/m/Y') }}</bdi><br>@endif
      @if ($o->awb){{ $L('SMSA waybill', 'بوليصة سمسا') }}: <bdi>{{ $o->awb }}</bdi>@endif
    </div>
  </section>

  <table>
    <thead><tr><th>{{ $L('Product', 'المنتج') }}</th><th class="num">{{ $L('Price', 'السعر') }}</th><th class="num">{{ $L('Qty', 'الكمية') }}</th><th class="num">{{ $L('Total', 'الإجمالي') }}</th></tr></thead>
    <tbody>
    @foreach ($items as $i)
      <tr><td>{{ $ar && $i->name_ar ? $i->name_ar : $i->name }}</td><td class="num"><bdi>{{ $money((float) $i->price) }}</bdi></td><td class="num">{{ $i->qty }}</td><td class="num"><bdi>{{ $money((float) $i->line_total) }}</bdi></td></tr>
    @endforeach
    </tbody>
  </table>

  <table class="totals">
    <tr><td>{{ $L('Subtotal', 'المجموع الفرعي') }}</td><td class="num"><bdi>{{ $money((float) $o->subtotal) }}</bdi></td></tr>
    <tr><td>{{ $L('Delivery', 'التوصيل') }} <small>(<bdi>{{ number_format($o->weight_grams / 1000, 2) }} kg</bdi>)</small></td><td class="num"><bdi>{{ $money((float) $o->shipping) }}</bdi></td></tr>
    @if ((float) $o->discount > 0)<tr><td>{{ $L('Discount', 'الخصم') }}</td><td class="num"><bdi>− {{ $money((float) $o->discount) }}</bdi></td></tr>@endif
    <tr class="grand"><td>{{ $L('Total', 'الإجمالي') }}</td><td class="num"><bdi>{{ $o->charge_currency }} {{ number_format((float) $o->charge_amount, in_array($o->charge_currency, ['BHD', 'KWD', 'OMR', 'JOD'], true) ? 3 : 2) }}</bdi></td></tr>
    @if ($refunded > 0)<tr><td>{{ $L('Refunded', 'المسترجع') }}</td><td class="num"><bdi>− {{ $o->charge_currency }} {{ number_format($refunded, in_array($o->charge_currency, ['BHD', 'KWD', 'OMR', 'JOD'], true) ? 3 : 2) }}</bdi></td></tr>@endif
  </table>

  <footer>{{ $L('Thank you for your order!', 'شكرًا لطلبك!') }}</footer>
</main>
</body>
</html>
