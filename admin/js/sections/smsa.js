import { api, esc, pill, toggle, field, busy, toast, toastErr, showErrors, clearErrors, icon, confirmBox, fmtDate, nf, isAr } from '../core.js';

/* Owner: SMSA shipping – the same integration as Sniper Safety. The API keys (write-only), sandbox or live, the sender
   address on the waybills, which delivery methods go through SMSA (by air EIDL or by road EIED when they go abroad),
   who pays import VAT and duty, and the on/off switch. Once on, paid orders with those methods get their waybill within
   a minute and their status follows SMSA's tracking. */

const DUTIES = {
  consignee: ['The customer pays on delivery', 'SMSA collects the import VAT and customs duty from the customer when the parcel arrives. SMSA’s portal shows Vat Billing and Duty Billing: Consignee.'],
  shipper: ['We pay (billed to our SMSA account)', 'SMSA bills the import VAT and customs duty to our SMSA account. The customer pays nothing on delivery. SMSA’s portal shows Vat Billing and Duty Billing: Shipper.'],
};

export default {
  key: 'smsa', label: 'SMSA shipping', icon: 'truck', role: 'owner', group: 'settings',
  async view(main, route, alive) {
    const d = await api('smsa');
    if (!alive()) return;
    paint(main, d, () => this.view(main, route, alive));
  },
};

