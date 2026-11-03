<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $nameAr }} · نرجع قريبًا · Back soon</title>
<style>
  @font-face { font-family: Alexandria; src: url('/assets/fonts/alexandria-arabic.woff2') format('woff2'); font-weight: 400 900; font-display: swap; unicode-range: U+0600-06FF, U+0750-077F, U+FB50-FDFF, U+FE70-FEFF; }
  @font-face { font-family: Alexandria; src: url('/assets/fonts/alexandria-latin.woff2') format('woff2'); font-weight: 400 900; font-display: swap; }
  * { box-sizing: border-box; }
  html, body { margin: 0; min-height: 100%; }
  body { min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; font: 17px/1.7 Alexandria, system-ui, -apple-system, 'Segoe UI', Tahoma, sans-serif; color: #1F2024;
    background: radial-gradient(circle at 20% 0%, #FF2A35 0, #E30613 45%, #9E0010 100%); }
  .card { width: 100%; max-width: 520px; background: #fff; border-radius: 26px; padding: 34px 26px 28px; text-align: center; box-shadow: 0 30px 70px -30px rgba(0,0,0,.6); }
  .logo { width: 110px; height: 110px; border-radius: 50%; object-fit: cover; margin: -90px auto 12px; display: block; border: 6px solid #fff; background: #E30613; }
  .mark { font-size: 46px; line-height: 1; margin: 0 0 6px; }
  h1 { margin: 0 0 6px; font-size: 28px; font-weight: 900; }
  .en { direction: ltr; color: #6A6B72; }
  h2.en { margin: 0 0 18px; font-size: 19px; font-weight: 700; }
  p { margin: 0 0 8px; }
  hr { border: 0; border-top: 1px dashed #E7E2D6; margin: 18px 0; }
  .btns { display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; margin-top: 18px; }
  a.btn { display: inline-flex; align-items: center; gap: 8px; min-height: 48px; padding: 0 22px; border-radius: 999px; font-weight: 800; text-decoration: none; }
  .wa { background: #25D366; color: #0b3d1f; }
  .ig { background: #1F2024; color: #fff; }
  .dots span { display: inline-block; width: 10px; height: 10px; margin: 0 4px; border-radius: 50%; background: #FFD700; animation: b 1.2s infinite ease-in-out; }
  .dots span:nth-child(2) { animation-delay: .15s; } .dots span:nth-child(3) { animation-delay: .3s; }
  @keyframes b { 0%, 80%, 100% { transform: scale(.5); opacity: .5; } 40% { transform: scale(1); opacity: 1; } }
  @media (prefers-reduced-motion: reduce) { .dots span { animation: none; } }
</style>
</head>
<body>
<main class="card">
  @if ($logo)<img class="logo" src="{{ $logo }}" alt="{{ $nameAr }}">@endif
  <div class="dots" aria-hidden="true"><span></span><span></span><span></span></div>
  <h1>نجهّز لكم شي حلو… نرجع قريبًا</h1>
  <p>{{ $messageAr ?: 'المتجر في صيانة سريعة. اطلبوا الحين على واتساب، أو ارجعوا لنا بعد شوي.' }}</p>
  <hr>
  <h2 class="en">We’re making something sweet – back soon</h2>
  <p class="en">{{ $message ?: 'The shop is down for a quick update. Order on WhatsApp now, or come back in a little while.' }}</p>
  <div class="btns">
    @if ($whatsapp)<a class="btn wa" href="https://wa.me/{{ $whatsapp }}" rel="noopener">واتساب · WhatsApp</a>@endif
    @if ($instagram)<a class="btn ig" href="{{ $instagram }}" rel="noopener">Instagram</a>@endif
  </div>
</main>
</body>
</html>
