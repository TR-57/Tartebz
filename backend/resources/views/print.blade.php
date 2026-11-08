@php
    $T = fn ($en, $arText) => $ar ? $arText : $en;
    $labels = collect($docs)->filter(fn ($d) => $d['label'])->count();
    $missing = collect($docs)->filter(fn ($d) => $d['noLabel'])->map(fn ($d) => $d['o']->number)->values();
@endphp
<!doctype html>
<html lang="{{ $lang }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/assets/img/favicon-32.png" type="image/png" sizes="32x32">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $T('Print', 'طباعة') }} – {{ collect($docs)->map(fn ($d) => $d['o']->number)->implode(', ') }}</title>
<style>
@include('partials.invoice-css')
  .doc + .doc, .label { break-before: page; }
  .label { max-width: 4in; margin: 24px auto; background: #fff; box-shadow: 0 10px 30px -18px rgba(0,0,0,.35); }
  .label img { display: block; width: 100%; height: auto; }
  .label .wait { padding: 40px 16px; text-align: center; color: var(--muted); font-size: 13px; }
  .label .err { color: #a4000b; }
  .note { max-width: 800px; margin: 12px auto 0; padding: 10px 14px; border-radius: 10px; background: #fff3d6; color: #6b4600; font-size: 13px; }
  .tools .wait-print[disabled] { opacity: .5; cursor: progress; }
  @page label { size: 4in 6in; margin: 0; }
  @media print {
    .note { display: none; }
    .label { page: label; max-width: none; width: 4in; height: 6in; margin: 0; box-shadow: none; overflow: hidden; }
    .label img { width: 4in; height: 6in; object-fit: contain; }
    .label .wait { display: none; }
  }
</style>
</head>
<body>
<div class="tools">
  <button type="button" class="wait-print" id="print" @if ($labels) disabled @endif>{{ $T('Print', 'طباعة') }}</button>
  <a href="?ids={{ $ids }}&lang={{ $ar ? 'en' : 'ar' }}">{{ $ar ? 'English' : 'العربية' }}</a>
</div>
@if ($labels)
  <p class="note">{{ $T('Each order going outside Bahrain prints its invoice followed by its SMSA waybill (on a 4 × 6 in label page). Set the printer’s paper for each page, or print the waybills on the label printer.', 'كل طلب خارج البحرين تُطبع فاتورته ثم بوليصة سمسا (صفحة ملصق 4 × 6 إنش). اختر ورق الطابعة لكل صفحة، أو اطبع البوالص على طابعة الملصقات.') }}</p>
@endif
@if ($missing->count())
  <p class="note">{{ $T('No SMSA waybill yet for', 'لا توجد بوليصة سمسا بعد للطلب') }}: <bdi>{{ $missing->implode(', ') }}</bdi> – {{ $T('only the invoice prints. Send the order to SMSA first.', 'تُطبع الفاتورة فقط. أرسل الطلب إلى سمسا أولًا.') }}</p>
@endif
@foreach ($docs as $d)
  <div class="doc" @if ($d['ar']) dir="rtl" lang="ar" @else dir="ltr" lang="en" @endif>
    @include('partials.invoice-sheet', $d)
    @if ($d['label'])
      <div class="label" data-src="{{ $d['label'] }}" data-number="{{ $d['o']->number }}"><div class="wait">{{ $T('Loading the SMSA waybill…', 'جارٍ تحميل بوليصة سمسا…') }}</div></div>
    @endif
  </div>
@endforeach
<script type="module">
  const btn = document.getElementById('print');
  btn.addEventListener('click', () => window.print());
  const boxes = [...document.querySelectorAll('.label[data-src]')];
  const failed = @json($T('The SMSA waybill could not be loaded', 'تعذّر تحميل بوليصة سمسا'));
  const ready = () => { btn.disabled = false; if (@json($auto)) setTimeout(() => window.print(), 200); };
  if (!boxes.length) { ready(); } else {
    const pdfjs = await import('/assets/vendor/pdfjs/pdf.js');
    pdfjs.GlobalWorkerOptions.workerSrc = '/assets/vendor/pdfjs/pdf.worker.js';
    for (const box of boxes) {
      try {
        const res = await fetch(box.dataset.src, { credentials: 'same-origin' });
        if (!res.ok) {
          let msg = '';
          try { msg = (await res.json()).error?.message || ''; } catch { /* not JSON */ }
          throw new Error(msg || String(res.status));
        }
        const pdf = await pdfjs.getDocument({ data: new Uint8Array(await res.arrayBuffer()), standardFontDataUrl: '/assets/vendor/pdfjs/standard_fonts/' }).promise;
        box.innerHTML = '';
        let last = box;
        for (let n = 1; n <= pdf.numPages; n++) {
          const page = await pdf.getPage(n);
          const vp = page.getViewport({ scale: 3 });
          const canvas = document.createElement('canvas');
          canvas.width = Math.ceil(vp.width); canvas.height = Math.ceil(vp.height);
          await page.render({ canvas, canvasContext: canvas.getContext('2d'), viewport: vp }).promise;
          const img = new Image();
          img.alt = 'SMSA ' + box.dataset.number;
          img.src = canvas.toDataURL('image/png');
          if (n === 1) { box.appendChild(img); } else {
            const more = document.createElement('div');
            more.className = 'label'; more.appendChild(img); last.after(more); last = more;
          }
        }
      } catch (e) {
        box.innerHTML = '<div class="wait err"></div>';
        box.firstChild.textContent = failed + ' (' + box.dataset.number + '): ' + e.message;
      }
    }
    ready();
  }
</script>
</body>
</html>
