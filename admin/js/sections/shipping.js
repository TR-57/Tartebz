import { api, esc, pill, busy, toast, showErrors, clearErrors, icon, confirmBox, money, isAr } from '../core.js';

/* Owner: Shipping rates. Each destination (a country, a group of countries, or * for everywhere else) offers its own
   delivery methods – by air, by road, home delivery … – each with its price and delivery time. The same model as
   Sniper Safety; the server (App\Support\Shipping::price) and the shop (TZ.methodPrice) price them the same way. */

const PRICING = [['flat', 'Fixed price'], ['formula', 'Base price + per extra weight'], ['table', 'Weight table']];

/* The price of one method for a parcel – a twin of App\Support\Shipping::price(). null = not offered (too heavy). */
function priceOf(m, kg, subtotal) {
  const num = (v) => (v === '' || v == null || isNaN(+v) ? null : +v);
  kg = Math.max(0, kg || 0);
  if (num(m.maxKg) != null && kg > num(m.maxKg)) return null;
  const step = num(m.stepKg) || 0; const per = num(m.perStep) || 0;
  const steps = (over) => (step > 0 && over > 0 ? Math.ceil(Math.round((over / step) * 1e6) / 1e6) : 0);
  let p;
  if (m.pricing === 'formula') p = (num(m.base) || 0) + steps(kg - (num(m.firstKg) || 0)) * per;
  else if (m.pricing === 'table') {
    const bands = (m.table || []).slice().sort((a, b) => a[0] - b[0]);
    const hit = bands.find((b) => kg <= +b[0] + 1e-9);
    if (hit) p = +hit[1]; else { const last = bands[bands.length - 1] || [0, 0]; p = +last[1] + steps(kg - last[0]) * per; }
  } else p = num(m.base) || 0;
  if (num(m.min) != null && p < num(m.min)) p = num(m.min);
  if (num(m.max) != null && p > num(m.max)) p = num(m.max);
  if (num(m.freeOver) != null && subtotal >= num(m.freeOver)) return 0;
  return Math.round(Math.max(0, p) * 1000) / 1000;
}

const blankMethod = () => ({ id: '', name: { en: '', ar: '' }, pricing: 'flat', base: 0, firstKg: 1, stepKg: 0.5, perStep: 0, table: [[0.5, 0]], min: null, max: null, freeOver: null, maxKg: null, etaMin: null, etaMax: null, active: true });
const nv = (v) => (v == null ? '' : v);

