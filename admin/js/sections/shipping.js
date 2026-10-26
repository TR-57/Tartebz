import { api, esc, ltr, pill, toggle, field, busy, toast, toastErr, showErrors, clearErrors, icon, confirmBox } from '../core.js';

/* Owner: delivery prices and SMSA Express (API key, sandbox/live, sender address, connection test). */
export default {
  key: 'shipping', label: 'Shipping', icon: 'truck', role: 'owner', group: 'settings',
  async view(main, route, alive) {
    const r = await api('shipping');
    if (!alive()) return;
    const s = r.smsa;
    const rates = r.rates;
    let flat = rates.flat.map((f) => ({ ...f }));
    let table = rates.table.map((row) => [...row]);
    const key = (name, label, saved) => `<div class="field" data-field="${name}"><label for="${name}">${esc(label)}</label>
      <input id="${name}" name="${name}" type="password" autocomplete="off" dir="ltr" placeholder="${esc(saved.set ? '•••• ' + saved.last4 + ' (saved – type to replace)' : 'API key')}">
      ${saved.set ? `<label class="mini-check"><input type="checkbox" name="clear${name[0].toUpperCase() + name.slice(1)}"> <span>Remove the saved key</span></label>` : ''}</div>`;
    const status = s.active ? (s.mode === 'live' ? pill('Live – real shipments', 'green') : pill('Sandbox – test waybills', 'amber')) : pill('Off', 'hidden');
    const sh = s.shipper;

    main.innerHTML = `
      <div class="phead"><div><h1>Shipping</h1><p class="sub">Delivery prices at checkout, and SMSA Express for waybills, labels and tracking.</p></div></div>
      <div class="steps card"><h2>Going live with SMSA in 4 steps</h2><ol>
        <li>Ask your SMSA account manager for the eCommerce API keys: one for the sandbox and one for live, and your service code (for example EDDL).</li>
        <li>Paste the sandbox key, fill in the sender address, keep Sandbox, switch SMSA on, save, and press “Test connection”.</li>
        <li>Open a paid order (a Tap test order is fine) and book a shipment. You get a test waybill and its label PDF.</li>
        <li>Paste the live key, test it, choose Live and save. Every shipment after that is a real, billable parcel.</li></ol></div>
      <form id="sf" novalidate>
      <div class="grid-2">
        <div class="card"><div class="card-head"><h2>${icon('truck')} SMSA Express</h2>${status}</div>
          ${toggle('enabled', 'Book shipments with SMSA from the order page', s.enabled)}
          <div class="field"><span class="field-label">Mode</span>
            <div class="seg" role="radiogroup" aria-label="Mode">
              <label><input type="radio" name="mode" value="test" ${s.mode !== 'live' ? 'checked' : ''}><span>Sandbox</span></label>
              <label><input type="radio" name="mode" value="live" ${s.mode === 'live' ? 'checked' : ''}><span>Live</span></label></div></div>
          ${key('testKey', 'Sandbox API key', s.testKey)}
          <button type="button" class="btn btn-sm" data-test="test">${icon('check')} Test connection (sandbox)</button> <span class="test-res" data-res="test"></span>
          ${key('liveKey', 'Live API key', s.liveKey)}
          <button type="button" class="btn btn-sm" data-test="live">${icon('check')} Test connection (live)</button> <span class="test-res" data-res="live"></span>
          <div id="services"></div>
          <div class="form-grid" style="margin-top:14px">
            ${field('serviceCode', 'Service code', s.serviceCode, { dir: 'ltr', hint: 'From SMSA (e.g. EDDL). Empty = your account’s default.' })}
            ${field('retailId', 'SMSA retail ID (optional)', s.retailId, { dir: 'ltr' })}
            ${field('contentDescription', 'Contents (on the waybill)', s.contentDescription)}
            ${field('declaredCurrency', 'Declared value currency', s.declaredCurrency, { options: [['BHD', 'BHD'], ['SAR', 'SAR'], ['AED', 'AED'], ['KWD', 'KWD'], ['QAR', 'QAR'], ['OMR', 'OMR']] })}
          </div>
          <details style="margin-top:8px"><summary class="muted small">Server addresses (only change if SMSA tells you to)</summary><div class="form-grid" style="margin-top:8px">
            ${field('testUrl', 'Sandbox address', s.testUrl, { dir: 'ltr', full: true })}${field('liveUrl', 'Live address', s.liveUrl, { dir: 'ltr', full: true })}</div></details>
        </div>
        <div class="card"><h2>Sender (on every waybill)</h2>
          ${s.problems.length ? `<div class="box warn small">${s.problems.map(esc).join('<br>')}</div>` : ''}
          <div class="form-grid">
            ${field('shipper.name', 'Name (at least 5 letters)', sh.name, { full: true })}
            ${field('shipper.phone', 'Phone', sh.phone, { dir: 'ltr', inputmode: 'tel' })}
            ${field('shipper.country', 'Country code', sh.country, { dir: 'ltr' })}
            ${field('shipper.city', 'City', sh.city)}
            ${field('shipper.district', 'Area / district', sh.district)}
            ${field('shipper.address1', 'Address (at least 10 characters)', sh.address1, { full: true })}
            ${field('shipper.address2', 'Address line 2', sh.address2, { full: true })}
            ${field('shipper.postalCode', 'Postal code', sh.postalCode, { dir: 'ltr' })}
          </div></div>
      </div>
      <div class="card"><h2>Delivery prices (BHD)</h2>
        <p class="muted small">What the customer pays at checkout. Fixed price per country first; other countries pay by parcel weight.</p>
        ${field('rates.countries', 'We deliver to (country codes)', rates.countries.join(', '), { dir: 'ltr', hint: 'e.g. BH, SA, AE, QA, KW, OM' })}
        <h3>Fixed price</h3><div id="flat"></div><button type="button" class="btn btn-sm" id="fadd">${icon('plus')} Add a country</button>
        <h3 style="margin-top:16px">By weight</h3><div id="table" class="rate-table"></div><button type="button" class="btn btn-sm" id="tadd">${icon('plus')} Add a row</button>
        ${field('rates.extraPerHalfKg', 'Above the last row: extra for every 0.5 kg', rates.extraPerHalfKg, { type: 'number', step: '0.001', min: 0, dir: 'ltr' })}
      </div>
      <div class="savebar"><button type="submit" class="btn btn-primary" id="save">Save</button></div>
      </form>`;

    const drawFlat = () => {
      main.querySelector('#flat').innerHTML = flat.map((f, i) => `<div class="rate-row" data-field="rates.flat.${i}">
        <input class="input" value="${esc(f.country)}" data-f="${i}" data-k="country" dir="ltr" maxlength="2" aria-label="Country" style="width:70px">
        <input class="input" type="number" step="0.001" min="0" value="${f.price}" data-f="${i}" data-k="price" dir="ltr" aria-label="Price" style="width:110px"> <span class="muted small">BHD</span>
        <button type="button" class="iconbtn" data-frm="${i}" aria-label="Remove">${icon('close')}</button></div>`).join('');
    };
    const drawTable = () => {
      main.querySelector('#table').innerHTML = table.map((row, i) => `<div class="rate-row" data-field="rates.table.${i}"><span class="muted small">up to</span>
        <input class="input" type="number" step="0.1" min="0" value="${row[0]}" data-t="${i}" data-c="0" dir="ltr" aria-label="kg" style="width:80px"> <span class="muted small">kg</span>
        <input class="input" type="number" step="0.001" min="0" value="${row[1]}" data-t="${i}" data-c="1" dir="ltr" aria-label="Price" style="width:100px"> <span class="muted small">BHD</span>
        <button type="button" class="iconbtn" data-trm="${i}" aria-label="Remove">${icon('close')}</button></div>`).join('');
    };
    drawFlat(); drawTable();
    main.querySelector('#flat').addEventListener('input', (e) => { const i = e.target.dataset.f; if (i != null) flat[+i][e.target.dataset.k] = e.target.dataset.k === 'price' ? +e.target.value : e.target.value.toUpperCase(); });
    main.querySelector('#flat').addEventListener('click', (e) => { const b = e.target.closest('[data-frm]'); if (b) { flat.splice(+b.dataset.frm, 1); drawFlat(); } });
    main.querySelector('#table').addEventListener('input', (e) => { const i = e.target.dataset.t; if (i != null) table[+i][+e.target.dataset.c] = +e.target.value; });
    main.querySelector('#table').addEventListener('click', (e) => { const b = e.target.closest('[data-trm]'); if (b) { table.splice(+b.dataset.trm, 1); drawTable(); } });
    main.querySelector('#fadd').addEventListener('click', () => { flat.push({ country: '', price: 0 }); drawFlat(); });
    main.querySelector('#tadd').addEventListener('click', () => { const last = table[table.length - 1] || [0, 0]; table.push([+(last[0] + 0.5).toFixed(1), last[1]]); drawTable(); });

    const form = main.querySelector('#sf');
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearErrors(form);
      const v = (n) => form.querySelector(`[name="${n}"]`);
      const body = {
        enabled: v('enabled').checked, mode: form.querySelector('[name=mode]:checked').value,
        testKey: v('testKey').value.trim(), liveKey: v('liveKey').value.trim(),
        serviceCode: v('serviceCode').value, retailId: v('retailId').value, contentDescription: v('contentDescription').value,
        declaredCurrency: v('declaredCurrency').value, testUrl: v('testUrl').value, liveUrl: v('liveUrl').value,
        shipper: Object.fromEntries(['name', 'phone', 'country', 'city', 'district', 'address1', 'address2', 'postalCode'].map((k) => [k, v('shipper.' + k).value])),
        rates: { countries: v('rates.countries').value.split(/[\s,]+/).filter(Boolean), flat, table, extraPerHalfKg: +v('rates.extraPerHalfKg').value },
      };
      ['clearTestKey', 'clearLiveKey'].forEach((k) => { if (v(k)) body[k] = v(k).checked; });
      if (body.mode === 'live' && body.enabled && !(await confirmBox('Switch SMSA to LIVE? Every shipment you book will be a real, billable parcel.', { ok: 'Go live' }))) return;
      try { await busy(main.querySelector('#save'), () => api('shipping', { method: 'PUT', body })); toast('Saved'); this.view(main, route, alive); } catch (err) { showErrors(form, err); }
    });
    main.querySelectorAll('[data-test]').forEach((b) => b.addEventListener('click', async () => {
      const out = main.querySelector(`[data-res="${b.dataset.test}"]`);
      out.textContent = '…';
      try {
        const res = await busy(b, () => api('shipping/test', { method: 'POST', body: { mode: b.dataset.test } }));
        out.className = 'test-res ' + (res.connected ? 'ok' : 'err'); out.textContent = res.message; out.dataset.mark = res.connected ? '✓' : '✗';
        if (res.services && res.services.length) {
          main.querySelector('#services').innerHTML = `<p class="muted small">Your SMSA services:</p><ul class="services">${res.services.map((x) => `<li>${ltr(x.code)} – <span data-noi18n>${esc(x.name)}</span></li>`).join('')}</ul>`;
        }
      } catch (err) { out.className = 'test-res err'; out.textContent = err.message; toastErr(err); }
    }));
  },
};
