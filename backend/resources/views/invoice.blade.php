@php
    $L = fn ($en, $arText) => ($lang ?? '') === 'both' ? $en.' / '.$arText : ($ar ? $arText : $en);
    $base = $publicBase ?? '';
    $status = [
        'paid' => $L('Paid', 'مدفوع'), 'unpaid' => $L('Not paid yet', 'غير مدفوع'), 'failed' => $L('Not paid yet', 'غير مدفوع'),
        'refunded' => $L('Refunded', 'مسترجع'),
    ][$o->payment_status] ?? $o->payment_status;
@endphp
<!doctype html>
<html lang="{{ $lang }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/assets/img/favicon-32.png" type="image/png" sizes="32x32">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $o->paid_at ? $L('Invoice', 'فاتورة') : $L('Proforma invoice', 'فاتورة مبدئية') }} {{ $o->number }}</title>
<style>
@include('partials.invoice-css')
</style>
</head>
<body class="{{ request('paper') === 'receipt' ? 'receipt' : '' }}">
<div class="tools">
  <button type="button" onclick="window.print()">{{ $L('Print', 'طباعة') }}</button>
  <button type="button" onclick="window.print()" title="{{ $L('Choose “Save as PDF” as the printer', 'اختر «حفظ بتنسيق PDF» كطابعة') }}">{{ $L('Download PDF', 'تحميل PDF') }}</button>
  <a href="?{{ $base }}lang={{ $ar ? 'en' : 'ar' }}{{ request('paper') === 'receipt' ? '&paper=receipt' : '' }}">{{ $ar ? 'English' : 'العربية' }}</a>
  <a href="?{{ $base }}lang=both">{{ $L('Both languages', 'اللغتين') }}</a>
  <a href="?{{ $base }}lang={{ $lang }}{{ request('paper') === 'receipt' ? '' : '&paper=receipt' }}">{{ request('paper') === 'receipt' ? $L('A4 page', 'صفحة A4') : $L('Receipt printer', 'طابعة إيصالات') }}</a>
</div>
@include('partials.invoice-sheet')
</body>
</html>
