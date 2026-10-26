import { api, esc, toggle, busy, toast, showErrors, clearErrors, icon, can } from '../core.js';

const LOGO_NAMES = { benefit: 'BenefitPay', visa: 'Visa', mastercard: 'Mastercard', applepay: 'Apple Pay', mada: 'mada', knet: 'KNET', tabby: 'tabby', cod: 'Cash on delivery' };
const COUNTRY = { BH: '🇧🇭 Bahrain', SA: '🇸🇦 Saudi Arabia', AE: '🇦🇪 UAE', QA: '🇶🇦 Qatar', KW: '🇰🇼 Kuwait', OM: '🇴🇲 Oman' };

/* Website extras: WhatsApp ordering, back-in-stock alerts, payment marks and delivery times. */
export default {
  key: 'website', label: 'Website', icon: 'store', role: 'manager', group: 'settings',
  async view(main, route, alive) {
    const r = await api('website');
    if (!alive()) return;
    const s = r.storefront;
    let delivery = s.delivery.map((d) => ({ ...d }));
    const edit = can('manager');

    const rows = () => delivery.map((d, i) => `<tr data-i="${i}">
      <td data-label="Country" data-field="delivery.${i}.country">${COUNTRY[d.country] ? `<b>${esc(COUNTRY[d.country])}</b>` : `<input class="input" data-k="country" value="${esc(d.country)}" maxlength="2" dir="ltr" style="width:70px" aria-label="Country">`}</td>
      <td data-label="From (days)"><input class="input" type="number" min="0" max="60" data-k="min" value="${d.min}" dir="ltr" style="width:80px" aria-label="From (days)"></td>
      <td data-label="To (days)" data-field="delivery.${i}.max"><input class="input" type="number" min="0" max="60" data-k="max" value="${d.max}" dir="ltr" style="width:80px" aria-label="To (days)"></td>
      <td data-label="Shown as" class="muted small">${esc(d.min === 1 && d.max === 1 ? 'Arrives tomorrow' : d.max === 0 ? 'Arrives today' : `Arrives in ${d.min}–${d.max} days (as dates)`)}</td>
      <td>${COUNTRY[d.country] ? '' : `<button type="button" class="iconbtn" data-rm="${i}" aria-label="Remove">${icon('close')}</button>`}</td></tr>`).join('');

    main.innerHTML = `
      <div class="phead"><div><h1>Website</h1><p class="sub">Extras on the shop pages. Changes show on the website within a minute.</p></div></div>
      <form id="wf" novalidate>
      <div class="grid-2">
        <div class="card"><h2>${icon('phone')} Order on WhatsApp</h2>
          ${toggle('whatsappOrders', 'Show “Order on WhatsApp” in the cart', s.whatsappOrders, 'Sends the cart, the delivery country and the total to your WhatsApp as a ready message.')}
          <div class="field" data-field="whatsappNumber"><label for="wn">WhatsApp number for orders</label>
            <input id="wn" name="whatsappNumber" value="${esc(s.whatsappNumber)}" dir="ltr" placeholder="${esc('+' + r.businessWhatsapp.replace(/\D/g, ''))}" inputmode="tel">
            <span class="hint">With the country code. Leave empty to use the shop’s WhatsApp from Settings.</span></div>
        </div>
        <div class="card"><h2>${icon('bell')} Back in stock</h2>
          ${toggle('backInStock', 'Let customers ask to be told when a product is back', s.backInStock, 'On sold-out products. Emails go out by themselves when you put stock back; WhatsApp numbers are listed on the product.')}
        </div>
      </div>
      <div class="card"><h2>Payment marks</h2><p class="muted small">Shown near the price, in the cart and in the footer. Tick only the ways you really accept.</p>
        <div class="logo-picks">${r.logos.map((k) => `<label class="chip-check"><input type="checkbox" name="logo" value="${k}" ${s.paymentLogos.includes(k) ? 'checked' : ''}><span>${esc(LOGO_NAMES[k] || k)}</span></label>`).join('')}</div>
      </div>
      <div class="card"><h2>Delivery times</h2><p class="muted small">Days after the order. The website shows them as dates, e.g. “Arrives Sun 3 Nov – Wed 6 Nov”.</p>
        <div class="table-wrap"><table class="t cards"><thead><tr><th>Country</th><th>From (days)</th><th>To (days)</th><th>Shown as</th><th></th></tr></thead><tbody id="drows">${rows()}</tbody></table></div>
        <p><button type="button" class="btn btn-sm" id="dadd">${icon('plus')} Add a country</button></p>
      </div>
      ${edit ? '<div class="savebar"><button type="submit" class="btn btn-primary" id="save">Save</button></div>' : ''}
      </form>`;

    const form = main.querySelector('#wf');
    const tbody = main.querySelector('#drows');
    tbody.addEventListener('input', (e) => {
      const tr = e.target.closest('[data-i]');
      if (!tr) return;
      const k = e.target.dataset.k;
      delivery[+tr.dataset.i][k] = k === 'country' ? e.target.value.toUpperCase() : +e.target.value;
    });
    tbody.addEventListener('change', () => { tbody.innerHTML = rows(); });
    tbody.addEventListener('click', (e) => { const b = e.target.closest('[data-rm]'); if (b) { delivery.splice(+b.dataset.rm, 1); tbody.innerHTML = rows(); } });
    main.querySelector('#dadd').addEventListener('click', () => { delivery.push({ country: '', min: 2, max: 4 }); tbody.innerHTML = rows(); });
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearErrors(form);
      const body = {
        whatsappOrders: form.querySelector('[name=whatsappOrders]').checked,
        backInStock: form.querySelector('[name=backInStock]').checked,
        whatsappNumber: form.querySelector('[name=whatsappNumber]').value,
        paymentLogos: [...form.querySelectorAll('[name=logo]:checked')].map((c) => c.value),
        delivery,
      };
      try {
        await busy(main.querySelector('#save'), () => api('website', { method: 'PUT', body }));
        toast('Saved');
      } catch (err) { showErrors(form, err); }
    });
  },
};
