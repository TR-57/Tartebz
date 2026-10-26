import { api, esc, d, ltr, pill, busy, toast, toastErr, modal, confirmBox, fmtDate, money, nf, debounce, setQuery, go, can, icon, isAr } from '../core.js';

const TABS = [['all', 'All'], ['todo', 'To prepare'], ['unpaid', 'Not paid'], ['shipped', 'Shipped'], ['done', 'Delivered'], ['cancelled', 'Cancelled']];
const PILL = { pending_payment: 'amber', awaiting_whatsapp: 'amber', payment_failed: 'red', paid: 'blue', processing: 'purple', shipped: 'blue', delivered: 'green', cancelled: 'hidden', refunded: 'hidden' };

/* "BHD 7.500" plus the customer's own currency when they paid in another one. */
function amount(o, bhd) {
  let s = `<span data-noi18n>${esc(money(bhd))}</span>`;
  if (o.currency && o.currency !== 'BHD' && o.fxRate) {
    s += ` <span class="muted small" data-noi18n>(${esc(o.currency)} ${nf(bhd * o.fxRate, 2)})</span>`;
  }
  return s;
}

export default {
  key: 'orders', label: 'Orders', icon: 'bag', role: 'staff', group: 'store',
  async view(main, route, alive) {
    if (route.sub) return detail(main, route, alive);
    const q = route.query;
    const tab = TABS.some((t) => t[0] === q.tab) ? q.tab : 'all';
    const page = Math.max(1, +q.page || 1);
    const r = await api('orders', { query: { tab, q: q.q || '', page } });
    if (!alive()) return;
    const pages = Math.max(1, Math.ceil(r.total / r.per));
    main.innerHTML = `
      <div class="phead"><div><h1>Orders</h1><p class="sub">Amounts are in BHD; what the customer paid in their own currency is shown next to it.</p></div></div>
      <div class="tabs" role="tablist">${TABS.map(([k, l]) => `<a href="#/orders?tab=${k}" role="tab" aria-selected="${tab === k}">${esc(l)} <span class="count-pill${k === 'todo' && r.counts[k] ? ' red' : ''}" data-noi18n>${nf(r.counts[k])}</span></a>`).join('')}</div>
      <div class="toolbar"><input class="input grow" type="search" id="oq" value="${esc(q.q || '')}" placeholder="Search by order number, name, phone, email or waybill" aria-label="Search orders"></div>
      <div class="table-wrap"><table class="t cards"><thead><tr><th>Order</th><th>Customer</th><th>Status</th><th class="num">Total</th><th>Date</th></tr></thead><tbody>
      ${r.orders.length ? r.orders.map((o) => `<tr class="rowlink" data-href="#/orders/${o.id}">
        <td data-label="Order"><a href="#/orders/${o.id}"><b data-noi18n>#${esc(o.number)}</b></a>${o.testMode ? ' ' + pill('Test', 'amber') : ''}
          <div class="muted small"><span>${o.method === 'whatsapp' ? 'WhatsApp' : 'Card (Tap)'}</span> · <span>${o.items === 1 ? '1 item' : `${nf(o.items)} items`}</span></div></td>
        <td data-label="Customer">${d(o.name)}<div class="muted small">${ltr(o.phone)} · <span data-noi18n>${esc(o.country)}</span></div></td>
        <td data-label="Status">${pill(r.labels[o.status] || o.status, PILL[o.status] || '')}${o.awb ? `<div class="muted small">SMSA ${ltr(o.awb)}</div>` : ''}</td>
        <td data-label="Total" class="num">${amount({ currency: o.currency, fxRate: o.customerTotal ? o.customerTotal / o.total : 0 }, o.total)}</td>
        <td data-label="Date"><span data-noi18n>${esc(fmtDate(o.createdAt))}</span></td></tr>`).join('')
        : `<tr><td colspan="5"><p class="empty">${q.q ? 'No order matches your search.' : 'No orders here yet.'}</p></td></tr>`}
      </tbody></table></div>
      ${pages > 1 ? `<div class="pager"><span class="muted" data-noi18n>${nf(r.total)}</span>
        <span>${page > 1 ? `<button class="btn btn-sm" data-page="${page - 1}">Previous</button>` : ''} <span data-noi18n>${page} / ${pages}</span> ${page < pages ? `<button class="btn btn-sm" data-page="${page + 1}">Next</button>` : ''}</span></div>` : ''}`;
    const reload = (obj = {}) => { const nq = { tab, q: q.q || '', page, ...obj }; setQuery(nq); this.view(main, { ...route, query: nq }, alive); };
    main.querySelector('#oq').addEventListener('input', debounce((e) => reload({ q: e.target.value.trim(), page: 1 }), 400));
    main.querySelectorAll('[data-page]').forEach((b) => b.addEventListener('click', () => reload({ page: +b.dataset.page })));
    main.querySelectorAll('tr.rowlink').forEach((tr) => tr.addEventListener('click', (e) => { if (!e.target.closest('a, button')) go(tr.dataset.href); }));
  },
};