function paint(main, d, reload) {
  const s = d.settings; const sh = s.shipper; const c = d.counts;
  const name = (n) => (isAr() ? n.ar || n.en : n.en);
  const key = (mode, label) => {
    const k = d.keys[mode]; const n = mode === 'live' ? 'liveKey' : 'testKey';
    return `<div class="field full" data-field="${n}"><label for="${n}">${esc(label)}</label>
      <input id="${n}" name="${n}" type="password" autocomplete="new-password" dir="ltr" placeholder="${esc(k.set ? 'Saved – ends in ' + k.last4 + '. Type a new key to replace it.' : 'Paste the API key from SMSA')}">
      <span class="btn-row">${k.set ? `<button type="button" class="btn btn-sm" data-test="${mode}">${icon('check')} Check connection</button>
        <label class="mini-check"><input type="checkbox" name="clear${n[0].toUpperCase() + n.slice(1)}"> <span>Remove the saved key</span></label>` : ''}
        <span class="test-res" data-res="${mode}"></span></span></div>`;
  };
  main.innerHTML = `
    <div class="phead"><div><h1>SMSA shipping</h1><p class="sub">Waybills, labels and tracking for SMSA Express, from your orders – no forms to fill.</p></div>
      <div class="actions"><a class="btn" href="#/shipping">${icon('truck')} Shipping rates</a></div></div>
    <div class="card smsa-state ${d.active ? 'on' : ''}">
      <div class="card-head"><h2>${d.active ? 'SMSA is on' : 'SMSA is off'}</h2>${d.active ? (s.mode === 'live' ? pill('Live – real shipments', 'green') : pill('Sandbox – test waybills', 'amber')) : pill('Off', 'hidden')}</div>
      <p>${d.active ? `Paid orders with the delivery methods ticked below get their SMSA waybill within a minute (orders placed since <span data-noi18n>${esc(fmtDate(s.since))}</span>).`
        : 'Nothing is sent to SMSA by itself. Fill in the key and the sender address, tick the delivery methods, then switch it on.'}</p>
      ${d.missing.length ? `<p class="box warn small"><span>Still needed:</span> ${d.missing.map(esc).join(', ')}</p>` : ''}
      <p class="small"><span>Import VAT and duty on shipments outside Bahrain:</span> <b>${esc(DUTIES[s.dutiesBy][0])}</b></p>
      <p class="muted small"><span data-noi18n>${nf(c.created)}</span> <span>waiting for pickup</span> · <span data-noi18n>${nf(c.moving)}</span> <span>on the way</span> ·
        <span data-noi18n>${nf(c.delivered)}</span> <span>delivered</span> · <span data-noi18n>${nf(c.failed)}</span> <span>refused</span></p>
    </div>
    ${d.failed.length ? `<div class="card"><h2>Refused by SMSA</h2><ul class="smsa-failed">${d.failed.map((f) => `<li><a href="#/orders/${f.id}"><b data-noi18n>#${esc(f.number)}</b></a> <span data-noi18n>${esc(f.name)}</span>
      <div class="muted small" data-noi18n>${esc(f.error)}</div></li>`).join('')}</ul></div>` : ''}
    <form id="smf" novalidate>
    <div class="grid-2">
      <div class="card"><h2>Connection</h2>
        ${toggle('enabled', 'Send paid orders to SMSA automatically', s.enabled)}
        <div class="field"><span class="field-label">Mode</span>
          <div class="seg" role="radiogroup" aria-label="Mode">
            <label><input type="radio" name="mode" value="test" ${s.mode !== 'live' ? 'checked' : ''}><span>Sandbox</span></label>
            <label><input type="radio" name="mode" value="live" ${s.mode === 'live' ? 'checked' : ''}><span>Live</span></label></div>
          <span class="hint">Sandbox makes test waybills (no courier comes). Live waybills are real, billable parcels.</span></div>
        ${key('test', 'Sandbox API key')}
        ${key('live', 'Live API key')}
        <p class="muted small">From SMSA (your SMSA account manager or the SMSA eCommerce portal). Stored encrypted and never shown again.</p>
        ${toggle('autoStatus', 'Update the order status from SMSA tracking (Shipped when SMSA has the parcel, Delivered when delivered – the customer is emailed)', s.autoStatus)}
        <div class="form-grid">
          ${field('contents', 'What the parcels contain (for customs)', s.contents, { full: true, hint: 'Shown on every waybill. Leave empty to list the items of each order.' })}
          ${field('retailId', 'SMSA retail ID (optional)', s.retailId, { dir: 'ltr' })}
        </div>
        <details><summary class="muted small">Server addresses (only change if SMSA tells you to)</summary><div class="form-grid" style="margin-top:8px">
          ${field('testUrl', 'Sandbox address', s.testUrl, { dir: 'ltr', full: true })}${field('liveUrl', 'Live address', s.liveUrl, { dir: 'ltr', full: true })}</div></details>
      </div>
      <div class="card"><h2>Sender (printed on the waybill)</h2>
        ${d.problems.length ? `<div class="box warn small">${d.problems.map(esc).join('<br>')}</div>` : ''}
        <div class="form-grid">
          ${field('shipper.name', 'Company or contact name (at least 5 letters)', sh.name, { full: true })}
          ${field('shipper.phone', 'Phone', sh.phone, { dir: 'ltr', inputmode: 'tel', placeholder: '+973 …' })}
          ${field('shipper.country', 'Country (2 letters)', sh.country, { dir: 'ltr' })}
          ${field('shipper.address1', 'Address line 1 (at least 10 characters)', sh.address1, { full: true, placeholder: 'Building, road, block' })}
          ${field('shipper.address2', 'Address line 2', sh.address2, { full: true })}
          ${field('shipper.city', 'City', sh.city, { placeholder: 'Manama' })}
          ${field('shipper.district', 'Area / district', sh.district)}
          ${field('shipper.postalCode', 'Postcode', sh.postalCode, { dir: 'ltr' })}
        </div></div>
    </div>
    <div class="card"><h2>Delivery methods sent with SMSA</h2>
      <p class="muted small">Tick the methods whose paid orders go to SMSA. For each method going abroad, choose how SMSA carries it: <b>by air (EIDL)</b> or <b>by road (EIED)</b> – the service printed on the label. Deliveries inside Bahrain are always EDDL. It applies to waybills created from now on.</p>
      <div class="smsa-methods" data-field="services">${d.methods.map((m) => `<div class="smsa-method">
        <label class="mini-check"><input type="checkbox" name="m_${esc(m.id)}" ${s.methods.includes(m.id) ? 'checked' : ''}> <span data-noi18n>${esc(name(m.name))}</span>
          <span class="muted small" data-noi18n>${esc(name(m.destination))} · ${esc(m.countries.join(', '))}</span>${m.active ? '' : ' ' + pill('Off', 'hidden')}</label>
        ${m.abroad ? `<label class="smsa-svc"><span>SMSA service</span><select name="svc_${esc(m.id)}">${[['EIDL', 'By air – EIDL'], ['EIED', 'By road – EIED']].map(([v, l]) => `<option value="${v}"${m.service === v ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select>
          ${m.serviceSaved ? '' : '<span class="hint">Suggested from the name – press Save to keep it</span>'}</label>` : '<span class="muted small">Inside Bahrain – EDDL</span>'}
      </div>`).join('') || '<p class="muted">No delivery methods yet – add them in Shipping rates.</p>'}</div></div>
    <div class="card"><h2>Import VAT and duty paid by</h2>
      <p class="muted small">For shipments outside Bahrain only. Deliveries inside Bahrain have no import VAT or customs duty. It applies to waybills created from now on – a waybill already created keeps what it was created with (each order’s SMSA panel and history say which).</p>
      <div class="smsa-duties">${Object.entries(DUTIES).map(([k, [t, x]]) => `<label class="smsa-duty${s.dutiesBy === k ? ' on' : ''}"><input type="radio" name="dutiesBy" value="${k}" ${s.dutiesBy === k ? 'checked' : ''}>
        <span><b>${esc(t)}</b><small>${esc(x)}</small></span></label>`).join('')}</div></div>
    <div class="savebar"><button type="submit" class="btn btn-primary" id="save">Save</button></div>
    </form>`;

  const f = main.querySelector('#smf');
  f.querySelectorAll('[name=dutiesBy]').forEach((r) => r.addEventListener('change', () => {
    f.querySelectorAll('.smsa-duty').forEach((l) => l.classList.toggle('on', l.querySelector('input').checked));
  }));
  f.addEventListener('submit', async (e) => {
    e.preventDefault();
    clearErrors(f);
    const v = (n) => f.querySelector(`[name="${n}"]`);
    const duties = f.querySelector('[name=dutiesBy]:checked').value;
    const mode = f.querySelector('[name=mode]:checked').value;
    const body = {
      enabled: v('enabled').checked, mode, autoStatus: v('autoStatus').checked, contents: v('contents').value, retailId: v('retailId').value,
      dutiesBy: duties, testUrl: v('testUrl').value, liveUrl: v('liveUrl').value,
      methods: d.methods.filter((m) => v('m_' + m.id).checked).map((m) => m.id),
      services: Object.fromEntries(d.methods.filter((m) => m.abroad && v('svc_' + m.id)).map((m) => [m.id, v('svc_' + m.id).value])),
      shipper: Object.fromEntries(['name', 'phone', 'country', 'city', 'district', 'address1', 'address2', 'postalCode'].map((k) => [k, v('shipper.' + k).value])),
      testKey: v('testKey').value.trim(), liveKey: v('liveKey').value.trim(),
    };
    ['clearTestKey', 'clearLiveKey'].forEach((k) => { if (v(k)) body[k] = v(k).checked; });
    if (duties !== s.dutiesBy && !(await confirmBox(`Import VAT and duty on shipments outside Bahrain will be paid by: ${DUTIES[duties][0]}. This applies to waybills created from now on.`, { ok: 'Continue' }))) return;
    if (mode === 'live' && body.enabled && (s.mode !== 'live' || !s.enabled) && !(await confirmBox('Switch SMSA to LIVE? Every waybill from now on is a real, billable parcel.', { ok: 'Go live' }))) return;
    try {
      const res = await busy(main.querySelector('#save'), () => api('smsa', { method: 'PUT', body }));
      toast('Saved');
      paint(main, res, reload);
    } catch (err) {
      if (err.code === 'smsa_setup') { toastErr(err); reload(); return; }
      showErrors(f, err);
    }
  });
  main.querySelectorAll('[data-test]').forEach((b) => b.addEventListener('click', async () => {
    const out = main.querySelector(`[data-res="${b.dataset.test}"]`);
    out.className = 'test-res'; out.textContent = '…';
    try {
      const res = await busy(b, () => api('smsa/test', { method: 'POST', body: { mode: b.dataset.test } }));
      out.className = 'test-res ' + (res.connected ? 'ok' : 'err'); out.textContent = res.message;
    } catch (err) { out.className = 'test-res err'; out.textContent = err.message; }
  }));
}