export default {
  key: 'shipping', label: 'Shipping rates', icon: 'truck', role: 'owner', group: 'settings',
  async view(main, route, alive) {
    const r = await api('shipping');
    if (!alive()) return;
    let dests = JSON.parse(JSON.stringify(r.destinations));
    // Listeners go on a box of this view's own: the page's <main> stays when another section opens.
    main.innerHTML = '<div class="rates-root"></div>';
    const root = main.firstElementChild;

    const num = (name, label, value, o = {}) => `<div class="field${o.cls ? ' ' + o.cls : ''}" data-field="${esc(name)}"><label>${esc(label)}</label>
      <input class="input" type="number" min="0" step="${o.step || '0.001'}" dir="ltr" data-k="${esc(o.k)}" value="${esc(nv(value))}" ${o.ph ? `placeholder="${esc(o.ph)}"` : ''}>${o.hint ? `<span class="hint">${esc(o.hint)}</span>` : ''}</div>`;

    const methodHtml = (m, di, mi) => {
      const k = `destinations.${di}.methods.${mi}`;
      const pr = m.pricing;
      return `<div class="method card${m.active === false ? ' off' : ''}" data-d="${di}" data-m="${mi}">
        <div class="method-head">
          <label class="switch"><input type="checkbox" data-k="active" ${m.active !== false ? 'checked' : ''}><span class="sw" aria-hidden="true"></span><span>${m.active !== false ? 'On' : 'Off'}</span></label>
          <span class="grow"></span>
          <button type="button" class="iconbtn" data-act="mup" aria-label="Move up" ${mi === 0 ? 'disabled' : ''}>${icon('up')}</button>
          <button type="button" class="iconbtn" data-act="mdown" aria-label="Move down" ${mi === dests[di].methods.length - 1 ? 'disabled' : ''}>${icon('down')}</button>
          <button type="button" class="iconbtn" data-act="mdel" aria-label="Remove method">${icon('close')}</button></div>
        <div class="form-grid">
          <div class="field" data-field="${k}.name"><label>Name (English)</label><input class="input" data-k="name.en" value="${esc(m.name.en)}" placeholder="By air" dir="ltr"></div>
          <div class="field"><label>Name (Arabic)</label><input class="input" data-k="name.ar" value="${esc(m.name.ar)}" placeholder="شحن جوي" dir="rtl"></div>
          <div class="field full"><label>How it is priced</label><select data-k="pricing">${PRICING.map(([v, l]) => `<option value="${v}"${pr === v ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select></div>
          ${pr === 'flat' ? num(`${k}.base`, 'Price (BHD)', m.base, { k: 'base' }) : ''}
          ${pr === 'formula' ? num(`${k}.base`, 'Base price (BHD)', m.base, { k: 'base' }) + num(`${k}.firstKg`, 'Base price covers up to (kg)', m.firstKg, { k: 'firstKg', step: '0.1' })
            + num(`${k}.stepKg`, 'Then every (kg)', m.stepKg, { k: 'stepKg', step: '0.1' }) + num(`${k}.perStep`, 'adds (BHD)', m.perStep, { k: 'perStep' }) : ''}
        </div>
        ${pr === 'table' ? `<div class="rate-table" data-field="${k}.table">${(m.table || []).map((row, t) => `<div class="rate-row" data-field="${k}.table.${t}"><span class="muted small">up to</span>
            <input class="input" type="number" step="0.1" min="0" value="${esc(row[0])}" data-t="${t}" data-c="0" dir="ltr" aria-label="kg" style="width:80px"> <span class="muted small">kg</span>
            <input class="input" type="number" step="0.001" min="0" value="${esc(row[1])}" data-t="${t}" data-c="1" dir="ltr" aria-label="Price" style="width:100px"> <span class="muted small">BHD</span>
            <button type="button" class="iconbtn" data-act="trm" data-t="${t}" aria-label="Remove">${icon('close')}</button></div>`).join('')}
          <button type="button" class="btn btn-sm" data-act="tadd">${icon('plus')} Add a row</button>
          <div class="form-grid" style="margin-top:8px">${num(`${k}.stepKg`, 'Above the last row: every (kg)', m.stepKg, { k: 'stepKg', step: '0.1' })}${num(`${k}.perStep`, 'adds (BHD)', m.perStep, { k: 'perStep' })}</div></div>` : ''}
        <details class="method-more" ${m.min != null || m.max != null || m.freeOver != null || m.maxKg != null ? 'open' : ''}><summary class="muted small">Limits and free delivery</summary>
          <div class="form-grid">${num(`${k}.min`, 'Lowest price (BHD)', m.min, { k: 'min', ph: 'none' })}${num(`${k}.max`, 'Highest price (BHD)', m.max, { k: 'max', ph: 'none' })}
            ${num(`${k}.freeOver`, 'Free when the order reaches (BHD)', m.freeOver, { k: 'freeOver', ph: 'never' })}${num(`${k}.maxKg`, 'Not offered above (kg)', m.maxKg, { k: 'maxKg', step: '0.1', ph: 'no limit' })}</div></details>
        <div class="form-grid">${num(`${k}.etaMin`, 'Delivery time: from (days)', m.etaMin, { k: 'etaMin', step: '1' })}${num(`${k}.etaMax`, 'to (days)', m.etaMax, { k: 'etaMax', step: '1' })}</div>
      </div>`;
    };

    const destHtml = (d, di) => `<div class="card dest" data-d="${di}">
      <div class="card-head"><h2><span data-noi18n>${esc((isAr() ? d.name.ar : d.name.en) || d.name.en || '…')}</span></h2>
        <span>${(d.countries || []).includes('*') ? pill('Everywhere else', 'blue') : ''}
          <button type="button" class="iconbtn" data-act="ddel" aria-label="Remove destination">${icon('close')}</button></span></div>
      <div class="form-grid">
        <div class="field" data-field="destinations.${di}.name"><label>Name (English)</label><input class="input" data-dk="name.en" value="${esc(d.name.en)}" placeholder="GCC" dir="ltr"></div>
        <div class="field"><label>Name (Arabic)</label><input class="input" data-dk="name.ar" value="${esc(d.name.ar)}" placeholder="دول الخليج" dir="rtl"></div>
        <div class="field full" data-field="destinations.${di}.countries"><label>Countries (2-letter codes; * = every other country)</label>
          <input class="input" data-dk="countries" value="${esc((d.countries || []).join(', '))}" placeholder="SA, AE, QA, KW, OM" dir="ltr"></div></div>
      <h3>Delivery methods</h3><p class="muted small">The customer chooses one at checkout, in this order. Turn one off to hide it.</p>
      <div class="methods" data-field="destinations.${di}.methods">${d.methods.map((m, mi) => methodHtml(m, di, mi)).join('')}</div>
      <button type="button" class="btn btn-sm" data-act="madd">${icon('plus')} Add a delivery method</button></div>`;

    const check = () => {
      const box = root.querySelector('#pcheck-out');
      if (!box) return;
      const kg = +root.querySelector('#pc-kg').value || 0; const sub = +root.querySelector('#pc-sub').value || 0;
      const cc = root.querySelector('#pc-cc').value.trim().toUpperCase();
      const d = dests.find((x) => (x.countries || []).includes(cc)) || dests.find((x) => (x.countries || []).includes('*'));
      if (!d) { box.innerHTML = '<p class="muted">We do not deliver there.</p>'; return; }
      const rows = d.methods.filter((m) => m.active !== false).map((m) => {
        const p = priceOf(m, kg, sub);
        return `<li><span data-noi18n>${esc((isAr() ? m.name.ar : m.name.en) || m.name.en)}</span>: <b data-noi18n>${p == null ? '—' : p === 0 ? '0' : esc(money(p))}</b>${p == null ? ' <span class="muted small">Not offered at this weight</span>' : p === 0 ? ' <span class="muted small">Free</span>' : ''}</li>`;
      });
      box.innerHTML = rows.length ? `<ul class="plist">${rows.join('')}</ul>` : '<p class="muted">No method is on.</p>';
    };

    const draw = () => {
      root.innerHTML = `
        <div class="phead"><div><h1>Shipping rates</h1><p class="sub">What the customer pays for delivery, per destination and delivery method (BHD). Choose which methods go through SMSA in SMSA shipping.</p></div>
          <div class="actions"><a class="btn" href="#/smsa">${icon('truck')} SMSA shipping</a></div></div>
        <form id="rf" novalidate>
          <div id="dests">${dests.map(destHtml).join('')}</div>
          <p><button type="button" class="btn" data-act="dadd">${icon('plus')} Add a destination</button></p>
          <div class="card"><h2>Price check</h2><div class="form-grid">
            <div class="field"><label for="pc-cc">Country</label><input id="pc-cc" class="input" value="SA" maxlength="2" dir="ltr"></div>
            <div class="field"><label for="pc-kg">Parcel weight (kg)</label><input id="pc-kg" class="input" type="number" min="0" step="0.1" value="1" dir="ltr"></div>
            <div class="field"><label for="pc-sub">Order value (BHD)</label><input id="pc-sub" class="input" type="number" min="0" step="0.5" value="10" dir="ltr"></div></div>
            <div id="pcheck-out"></div></div>
          <div class="savebar"><button type="submit" class="btn btn-primary" id="save">Save</button></div>
        </form>`;
      check();
    };
    draw();

    const at = (el) => { const m = el.closest('[data-m]'); const d = el.closest('[data-d]'); return { di: d ? +d.dataset.d : -1, mi: m ? +m.dataset.m : -1 }; };
    root.addEventListener('input', (e) => {
      const el = e.target;
      if (el.id && el.id.startsWith('pc-')) { check(); return; }
      const { di, mi } = at(el);
      if (di < 0) return;
      if (el.dataset.dk) {
        const d = dests[di];
        if (el.dataset.dk === 'countries') d.countries = el.value.split(/[\s,]+/).map((c) => c.trim().toUpperCase()).filter(Boolean);
        else d.name[el.dataset.dk.split('.')[1]] = el.value;
      } else if (mi >= 0) {
        const m = dests[di].methods[mi];
        if (el.dataset.t != null && el.dataset.c != null) m.table[+el.dataset.t][+el.dataset.c] = el.value === '' ? '' : +el.value;
        else if (el.dataset.k === 'name.en' || el.dataset.k === 'name.ar') m.name[el.dataset.k.split('.')[1]] = el.value;
        else if (el.dataset.k && el.dataset.k !== 'active' && el.dataset.k !== 'pricing') m[el.dataset.k] = el.value === '' ? null : +el.value;
      }
      check();
    });
    root.addEventListener('change', (e) => {
      const el = e.target;
      const { di, mi } = at(el);
      if (di < 0 || mi < 0) return;
      const m = dests[di].methods[mi];
      if (el.dataset.k === 'pricing') { m.pricing = el.value; if (m.pricing === 'table' && !(m.table || []).length) m.table = [[0.5, 0]]; draw(); }
      if (el.dataset.k === 'active') { m.active = el.checked; draw(); }
    });
    root.addEventListener('click', async (e) => {
      const b = e.target.closest('[data-act]');
      if (!b) return;
      const { di, mi } = at(b);
      const a = b.dataset.act;
      if (a === 'dadd') dests.push({ id: '', name: { en: '', ar: '' }, countries: [], methods: [blankMethod()] });
      else if (a === 'ddel') { if (!(await confirmBox('Remove this destination and its delivery methods?', { ok: 'Remove', danger: true }))) return; dests.splice(di, 1); }
      else if (a === 'madd') dests[di].methods.push(blankMethod());
      else if (a === 'mdel') { if (dests[di].methods.length === 1) { toast('Keep at least one delivery method – turn it off instead.', 'err'); return; } dests[di].methods.splice(mi, 1); }
      else if (a === 'mup' || a === 'mdown') { const list = dests[di].methods; const j = a === 'mup' ? mi - 1 : mi + 1; [list[mi], list[j]] = [list[j], list[mi]]; }
      else if (a === 'tadd') { const t = dests[di].methods[mi].table; const last = t[t.length - 1] || [0, 0]; t.push([+(+last[0] + 0.5).toFixed(1), last[1]]); }
      else if (a === 'trm') dests[di].methods[mi].table.splice(+b.dataset.t, 1);
      else return;
      draw();
    });
    root.addEventListener('submit', async (e) => {
      e.preventDefault();
      const form = root.querySelector('#rf');
      clearErrors(form);
      try {
        const res = await busy(root.querySelector('#save'), () => api('shipping', { method: 'PUT', body: { destinations: dests } }));
        dests = JSON.parse(JSON.stringify(res.destinations)); draw(); toast('Saved');
      } catch (err) { showErrors(root.querySelector('#rf'), err); }
    });
  },
};
