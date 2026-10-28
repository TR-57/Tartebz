import { api, upload, esc, ltr, pill, toggle, busy, toast, toastErr, showErrors, clearErrors, icon, confirmBox } from '../core.js';

/* Owner: Tap card payments. Paste the keys, test the connection, place a test order, then switch to live. */
export default {
  key: 'payments', label: 'Payments', icon: 'card', role: 'owner', group: 'settings',
  async view(main, route, alive) {
    const r = await api('payments');
    if (!alive()) return;
    const t = r.tap;
    const keyField = (name, label, saved, ph) => `<div class="field" data-field="${name}"><label for="${name}">${esc(label)}</label>
      <input id="${name}" name="${name}" type="password" autocomplete="off" dir="ltr" placeholder="${esc(saved.set ? '•••• ' + saved.last4 + ' (saved – type to replace)' : ph)}">
      ${saved.set ? `<label class="mini-check"><input type="checkbox" name="clear${name[0].toUpperCase() + name.slice(1)}"> <span>Remove the saved key</span></label>` : ''}</div>`;
    const status = t.active ? (t.mode === 'live' ? pill('Live – real payments', 'green') : pill('Test mode – no real money', 'amber')) : pill('Off', 'hidden');

    main.innerHTML = `
      <div class="phead"><div><h1>Payments</h1><p class="sub">Card payments through Tap. Customers pay on Tap’s secure page (cards, Apple Pay, Benefit, mada, KNET – whatever your Tap account has switched on). Card numbers never reach this website.</p></div></div>
      <div class="steps card"><h2>Going live in 4 steps</h2><ol>
        <li>In your Tap dashboard (goSell › API keys), copy the test secret key and test publishable key. Paste them below, keep Test mode, switch card payments on and save.</li>
        <li>Press “Test connection”. Then place an order on the website and pay with a Tap test card. Check the order shows as paid in Orders.</li>
        <li>Paste the live secret key and live publishable key, save, and test the live connection.</li>
        <li>Choose Live and save. From then on, payments are real.</li></ol></div>
      <form id="pf" novalidate>
      <div class="grid-2">
        <div class="card"><div class="card-head"><h2>${icon('card')} Tap</h2>${status}</div>
          ${toggle('enabled', 'Card payments on at checkout', t.enabled)}
          <div class="field"><span class="field-label">Mode</span>
            <div class="seg" role="radiogroup" aria-label="Mode">
              <label><input type="radio" name="mode" value="test" ${t.mode !== 'live' ? 'checked' : ''}><span>Test</span></label>
              <label><input type="radio" name="mode" value="live" ${t.mode === 'live' ? 'checked' : ''}><span>Live</span></label></div>
            <span class="hint">Test mode uses the test keys: Tap test cards only, no real money.</span></div>
          <div class="field"><label for="merchantId">Merchant ID (optional)</label><input id="merchantId" name="merchantId" value="${esc(t.merchantId)}" dir="ltr"><span class="hint">Only if Tap gave you one for this shop.</span></div>
          <div class="field"><span class="field-label">Charge in these currencies</span>
            <div class="logo-picks">${r.currencies.map((c) => `<label class="chip-check"><input type="checkbox" name="cur" value="${c}" ${t.chargeCurrencies.includes(c) || c === 'BHD' ? 'checked' : ''} ${c === 'BHD' ? 'disabled' : ''}><span data-noi18n>${esc(c)}</span></label>`).join('')}</div>
            <span class="hint">Tick only currencies your Tap account can accept. Customers from other countries are charged in BHD (the same price, converted).</span></div>
        </div>
        <div class="card"><h2>Keys</h2>
          <h3>Test</h3>
          ${keyField('testSecret', 'Secret key (sk_test_…)', t.testSecret, 'sk_test_…')}
          ${keyField('testPublishable', 'Publishable key (pk_test_…)', t.testPublishable, 'pk_test_…')}
          <button type="button" class="btn btn-sm" data-test="test">${icon('check')} Test connection (test keys)</button> <span class="test-res" data-res="test"></span>
          <h3 style="margin-top:18px">Live</h3>
          ${keyField('liveSecret', 'Secret key (sk_live_…)', t.liveSecret, 'sk_live_…')}
          ${keyField('livePublishable', 'Publishable key (pk_live_…)', t.livePublishable, 'pk_live_…')}
          <button type="button" class="btn btn-sm" data-test="live">${icon('check')} Test connection (live keys)</button> <span class="test-res" data-res="live"></span>
          <p class="muted small">Keys are stored encrypted and never shown again. Save before testing a new key.</p>
        </div>
      </div>
      <div class="card"><h2>WhatsApp orders</h2>
        ${toggle('whatsappEnabled', 'Offer “Order on WhatsApp” at checkout', r.whatsappEnabled, 'The order is saved; you confirm the payment by hand in Orders (Mark as paid).')}
      </div>
      <div class="card"><div class="card-head"><h2>Apple Pay</h2>${r.applePay.fileSet ? pill('Domain file uploaded', 'green') : ''}</div>
        <p class="muted small">Apple Pay shows on Tap’s payment page (iPhone, iPad, Mac with Safari) once Tap switches it on for your account – ask Tap support. If Tap asks you to verify your domain, upload the file they give you (apple-developer-merchantid-domain-association) here.</p>
        <p><label class="btn btn-sm">${icon('upload')} <span>${r.applePay.fileSet ? 'Replace the file' : 'Upload the file'}</span><input type="file" id="apfile" hidden></label>
          ${r.applePay.fileSet ? `<a class="btn btn-sm" href="${esc(r.applePay.url)}" target="_blank" rel="noopener">Open</a> <button type="button" class="btn btn-sm btn-ghost" id="apclear">Remove</button>` : ''}</p>
        <p class="muted small"><span>Address Apple checks:</span> ${ltr(r.applePay.url)}</p>
        <p class="muted small">To show the Apple Pay mark on the website: Settings › Website › Payment marks.</p>
      </div>
      <div class="card"><h2>For your Tap dashboard</h2>
        <p class="muted small">Tap sends payment updates here (the website also sets it on every payment, so usually nothing to do):</p>
        <p>${ltr(r.webhookUrl)}</p>
        <p class="muted small">Test cards: see Tap’s developer docs (“Testing cards”). In test mode, Benefit/KNET/mada pages also have a test flow.</p>
      </div>
      <div class="savebar"><button type="submit" class="btn btn-primary" id="save">Save</button></div>
      </form>`;

    const form = main.querySelector('#pf');
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearErrors(form);
      const v = (n) => form.querySelector(`[name="${n}"]`);
      const body = {
        enabled: v('enabled').checked, mode: form.querySelector('[name=mode]:checked').value, merchantId: v('merchantId').value,
        chargeCurrencies: [...form.querySelectorAll('[name=cur]:checked')].map((c) => c.value), whatsappEnabled: v('whatsappEnabled').checked,
      };
      ['testSecret', 'testPublishable', 'liveSecret', 'livePublishable'].forEach((k) => {
        body[k] = v(k).value.trim();
        const cl = v('clear' + k[0].toUpperCase() + k.slice(1));
        if (cl) body['clear' + k[0].toUpperCase() + k.slice(1)] = cl.checked;
      });
      if (body.mode === 'live' && body.enabled && !(await confirmBox('Switch to LIVE? Customers will be charged real money.', { ok: 'Go live' }))) return;
      try { await busy(main.querySelector('#save'), () => api('payments', { method: 'PUT', body })); toast('Saved'); this.view(main, route, alive); } catch (err) { showErrors(form, err); }
    });
    main.querySelector('#apfile').addEventListener('change', async (e) => {
      const f = e.target.files[0];
      if (!f) return;
      try { await upload('payments/apple-pay-file', f); toast('Apple Pay file saved'); this.view(main, route, alive); } catch (err) { toastErr(err); }
    });
    const apc = main.querySelector('#apclear');
    if (apc) apc.addEventListener('click', async () => {
      if (!(await confirmBox('Remove the Apple Pay domain file?', { ok: 'Remove' }))) return;
      try { await api('payments/apple-pay-file', { method: 'POST', body: { clear: true } }); toast('Removed'); this.view(main, route, alive); } catch (err) { toastErr(err); }
    });
    main.querySelectorAll('[data-test]').forEach((b) => b.addEventListener('click', async () => {
      const out = main.querySelector(`[data-res="${b.dataset.test}"]`);
      out.textContent = '…';
      try {
        const res = await busy(b, () => api('payments/test', { method: 'POST', body: { mode: b.dataset.test } }));
        out.className = 'test-res ' + (res.connected ? 'ok' : 'err'); out.textContent = res.message; out.dataset.mark = res.connected ? '✓' : '✗';
      } catch (err) { out.className = 'test-res err'; out.textContent = err.message; toastErr(err); }
    }));
  },
};