async function detail(main, route, alive) {
  const id = +route.sub;
  const r = await api('orders/' + id);
  if (!alive()) return;
  const o = r.order;
  const L = r.labels;
  const manager = can('manager');
  const paid = o.paymentStatus === 'paid';
  const closed = ['cancelled', 'refunded'].includes(o.status);
  const name = (i) => (isAr() && i.nameAr ? i.nameAr : i.name);

  const steps = paid && !closed ? [['processing', 'Preparing'], ['shipped', 'Shipped'], ['delivered', 'Delivered']].filter(([k]) => k !== o.status) : [];

  main.innerHTML = `
    <div class="crumbs"><a href="#/orders">Orders</a> › <span data-noi18n>#${esc(o.number)}</span></div>
    <div class="phead"><div><h1><span>Order</span> <span data-noi18n>#${esc(o.number)}</span></h1>
      <p class="sub">${pill(L[o.status] || o.status, PILL[o.status] || '')} ${o.testMode ? pill('Test payment – no real money', 'amber') : ''} <span class="muted" data-noi18n>${esc(fmtDate(o.createdAt))}</span></p></div>
      <div class="actions">${steps.map(([k, l]) => `<button class="btn" data-step="${k}">${icon('check')} ${esc(l)}</button>`).join('')}</div></div>
    <div class="ed-grid">
      <div class="ed-main">
        <div class="card"><h2>Products</h2>
          <div class="table-wrap"><table class="t"><tbody>${o.items.map((i) => `<tr>
            <td class="thumb">${i.image ? `<img src="${esc(i.image)}" alt="">` : `<span class="noimg">${icon('image')}</span>`}</td>
            <td>${i.productId ? `<a href="#/products/${i.productId}">${d(name(i))}</a>` : d(name(i))}<div class="muted small"><span data-noi18n>${esc(money(i.price))} × ${i.qty}</span></div></td>
            <td class="num">${amount(o, i.lineTotal)}</td></tr>`).join('')}
            <tr><td></td><td>Subtotal</td><td class="num">${amount(o, o.subtotal)}</td></tr>
            <tr><td></td><td>Delivery <span class="muted small" data-noi18n>(${nf(o.weightGrams / 1000, 2)} kg)</span></td><td class="num">${amount(o, o.shipping)}</td></tr>
            <tr><td></td><td><b>Total</b></td><td class="num"><b>${amount(o, o.total)}</b></td></tr>
          </tbody></table></div>
          <p class="muted small">Charged: <b data-noi18n>${esc(o.chargeCurrency)} ${nf(o.chargeAmount, ['BHD', 'KWD', 'OMR'].includes(o.chargeCurrency) ? 3 : 2)}</b></p>
        </div>
        <div class="card"><h2>Delivery – SMSA Express</h2>${shipBox(o, r.smsa)}</div>
        <div class="card"><h2>History</h2>
          <form id="nf" class="note-row"><input class="input grow" name="note" placeholder="Add a note for the team (not shown to the customer)" aria-label="Add a note"><button class="btn">Add note</button></form>
          <ul class="timeline">${o.events.map((e) => `<li class="ev-${esc(e.type)}"><span class="muted small"><span data-noi18n>${esc(fmtDate(e.at))}</span>${e.by ? ` · ${d(e.by)}` : ''}</span><div>${e.type === 'note' ? d(e.message) : esc(e.message)}</div></li>`).join('')}</ul>
        </div>
      </div>
      <div class="ed-side">
        <div class="card"><h2>Customer</h2>
          <p><b>${d(o.firstName + ' ' + o.lastName)}</b><br>${ltr(o.phone)} · <a href="${esc(o.whatsapp)}" target="_blank" rel="noopener">WhatsApp</a><br>${o.email ? `<a href="mailto:${esc(o.email)}">${ltr(o.email)}</a>` : ''}</p>
          <p class="muted small">Address</p>
          <p data-noi18n>${esc([o.address1, o.address2].filter(Boolean).join(', '))}<br>${esc([o.city, o.state, o.country].filter(Boolean).join(', '))}</p>
          ${o.notes ? `<p class="muted small">Customer’s note</p><p>${d(o.notes)}</p>` : ''}
          <p class="muted small">Language: <span data-noi18n>${o.lang === 'en' ? 'English' : 'العربية'}</span></p>
        </div>
        <div class="card"><h2>Payment</h2>
          <p>${paid ? pill('Paid', 'green') : pill(o.paymentStatus === 'failed' ? 'Not completed' : 'Not paid', paid ? 'green' : 'amber')} <span class="muted small">${o.method === 'whatsapp' ? 'WhatsApp order' : 'Card through Tap'}</span></p>
          ${o.paidAt ? `<p class="muted small">Paid <span data-noi18n>${esc(fmtDate(o.paidAt))}</span></p>` : ''}
          ${o.tapChargeId ? `<p class="muted small">Tap charge: ${ltr(o.tapChargeId)} · <span data-noi18n>${esc(o.tapStatus || '')}</span>${o.tapMode === 'test' ? ' · ' + pill('Test', 'amber') : ''}</p>
            <button class="btn btn-sm" id="chk">${icon('check')} Check payment with Tap</button>` : ''}
          ${!paid && !closed && manager ? `<button class="btn btn-sm" id="mpaid">Mark as paid…</button>` : ''}
          <p class="muted small" style="margin-top:10px">Customer’s order page: <a href="${esc(o.customerUrl)}" target="_blank" rel="noopener">open</a></p>
        </div>
        ${!closed && manager ? `<div class="card"><button class="btn btn-danger btn-sm" id="cancel">${icon('close')} Cancel order</button>
          <p class="muted small">Gives the stock back. A card payment is refunded in the Tap dashboard.</p></div>` : ''}
      </div>
    </div>`;

  const reload = () => detail(main, route, alive);
  const act = async (btn, path, body, ok) => {
    try { await busy(btn, () => api(`orders/${id}/${path}`, { method: 'POST', body })); toast(ok); reload(); } catch (e) { toastErr(e); }
  };
  main.querySelectorAll('[data-step]').forEach((b) => b.addEventListener('click', () => act(b, 'status', { status: b.dataset.step }, 'Saved')));
  main.querySelector('#nf').addEventListener('submit', (e) => { e.preventDefault(); const v = e.target.note.value.trim(); if (v) act(e.submitter, 'note', { note: v }, 'Note added'); });
  const chk = main.querySelector('#chk');
  if (chk) chk.addEventListener('click', () => act(chk, 'check-payment', {}, 'Checked with Tap'));
  const mp = main.querySelector('#mpaid');
  if (mp) mp.addEventListener('click', () => modal({
    title: 'Mark as paid',
    body: `<form id="mpf" class="form-grid" novalidate><div class="field full" data-field="how"><label for="how">How was it paid?</label>
      <input id="how" name="how" placeholder="e.g. BenefitPay transfer, cash"><span class="hint">Only when the money is really in. This takes the stock and emails the customer.</span></div></form>`,
    foot: '<button class="btn" data-close>Cancel</button><button class="btn btn-primary" id="mpok">Mark as paid</button>',
    onOpen: (el, close) => el.querySelector('#mpok').addEventListener('click', async (e) => {
      try { await busy(e.currentTarget, () => api(`orders/${id}/paid`, { method: 'POST', body: { how: el.querySelector('#how').value } })); close(); toast('Marked as paid'); reload(); } catch (err) { toastErr(err); }
    }),
  }));
  const cn = main.querySelector('#cancel');
  if (cn) cn.addEventListener('click', async () => {
    if (!(await confirmBox('Cancel this order? The stock goes back on sale.', { ok: 'Cancel order', danger: true }))) return;
    act(cn, 'cancel', {}, 'Order cancelled');
  });
  bindShip(main, o, r.smsa, act, reload);
}

