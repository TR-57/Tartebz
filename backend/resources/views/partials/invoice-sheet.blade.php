@php
    $L = fn ($en, $arText) => $ar ? $arText : $en;
    $status = [
        'paid' => $L('Paid', 'مدفوع'), 'unpaid' => $L('Not paid yet', 'غير مدفوع'), 'failed' => $L('Not paid yet', 'غير مدفوع'),
        'refunded' => $L('Refunded', 'مسترجع'),
    ][$o->payment_status] ?? $o->payment_status;
@endphp
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
      @if ($o->shipping_method_name){{ $L('Delivery', 'التوصيل') }}: {{ $ar && $o->shipping_method_name_ar ? $o->shipping_method_name_ar : $o->shipping_method_name }}<br>@endif
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
