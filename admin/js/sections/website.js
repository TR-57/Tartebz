import { api, esc, toggle, busy, toast, showErrors, clearErrors, icon, can } from '../core.js';

const LOGO_NAMES = { benefit: 'BenefitPay', visa: 'Visa', mastercard: 'Mastercard', applepay: 'Apple Pay', mada: 'mada', knet: 'KNET', tabby: 'tabby', cod: 'Cash on delivery' };

/* Website extras: WhatsApp ordering, back-in-stock alerts, payment marks, stock reservation. Delivery times: Shipping rates. */
export default {
  key: 'website', label: 'Website', icon: 'store', role: 'manager', group: 'settings',
  async view(main, route, alive) {
    const r = await api('website');
    if (!alive()) return;
    const s = r.storefront;
    const edit = can('manager');

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
      <div class="card"><h2>${icon('bag')} Stock reservation</h2>
        <p class="muted small">Pieces in a customer’s cart are kept for them, so two people never pay for the last one. Other customers see only what nobody is keeping.</p>
        <div class="grid-3">
          <div class="field" data-field="cartHoldMinutes"><label for="hc">In a cart (minutes)</label>
            <input id="hc" name="cartHoldMinutes" type="number" min="1" max="120" value="${r.holds.cartHoldMinutes}" dir="ltr">
            <span class="hint">Starts again each time the customer uses the cart.</span></div>
          <div class="field" data-field="orderHoldMinutes"><label for="ho">Card order waiting for payment (minutes)</label>
            <input id="ho" name="orderHoldMinutes" type="number" min="10" max="1440" value="${r.holds.orderHoldMinutes}" dir="ltr">
            <span class="hint">After that, the pieces go back on sale if the order is still unpaid.</span></div>
          <div class="field" data-field="whatsappHoldHours"><label for="hw">WhatsApp order (hours)</label>
            <input id="hw" name="whatsappHoldHours" type="number" min="1" max="168" value="${r.holds.whatsappHoldHours}" dir="ltr">
            <span class="hint">Until you mark it paid or cancel it.</span></div>
        </div>
      </div>
      <p class="muted small">Delivery prices and times (working days, the daily cut-off) are set per delivery method in <a href="#/shipping">Shipping rates</a>.</p>
      ${edit ? '<div class="savebar"><button type="submit" class="btn btn-primary" id="save">Save</button></div>' : ''}
      </form>`;

    const form = main.querySelector('#wf');
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearErrors(form);
      const body = {
        whatsappOrders: form.querySelector('[name=whatsappOrders]').checked,
        backInStock: form.querySelector('[name=backInStock]').checked,
        whatsappNumber: form.querySelector('[name=whatsappNumber]').value,
        paymentLogos: [...form.querySelectorAll('[name=logo]:checked')].map((c) => c.value),
        cartHoldMinutes: +form.querySelector('[name=cartHoldMinutes]').value,
        orderHoldMinutes: +form.querySelector('[name=orderHoldMinutes]').value,
        whatsappHoldHours: +form.querySelector('[name=whatsappHoldHours]').value,
      };
      try {
        await busy(main.querySelector('#save'), () => api('website', { method: 'PUT', body }));
        toast('Saved');
      } catch (err) { showErrors(form, err); }
    });
  },
};
