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