function shipBox(o, smsa) {
  if (o.awb) {
    return `<p><b>SMSA ${ltr(o.awb)}</b> ${o.carrier.endsWith(':test') ? pill('Sandbox', 'amber') : ''}</p>
      ${o.trackingStatus ? `<p>${icon('truck')} <span data-noi18n>${esc(o.trackingStatus)}</span> <span class="muted small" data-noi18n>${esc(fmtDate(o.trackingAt))}</span></p>` : ''}
      <div class="btn-row">${o.hasLabel ? `<a class="btn btn-primary btn-sm" href="/api/admin/orders/${o.id}/label" target="_blank" rel="noopener">${icon('log')} Print label (PDF)</a>` : ''}
        <button class="btn btn-sm" id="track">${icon('truck')} Update tracking</button>
        <a class="btn btn-sm" href="${esc(o.trackingUrl)}" target="_blank" rel="noopener">SMSA tracking page</a>
        ${can('manager') && !['delivered'].includes(o.status) ? '<button class="btn btn-sm btn-ghost" id="cship">Cancel shipment</button>' : ''}</div>`;
  }
  if (o.paymentStatus !== 'paid' || ['cancelled', 'refunded'].includes(o.status)) return '<p class="muted">The shipment can be booked once the order is paid.</p>';
  if (!smsa.enabled) return '<p class="muted">SMSA is not switched on. Settings › Shipping: paste the API key, test, switch on.</p>';
  return `${smsa.problems.length ? `<div class="box warn small">${smsa.problems.map(esc).join('<br>')}</div>` : ''}
    <p class="muted small">${smsa.mode === 'live' ? 'Live: this books a real, billable SMSA parcel.' : 'Sandbox: a test waybill, no courier comes.'}</p>
    <div class="form-grid ship-form">
      <div class="field"><label for="parcels">Boxes</label><input id="parcels" type="number" min="1" max="25" value="1" dir="ltr"></div>
      <div class="field"><label for="kg">Weight (kg)</label><input id="kg" type="number" min="0.1" step="0.1" value="${(Math.max(100, o.weightGrams) / 1000).toFixed(1)}" dir="ltr"></div>
    </div>
    <button class="btn btn-primary" id="ship" ${smsa.problems.length ? 'disabled' : ''}>${icon('truck')} Book SMSA shipment and get the label</button>`;
}

function bindShip(main, o, smsa, act) {
  const ship = main.querySelector('#ship');
  if (ship) ship.addEventListener('click', async () => {
    if (smsa.mode === 'live' && !(await confirmBox('Book a real SMSA shipment for this order?', { ok: 'Book shipment' }))) return;
    act(ship, 'ship', { parcels: +main.querySelector('#parcels').value || 1, weightKg: +main.querySelector('#kg').value || null }, 'Shipment booked');
  });
  const tr = main.querySelector('#track');
  if (tr) tr.addEventListener('click', () => act(tr, 'track', {}, 'Tracking updated'));
  const cs = main.querySelector('#cship');
  if (cs) cs.addEventListener('click', async () => {
    if (!(await confirmBox('Cancel this SMSA shipment? The waybill can no longer be used.', { ok: 'Cancel shipment', danger: true }))) return;
    act(cs, 'cancel-shipment', {}, 'Shipment cancelled');
  });
}
