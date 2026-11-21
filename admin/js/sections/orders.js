import { api, esc, d, ltr, pill, busy, toast, toastErr, modal, confirmBox, showErrors, clearErrors, fmtDate, money, nf, debounce, setQuery, go, can, icon, isAr } from '../core.js';

const TABS = [['all', 'All'], ['todo', 'To prepare'], ['unpaid', 'Not paid'], ['shipped', 'Shipped'], ['done', 'Delivered'], ['cancelled', 'Cancelled']];
const PILL = { pending_payment: 'amber', awaiting_whatsapp: 'amber', payment_failed: 'red', paid: 'blue', processing: 'purple', ready: 'purple', shipped: 'blue', delivered: 'green', cancelled: 'hidden', refunded: 'hidden' };

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
      <div class="phead"><div><h1>Orders</h1><p class="sub">Amounts are in BHD; what the customer paid in their own currency is shown next to it.</p></div>
        <div class="actions"><button class="btn btn-primary" id="pkbtn" type="button">${icon('truck')} Book SMSA pickup</button><a class="btn" href="/staff" target="_blank" rel="noopener">${icon('bag')} Orders board</a><a class="btn btn-primary" href="/staff#new" target="_blank" rel="noopener">${icon('plus')} New order</a></div></div>
      <div id="pkopen"></div>
      <div class="tabs" role="tablist">${TABS.map(([k, l]) => `<a href="#/orders?tab=${k}" role="tab" aria-selected="${tab === k}">${esc(l)} <span class="count-pill${k === 'todo' && r.counts[k] ? ' red' : ''}" data-noi18n>${nf(r.counts[k])}</span></a>`).join('')}</div>
      <div class="toolbar"><input class="input grow" type="search" id="oq" value="${esc(q.q || '')}" placeholder="Search by order number, name, phone, email or waybill" aria-label="Search orders"></div>
      <div class="bulkbar" id="obulk" hidden><span><b id="ocount" data-noi18n>0</b> <span>selected</span></span>
        <button class="btn btn-sm btn-primary" data-bulk="print">${icon('log')} Print invoices (+ SMSA waybills abroad)</button>
        <button class="btn btn-sm" data-bulk="labels">SMSA labels (one PDF)</button>
        <button class="btn btn-sm" data-bulk="ship">${icon('truck')} Send to SMSA</button>
        <button class="btn btn-sm btn-ghost" data-bulk="none">Clear</button></div>
      <div class="table-wrap"><table class="t cards"><thead><tr><th class="pick"><input type="checkbox" id="oall" aria-label="Select all"></th><th>Order</th><th>Customer</th><th>Status</th><th class="num">Total</th><th>Date</th></tr></thead><tbody>
      ${r.orders.length ? r.orders.map((o) => `<tr class="rowlink" data-href="#/orders/${o.id}">
        <td class="pick"><input type="checkbox" data-pick="${o.id}" aria-label="Select"></td>
        <td data-label="Order"><a href="#/orders/${o.id}"><b data-noi18n>#${esc(o.number)}</b></a>${o.testMode ? ' ' + pill('Test', 'amber') : ''}
          <div class="muted small"><span>${o.method === 'whatsapp' ? 'WhatsApp' : 'Card (Tap)'}</span>${o.source === 'staff' ? ' · <span>Created by the team</span>' : ''} · <span>${o.items === 1 ? '1 item' : `${nf(o.items)} items`}</span></div></td>
        <td data-label="Customer">${d(o.name)}<div class="muted small">${ltr(o.phone)} · <span data-noi18n>${esc(o.country)}</span></div></td>
        <td data-label="Status">${pill(r.labels[o.status] || o.status, PILL[o.status] || '')}${o.awb ? `<div class="muted small">SMSA ${ltr(o.awb)}</div>` : ''}</td>
        <td data-label="Total" class="num">${amount({ currency: o.currency, fxRate: o.customerTotal ? o.customerTotal / o.total : 0 }, o.total)}</td>
        <td data-label="Date"><span data-noi18n>${esc(fmtDate(o.createdAt))}</span></td></tr>`).join('')
        : `<tr><td colspan="6"><p class="empty">${q.q ? 'No order matches your search.' : 'No orders here yet.'}</p></td></tr>`}
      </tbody></table></div>
      ${pages > 1 ? `<div class="pager"><span class="muted" data-noi18n>${nf(r.total)}</span>
        <span>${page > 1 ? `<button class="btn btn-sm" data-page="${page - 1}">Previous</button>` : ''} <span data-noi18n>${page} / ${pages}</span> ${page < pages ? `<button class="btn btn-sm" data-page="${page + 1}">Next</button>` : ''}</span></div>` : ''}`;
    const reload = (obj = {}) => { const nq = { tab, q: q.q || '', page, ...obj }; setQuery(nq); this.view(main, { ...route, query: nq }, alive); };
    main.querySelector('#oq').addEventListener('input', debounce((e) => reload({ q: e.target.value.trim(), page: 1 }), 400));
    const pkRefresh = () => pickupBanner(main.querySelector('#pkopen'), pkRefresh);
    pkRefresh();
    main.querySelector('#pkbtn').addEventListener('click', () => pickupModal(pkRefresh));
    main.querySelectorAll('[data-page]').forEach((b) => b.addEventListener('click', () => reload({ page: +b.dataset.page })));
    main.querySelectorAll('tr.rowlink').forEach((tr) => tr.addEventListener('click', (e) => { if (!e.target.closest('a, button, .pick')) go(tr.dataset.href); }));
    bindBulk(main, () => this.view(main, route, alive));
  },
};

/* Several orders at once: print (invoice, and the SMSA waybill for orders abroad), labels in one PDF, send to SMSA. */
function bindBulk(main, reload) {
  const bar = main.querySelector('#obulk');
  const picks = () => [...main.querySelectorAll('[data-pick]:checked')].map((c) => +c.dataset.pick);
  const sync = () => { const n = picks().length; bar.hidden = !n; main.querySelector('#ocount').textContent = nf(n); };
  main.querySelectorAll('[data-pick]').forEach((c) => c.addEventListener('change', sync));
  const all = main.querySelector('#oall');
  if (all) all.addEventListener('change', () => { main.querySelectorAll('[data-pick]').forEach((c) => { c.checked = all.checked; }); sync(); });
  bar.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-bulk]');
    if (!b) return;
    const ids = picks();
    if (b.dataset.bulk === 'none') { main.querySelectorAll('[data-pick], #oall').forEach((c) => { c.checked = false; }); sync(); return; }
    if (b.dataset.bulk === 'print') { window.open('/api/admin/orders/print?ids=' + ids.join(','), '_blank', 'noopener'); return; }
    if (b.dataset.bulk === 'labels') { window.open('/api/admin/orders/labels?ids=' + ids.join(','), '_blank', 'noopener'); return; }
    if (!(await confirmBox('Send the selected paid orders to SMSA? Each gets its waybill.', { ok: 'Send to SMSA' }))) return;
    try {
      const res = await busy(b, () => api('orders/ship', { method: 'POST', body: { ids } }));
      const lines = [`${nf(res.sent)} sent`, res.already ? `${nf(res.already)} already had a waybill` : ''].filter(Boolean);
      if (res.problems.length) {
        modal({ title: 'Sent to SMSA', body: `<p data-noi18n>${esc(lines.join(' · '))}</p><ul class="problems">${res.problems.map((p) => `<li><b data-noi18n>#${esc(p.number)}</b> <span data-noi18n>${esc(p.message)}</span></li>`).join('')}</ul>`,
          foot: '<button class="btn btn-primary" data-close>OK</button>' });
      } else toast(lines.join(' · '));
      reload();
    } catch (err) { toastErr(err); }
  });
}

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

  const pickup = /pick|collect/i.test(o.shippingMethod || '');
  const steps = paid && !closed ? [['processing', 'Preparing'], ...(pickup ? [['ready', 'Ready to collect']] : [['shipped', 'Shipped']]), ['delivered', 'Delivered']].filter(([k]) => k !== o.status) : [];

  main.innerHTML = `
    <div class="crumbs"><a href="#/orders">Orders</a> › <span data-noi18n>#${esc(o.number)}</span></div>
    <div class="phead"><div><h1><span>Order</span> <span data-noi18n>#${esc(o.number)}</span></h1>
      <p class="sub">${pill(L[o.status] || o.status, PILL[o.status] || '')} ${o.testMode ? pill('Test payment – no real money', 'amber') : ''} <span class="muted" data-noi18n>${esc(fmtDate(o.createdAt))}</span></p></div>
      <div class="actions">${steps.map(([k, l]) => `<button class="btn" data-step="${k}">${icon('check')} ${esc(l)}</button>`).join('')}
        ${steps.length && o.email ? '<label class="mini-check"><input type="checkbox" id="notify" checked> <span>Email the customer</span></label>' : ''}</div></div>
    <div class="ed-grid">
      <div class="ed-main">
        <div class="card"><h2>Products</h2>
          <div class="table-wrap"><table class="t"><tbody>${o.items.map((i) => `<tr>
            <td class="thumb">${i.image ? `<img src="${esc(i.image)}" alt="">` : `<span class="noimg">${icon('image')}</span>`}</td>
            <td>${i.productId ? `<a href="#/products/${i.productId}">${d(name(i))}</a>` : d(name(i))}<div class="muted small"><span data-noi18n>${esc(money(i.price))} × ${i.qty}</span></div></td>
            <td class="num">${amount(o, i.lineTotal)}</td></tr>`).join('')}
            <tr><td></td><td>Subtotal</td><td class="num">${amount(o, o.subtotal)}</td></tr>
            <tr><td></td><td>Delivery <span class="muted small" data-noi18n>(${nf(o.weightGrams / 1000, 2)} kg)</span></td><td class="num">${amount(o, o.shipping)}</td></tr>
            ${o.discount > 0 ? `<tr><td></td><td>Discount ${o.couponCode ? ltr('(' + o.couponCode + ')') : ''}</td><td class="num">−${amount(o, o.discount)}</td></tr>` : ''}
            <tr><td></td><td><b>Total</b></td><td class="num"><b>${amount(o, o.total)}</b></td></tr>
          </tbody></table></div>
          <p class="muted small">Charged: <b data-noi18n>${esc(o.chargeCurrency)} ${nf(o.chargeAmount, ['BHD', 'KWD', 'OMR'].includes(o.chargeCurrency) ? 3 : 2)}</b></p>
        </div>
        <div class="card"><h2>SMSA shipping</h2>${shipBox(o, r.smsa)}</div>
        <div class="card"><h2>History</h2>
          <form id="nf" class="note-row"><input class="input grow" name="note" placeholder="Add a note for the team (not shown to the customer)" aria-label="Add a note"><button class="btn">Add note</button></form>
          <div id="mails"></div>
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
          <p>${o.paymentStatus === 'refunded' ? pill('Refunded', 'hidden') : paid ? pill('Paid', 'green') : pill(o.paymentStatus === 'failed' ? 'Not completed' : 'Not paid', 'amber')}
            ${paid && o.refundedAmount > 0 ? pill('Partly refunded', 'amber') : ''} <span class="muted small">${o.method === 'whatsapp' ? 'WhatsApp order' : 'Card through Tap'}</span></p>
          ${o.heldUntil ? `<p class="muted small">${icon('bag')} <span>Stock kept for this order until</span> <span data-noi18n>${esc(fmtDate(o.heldUntil))}</span></p>` : ''}
          ${o.paidAt ? `<p class="muted small">Paid <span data-noi18n>${esc(fmtDate(o.paidAt))}</span></p>` : ''}
          ${o.tapChargeId ? `<p class="muted small">Tap charge: ${ltr(o.tapChargeId)} · <span data-noi18n>${esc(o.tapStatus || '')}</span>${o.tapMode === 'test' ? ' · ' + pill('Test', 'amber') : ''}</p>
            <button class="btn btn-sm" id="chk">${icon('check')} Check payment with Tap</button>` : ''}
          ${!paid && !closed ? `<button class="btn btn-sm" id="plink">${icon('card')} Payment link…</button>` : ''}
          ${!paid && !closed && manager ? `<button class="btn btn-sm" id="mpaid">Mark as paid…</button>` : ''}
          ${refundsBox(o, manager)}
          <p class="btn-row" style="margin-top:10px"><a class="btn btn-sm btn-primary" href="/api/admin/orders/${o.id}/print" target="_blank" rel="noopener">${icon('log')} ${o.abroad && o.shipment && o.shipment.awb && o.shipment.status !== 'cancelled' ? 'Print invoice + SMSA waybill' : 'Print invoice'}</a></p>
          <p class="muted small" style="margin-top:10px">Customer’s order page: <a href="${esc(o.customerUrl)}" target="_blank" rel="noopener">open</a> · <span>Customer’s invoice link:</span> <a href="${esc(o.invoiceUrl)}" target="_blank" rel="noopener">open</a></p>
          ${o.email ? `<button class="btn btn-sm" id="resend">${icon('mail')} ${paid ? 'Re-send confirmation' : 'Re-send order email'}</button>` : ''}
        </div>
        ${!closed && manager ? `<div class="card"><button class="btn btn-danger btn-sm" id="cancel">${icon('close')} Cancel order</button>
          <p class="muted small">Gives the stock back. It does not give the money back – use Refund for that.</p></div>` : ''}
      </div>
    </div>`;

  const reload = () => detail(main, route, alive);
  const act = async (btn, path, body, ok) => {
    try { await busy(btn, () => api(`orders/${id}/${path}`, { method: 'POST', body })); toast(ok); reload(); } catch (e) { toastErr(e); }
  };
  main.querySelectorAll('[data-step]').forEach((b) => b.addEventListener('click', () => act(b, 'status', { status: b.dataset.step, notify: (main.querySelector('#notify') || {}).checked !== false }, 'Saved')));
  const rs = main.querySelector('#resend');
  if (rs) rs.addEventListener('click', async () => {
    if (!(await confirmBox(`Email the customer again (${o.email})?`, { ok: 'Send' }))) return;
    act(rs, 'resend-email', {}, 'Sent');
  });
  api(`orders/${id}/emails`).then((m) => {
    const box = main.querySelector('#mails');
    if (!box || !m.emails.length) return;
    const ST = { sent: ['Sent', 'green'], failed: ['Failed', 'red'], queued: ['Waiting', 'amber'], sending: ['Sending', 'amber'], skipped: ['Not sent', 'hidden'] };
    box.innerHTML = `<details class="method-more"><summary class="muted small">${icon('mail')} <span>Emails about this order</span> <span data-noi18n>(${m.emails.length})</span></summary><ul class="list-rows small">${m.emails.map((x) =>
      `<li>${pill(...(ST[x.status] || [x.status]))} <span data-noi18n>${esc(x.subject)}</span> <span class="muted" data-noi18n>· ${esc(x.to_email)} · ${esc(fmtDate(x.created_at))}</span>${x.last_error ? `<div class="muted" data-noi18n>${esc(x.last_error)}</div>` : ''}</li>`).join('')}</ul>
      <a class="btn btn-sm" href="#/emails/log?q=${encodeURIComponent(o.number)}">${icon('log')} Open in Sent emails</a></details>`;
  }).catch(() => {});
  main.querySelector('#nf').addEventListener('submit', (e) => { e.preventDefault(); const v = e.target.note.value.trim(); if (v) act(e.submitter, 'note', { note: v }, 'Note added'); });
  const chk = main.querySelector('#chk');
  if (chk) chk.addEventListener('click', () => act(chk, 'check-payment', {}, 'Checked with Tap'));
  const pl = main.querySelector('#plink');
  if (pl) pl.addEventListener('click', () => modal({
    title: 'Payment link',
    body: `<p class="muted small">A link to pay this order by card. Send it again for a retry, after the amount changed, or for an order made by the team.</p>
      ${o.method === 'whatsapp' ? '<div class="box warn small">This WhatsApp order becomes a card order waiting for payment.</div>' : ''}
      <div class="btn-row"><button class="btn" data-via="copy">${icon('copy')} Copy the link</button><button class="btn" data-via="whatsapp">${icon('phone')} Send on WhatsApp</button>
      ${o.email ? `<button class="btn btn-primary" data-via="email">${icon('mail')} Send by email</button>` : ''}</div><p class="small" id="plout" data-noi18n></p>`,
    onOpen: (el, close) => el.querySelectorAll('[data-via]').forEach((b) => b.addEventListener('click', async () => {
      const via = b.dataset.via;
      const win = via === 'whatsapp' ? window.open('', '_blank') : null;
      try {
        const res = await busy(b, () => api(`orders/${id}/payment-link`, { method: 'POST', body: { via } }));
        if (via === 'copy') { try { await navigator.clipboard.writeText(res.payUrl); toast('Link copied'); } catch (e) { el.querySelector('#plout').textContent = res.payUrl; } return; }
        if (via === 'whatsapp') { if (win) win.location = res.whatsappUrl; else location.href = res.whatsappUrl; }
        else toast('Sent to ' + o.email);
        close(); reload();
      } catch (e) { if (win) win.close(); toastErr(e); }
    })),
  }));
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
    modal({
      title: 'Cancel order',
      body: `<form class="form-grid" novalidate><p class="full">The stock goes back on sale. It does not give the money back – use Refund for that.</p>
        <div class="field full"><label for="cr1">Reason (for the team)</label><input id="cr1" class="input"></div>
        <div class="field full"><label for="cr2">Message to the customer (optional)</label><input id="cr2" class="input" dir="auto"></div>
        ${o.email ? '<label class="mini-check full"><input type="checkbox" id="cnn" checked> <span>Email the customer</span></label>' : ''}</form>`,
      foot: '<button class="btn" data-close>Keep the order</button><button class="btn btn-danger" id="cok">Cancel order</button>',
      onOpen: (el, close) => el.querySelector('#cok').addEventListener('click', async (e) => {
        try {
          await busy(e.currentTarget, () => api(`orders/${id}/cancel`, { method: 'POST', body: { reason: el.querySelector('#cr1').value, customerReason: el.querySelector('#cr2').value, notify: (el.querySelector('#cnn') || {}).checked === true } }));
          close(); toast('Order cancelled'); reload();
        } catch (err) { toastErr(err); }
      }),
    });
  });
  bindShip(main, o, r.smsa, act, reload);
  bindRefunds(main, o, id, act, reload);
}

const dec = (c) => (['BHD', 'KWD', 'OMR', 'JOD'].includes(c) ? 3 : 2);
const cash = (c, n) => `<span data-noi18n>${esc(c)} ${nf(n, dec(c))}</span>`;
const REFUND_PILL = { done: ['Refunded', 'green'], pending: ['Refund pending', 'amber'], failed: ['Refund failed', 'red'] };

/* Refunds on the Payment card: the list, the pending ones to check, and the Refund button (managers). */
function refundsBox(o, manager) {
  const list = o.refunds.map((f) => `<li data-rf="${f.id}">
      <div>${pill(...REFUND_PILL[f.status])} <b>${cash(f.currency, f.amount)}</b> <span class="muted small">${f.method === 'tap' ? 'to the card' : 'recorded by hand'}</span></div>
      <div class="muted small"><span data-noi18n>${esc(fmtDate(f.at))}</span>${f.by ? ` · ${d(f.by)}` : ''}${f.reason ? ` · ${d(f.reason)}` : ''}${f.restocked ? ' · <span>items back in stock</span>' : ''}</div>
      ${f.status === 'pending' && manager ? (f.tapRefundId
        ? `<button class="btn btn-sm" data-rcheck="${f.id}">${icon('check')} Check with Tap</button>`
        : `<p class="muted small">Tap could not be reached. Look in the Tap dashboard, then:</p>
           <button class="btn btn-sm" data-rset="${f.id}" data-result="done">It went through</button> <button class="btn btn-sm btn-ghost" data-rset="${f.id}" data-result="failed">It did not go through</button>`) : ''}
    </li>`).join('');
  return `${list ? `<ul class="refunds">${list}</ul>` : ''}
    ${manager && o.refundable > 0 ? `<button class="btn btn-sm" id="refund">${icon('card')} Refund…</button>` : ''}`;
}

function bindRefunds(main, o, id, act, reload) {
  main.querySelectorAll('[data-rcheck]').forEach((b) => b.addEventListener('click', () => act(b, `refunds/${b.dataset.rcheck}/check`, {}, 'Checked with Tap')));
  main.querySelectorAll('[data-rset]').forEach((b) => b.addEventListener('click', async () => {
    const done = b.dataset.result === 'done';
    if (!(await confirmBox(done ? 'Did you see this refund in the Tap dashboard?' : 'Mark this refund as not done? The amount can be refunded again.', { ok: done ? 'Yes, it went through' : 'Not done' }))) return;
    act(b, `refunds/${b.dataset.rset}/check`, { result: b.dataset.result }, 'Saved');
  }));
  const btn = main.querySelector('#refund');
  if (!btn) return;
  const card = o.method === 'tap' && o.tapChargeId;
  const c = o.chargeCurrency;
  btn.addEventListener('click', () => modal({
    title: 'Refund',
    body: `<form id="rff" class="form-grid" novalidate>
      ${card ? `<div class="field full"><span class="field-label">How</span><div class="seg" role="radiogroup" aria-label="How">
        <label><input type="radio" name="method" value="tap" checked><span>Back to the card (Tap)</span></label>
        <label><input type="radio" name="method" value="manual"><span>Already given back another way</span></label></div></div>` : ''}
      <div class="field" data-field="amount"><label for="ramt"><span>Amount</span> <span data-noi18n>(${esc(c)})</span></label>
        <input id="ramt" name="amount" type="number" min="0" step="${dec(c) === 3 ? '0.001' : '0.01'}" max="${o.refundable}" value="${o.refundable.toFixed(dec(c))}" dir="ltr">
        <span class="hint"><span>Up to</span> ${cash(c, o.refundable)}</span></div>
      <div class="field" data-field="reason"><label for="rwhy">Reason (optional)</label><input id="rwhy" name="reason" maxlength="300" placeholder="e.g. out of stock, damaged"></div>
      <div class="field full" data-field="restock"><label class="mini-check"><input type="checkbox" name="restock" id="rstock" ${o.status === 'shipped' || o.status === 'delivered' ? '' : 'checked'}> <span>Put the items back in stock</span></label>
        <span class="hint" id="rstockhint">Only with a full refund. Untick if the goods were sent and are not coming back.</span></div>
      ${card ? '' : '<p class="muted small full">Give the money back yourself first (Benefit transfer, cash…). This only records it.</p>'}
    </form>`,
    foot: '<button class="btn" data-close>Cancel</button><button class="btn btn-primary" id="rfok">Refund</button>',
    onOpen: (el, close) => {
      const amt = el.querySelector('#ramt');
      const rs = el.querySelector('#rstock');
      const full = () => Math.abs((+amt.value || 0) - o.refundable) < 0.0005;
      const sync = () => { rs.disabled = !full(); if (!full()) rs.checked = false; };
      amt.addEventListener('input', sync); sync();
      el.querySelector('#rfok').addEventListener('click', async (e) => {
        const f = el.querySelector('#rff');
        clearErrors(f);
        const method = card ? f.querySelector('[name=method]:checked').value : 'manual';
        const body = { amount: +amt.value, reason: f.reason.value, restock: rs.checked, method };
        const sure = method === 'tap'
          ? `Refund ${c} ${(+amt.value).toFixed(dec(c))} to the customer’s card? This cannot be undone.`
          : `Record a refund of ${c} ${(+amt.value).toFixed(dec(c))}?`;
        if (!(await confirmBox(sure, { ok: 'Refund', danger: method === 'tap' }))) return;
        try {
          const res = await busy(e.currentTarget, () => api(`orders/${id}/refund`, { method: 'POST', body }));
          close();
          toast(res.refund.status === 'done' ? 'Refunded' : res.refund.status === 'pending' ? 'Refund sent – waiting for Tap' : 'Refund failed');
          reload();
        } catch (err) { showErrors(f, err); toastErr(err); reload(); }
      });
    },
  }));
}

/* SMSA (as in Sniper Safety): the waybill, its label, tracking – or why SMSA refused it, and "Try again". */
const SMSA_STATUS = { created: ['Waiting for SMSA pickup', 'amber'], moving: ['On the way with SMSA', 'blue'], delivered: ['Delivered by SMSA', 'green'], failed: ['Refused by SMSA', 'red'], cancelled: ['Waybill cancelled', 'hidden'] };
const SERVICE = { EIDL: 'By air (EIDL)', EIED: 'By road (EIED)', EDDL: 'Inside Bahrain (EDDL)' };

function shipBox(o, smsa) {
  const sh = o.shipment;
  const live = sh && sh.awb && sh.status !== 'cancelled';
  const paid = o.paymentStatus === 'paid' && !['cancelled', 'refunded'].includes(o.status);
  const canSend = paid && (!sh || ['failed', 'cancelled'].includes(sh.status));
  const st = sh ? SMSA_STATUS[sh.status] || [sh.status, ''] : null;
  let h = '';
  if (o.shippingMethodName && o.shippingMethodName.en) h += `<p class="muted small"><span>Delivery method</span>: <span data-noi18n>${esc(isAr() ? o.shippingMethodName.ar : o.shippingMethodName.en)}</span></p>`;
  if (live) {
    h += `<p><span class="muted small">Waybill</span> <b>${ltr(sh.awb)}</b> ${pill(st[0], st[1])} ${sh.mode === 'test' ? pill('Sandbox', 'amber') : ''}</p>
      <p class="muted small"><span>${esc(SERVICE[sh.service] || sh.service || '')}</span>${sh.dutiesBy ? ` · <span>Import VAT and duty:</span> <b>${sh.dutiesBy === 'shipper' ? 'we pay (billed to our SMSA account)' : 'the customer pays on delivery'}</b>` : ''}</p>`;
  }
  if (sh && sh.status === 'failed') h += `<div class="box warn small"><b>${esc(st[0])}</b> <span data-noi18n>${esc(sh.error)}</span>${sh.attempts > 1 ? ` <span class="muted" data-noi18n>(${nf(sh.attempts)}×)</span>` : ''}</div>`;
  if (!sh || sh.status === 'cancelled') {
    h += `<p class="muted">${!paid ? 'The waybill is created once the order is paid.'
      : smsa.enabled && smsa.method ? 'The waybill is being created – it appears here within a minute.'
        : smsa.configured ? 'Not sent to SMSA yet. Send it by hand below.' : 'SMSA is not set up. Admin › SMSA shipping: paste the API key and the sender address.'}</p>`;
  }
  if (live && sh.scans && sh.scans.length) {
    h += `<ul class="scans">${sh.scans.slice(0, 8).map((x) => `<li><span class="muted small" data-noi18n>${esc(fmtDate(x.at))}</span> <span data-noi18n>${esc(x.what)}</span>${x.city ? ` <span class="muted small" data-noi18n>${esc(x.city)}</span>` : ''}</li>`).join('')}</ul>`;
  } else if (live && sh.lastScan) {
    h += `<p>${icon('truck')} <span data-noi18n>${esc(sh.lastScan)}</span></p>`;
  }
  const pk = smsa.pickup;
  if (live && pk && pk.status === 'requested') {
    h += `<div class="box info small pickup-box">${icon('truck')} <b>SMSA pickup</b> <span data-noi18n dir="ltr">${esc(pk.when)}</span>
      <div class="muted">${pk.smsaRef ? `<span>SMSA booking no.</span> <b data-noi18n dir="ltr">${esc(pk.smsaRef)}</b>` : pk.mode !== 'live' ? '<span>Sandbox – not for SMSA</span>' : '<span>Not booked in SMSA yet – finish it from the orders list.</span>'} · <span>Request</span> <span data-noi18n>#${pk.id}</span>${pk.awbs.length > 1 ? ` · <span data-noi18n>${nf(pk.awbs.length)}</span> <span>waybills</span> <span class="muted" data-noi18n>(${pk.awbs.map((x) => esc(x.number)).join(', ')})</span>` : ''}</div></div>`;
  } else if (live && pk && pk.status === 'done') {
    h += `<p class="muted small">${icon('check')} <span>Collected by SMSA (pickup</span> <span data-noi18n>#${pk.id}, ${esc(pk.when)}</span>)</p>`;
  }
  if (canSend && smsa.configured) {
    h += `${smsa.problems.length ? `<div class="box warn small">${smsa.problems.map(esc).join('<br>')}</div>` : ''}
      <details class="ship-more"><summary class="muted small">Boxes and weight</summary><div class="form-grid ship-form">
        <div class="field"><label for="parcels">Boxes</label><input id="parcels" type="number" min="1" max="25" value="1" dir="ltr"></div>
        <div class="field"><label for="kg">Weight (kg)</label><input id="kg" type="number" min="0.1" step="0.1" value="${(Math.max(100, o.weightGrams) / 1000).toFixed(1)}" dir="ltr"></div></div></details>
      <p class="muted small">${smsa.mode === 'live' ? 'Live: this creates a real, billable SMSA parcel.' : 'Sandbox: a test waybill, no courier comes.'}</p>`;
  }
  h += `<div class="btn-row">
    ${live ? `<button class="btn btn-primary btn-sm" id="slabel">${icon('log')} Print SMSA label</button>
      <button class="btn btn-sm" id="track">${icon('truck')} Refresh tracking</button>
      <a class="btn btn-sm" href="${esc(sh.trackUrl)}" target="_blank" rel="noopener">SMSA tracking page</a>` : ''}
    ${canSend && smsa.configured ? `<button class="btn btn-sm${live ? '' : ' btn-primary'}" id="ship" ${smsa.problems.length ? 'disabled' : ''}>${icon('truck')} ${sh && sh.status === 'failed' ? 'Try again' : 'Send to SMSA now'}</button>` : ''}
    ${live && can('manager') && ['created'].includes(sh.status) ? '<button class="btn btn-sm btn-ghost" id="cship">Cancel waybill</button>' : ''}</div>`;
  return h;
}

/* The shop's SMSA pickups. SMSA's API cannot book a collection, so "Book SMSA pickup" plans it here (day, ready time,
   closing time, waybills), opens SMSA's dashboard (Manage Pickup › Initiate Pickup Request) and gives the answers for its
   form with copy buttons; SMSA's booking number is pasted back so the whole team sees it above the list. */
export async function pickupBanner(box, onChange) {
  if (!box) return;
  let r;
  try { r = await api('pickups'); } catch (e) { return; }
  box.innerHTML = r.open.map((p) => `<div class="box ${p.smsaRef || p.mode !== 'live' ? 'info' : 'warn'} small pickup-box">${icon('truck')} <b>SMSA pickup</b> <span data-noi18n dir="ltr">${esc(p.when)}</span>
    · ${p.smsaRef ? `<span>SMSA booking no.</span> <b data-noi18n dir="ltr">${esc(p.smsaRef)}</b>` : p.mode !== 'live' ? '<span>Sandbox – not for SMSA</span>' : '<b>Not booked in SMSA yet</b>'}
    <span class="muted"> · <span data-noi18n>${nf(p.parcels)}</span> <span>${p.parcels === 1 ? 'parcel' : 'parcels'}</span> · <span>Request</span> <span data-noi18n>#${p.id}</span></span>
    <span class="btn-row" style="display:inline-flex;margin-inline-start:8px"><button class="btn btn-sm${p.smsaRef ? '' : ' btn-primary'}" data-pkfill="${p.id}">${icon('edit')} ${p.smsaRef ? 'Form answers' : 'Finish in SMSA'}</button><button class="btn btn-sm btn-ghost" data-pkcancel="${p.id}">Cancel pickup</button></span></div>`).join('');
  box.querySelectorAll('[data-pkfill]').forEach((b) => b.addEventListener('click', () => fillModal(r.open.find((x) => x.id === +b.dataset.pkfill), false, onChange)));
  box.querySelectorAll('[data-pkcancel]').forEach((b) => b.addEventListener('click', async () => {
    const p = r.open.find((x) => x.id === +b.dataset.pkcancel);
    if (!(await confirmBox(p.smsaRef ? 'Cancel this pickup here? Cancel it in SMSA’s dashboard too.' : 'Cancel this pickup?', { ok: 'Cancel pickup', danger: true }))) return;
    try { await busy(b, () => api(`pickups/${p.id}/cancel`, { method: 'POST', body: {} })); toast('Pickup cancelled'); onChange(); } catch (e) { toastErr(e); }
  }));
}

/* SMSA's form answers (copy buttons, in the form's order) and the box for SMSA's booking number. */
function fillModal(p, opened, done) {
  const live = p.mode === 'live';
  modal({
    title: 'Book it in SMSA', wide: true,
    body: `${live ? `<ol class="pk-steps small"><li>${opened ? '<span>SMSA’s dashboard opened in a new tab.</span>' : `<a href="${esc(p.dashboardUrl)}" target="_blank" rel="noopener">Open SMSA’s dashboard</a>`} <span>Go to</span> <b>Manage Pickup › Initiate Pickup Request</b>.</li>
        <li>Copy each answer below into SMSA’s form, then submit it.</li><li>Paste the booking number SMSA shows, and save.</li></ol>`
      : '<div class="box warn small">Sandbox waybills: nothing to book in SMSA. This is only to try it.</div>'}
      <div class="pk-fields">${p.fields.map((x) => `<div class="pk-field"><span class="pk-label">${esc(x.label)}</span><span class="pk-value" data-noi18n dir="auto">${esc(x.value || '—')}</span>
        <button class="btn btn-sm" type="button" data-copy="${esc(x.key)}" ${x.value ? '' : 'disabled'}>${icon('copy')} Copy</button></div>`).join('')}</div>
      ${live ? `<form id="pkref" class="form-grid" novalidate><div class="field" data-field="ref"><label for="pkr">SMSA booking number</label>
        <input class="input" id="pkr" name="ref" dir="ltr" autocomplete="off" value="${esc(p.smsaRef)}" placeholder="${esc(isAr() ? 'الصقه هنا' : 'Paste it here')}"></div></form>` : ''}`,
    foot: `<button class="btn" data-close>Close</button>${live ? '<button class="btn btn-primary" id="pkrsave">Save booking number</button>' : ''}`,
    onOpen: (el, close) => {
      el.querySelectorAll('[data-copy]').forEach((b) => b.addEventListener('click', async () => {
        const f = p.fields.find((x) => x.key === b.dataset.copy);
        try { await navigator.clipboard.writeText(f.value); b.classList.add('btn-primary'); toast('Copied'); } catch (e) { toastErr(e); }
      }));
      const sv = el.querySelector('#pkrsave');
      if (sv) sv.addEventListener('click', async () => {
        const form = el.querySelector('#pkref'); clearErrors(form);
        try { const res = await busy(sv, () => api(`pickups/${p.id}/ref`, { method: 'POST', body: { ref: form.ref.value } })); toast(`SMSA booking no. ${res.pickup.smsaRef}`); close(); done(); } catch (err) { showErrors(form, err); }
      });
    },
  });
}

export async function pickupModal(done) {
  let f;
  try { f = await api('pickups/form'); } catch (e) { toastErr(e); return; }
  if (!f.waiting.length) { toast('No SMSA waybills are waiting for collection. Send the orders to SMSA first.', 'err'); return; }
  if (!f.days.length) { toast('No pickup times left in the next days.', 'err'); return; }
  const ar = isAr();
  const opts = (list, sel) => list.map((t) => `<option value="${t}"${t === sel ? ' selected' : ''}>${t}</option>`).join('');
  const live = f.waiting.some((x) => x.mode === 'live');
  modal({
    title: 'Book SMSA pickup', wide: true,
    body: `<form id="pkf" class="form-grid" novalidate>
      <p class="muted small full"><span>SMSA collects between</span> <b data-noi18n dir="ltr">${esc(f.open)}–${esc(f.close)}</b>. <span>Choose the day, when the parcels are ready and when the shop closes for the courier.</span></p>
      ${live ? '' : '<div class="box warn small full">Sandbox waybills: the booking is saved here but not sent to SMSA.</div>'}
      <div class="field" data-field="date"><label for="pkd">Day</label><select class="input" id="pkd" name="date">${f.days.map((d) => `<option value="${d.date}">${esc(d.today ? (ar ? 'اليوم – ' : 'Today – ') : '')}${esc(ar ? d.label.ar : d.label.en)}</option>`).join('')}</select></div>
      <div class="field" data-field="from"><label for="pkfrom">Ready time</label><select class="input" id="pkfrom" name="from" dir="ltr" data-noi18n></select></div>
      <div class="field" data-field="until"><label for="pkuntil">Closing time</label><select class="input" id="pkuntil" name="until" dir="ltr" data-noi18n></select></div>
      <div class="field full"><span class="field-label">Waybills to collect</span><div class="chips">${f.waiting.map((x) => `<label class="chip-check"><input type="checkbox" data-wb="${x.orderId}" checked><span data-noi18n>#${esc(x.number)} · ${esc(x.awb)}${x.name ? ' · ' + esc(x.name) : ''}</span></label>`).join('')}</div></div>
      <div class="field" data-field="parcels"><label for="pkn">Parcels</label><input class="input" id="pkn" name="parcels" type="number" min="1" max="999" dir="ltr"></div>
      <div class="field"><span class="field-label">Heavy shipment</span><label class="mini-check"><input type="checkbox" id="pkh"> <span>Yes, it is heavy</span></label></div>
      <div class="field full" data-field="note"><label for="pknote">Note for SMSA (optional)</label><input class="input" id="pknote" name="note" maxlength="300" dir="auto" placeholder="${esc(ar ? 'مثال: اتصل عند الوصول' : 'e.g. call when you arrive')}"></div>
    </form>`,
    foot: `<button class="btn" data-close>Cancel</button><button class="btn btn-primary" id="pkok">${live ? 'Continue in SMSA' : 'Save'}</button>`,
    onOpen: (el, close) => {
      const form = el.querySelector('#pkf');
      const dSel = el.querySelector('#pkd'), fSel = el.querySelector('#pkfrom'), uSel = el.querySelector('#pkuntil'), nIn = el.querySelector('#pkn');
      const boxes = () => f.waiting.filter((x) => form.querySelector(`[data-wb="${x.orderId}"]`).checked).reduce((n, x) => n + (x.parcels || 1), 0);
      const hv = el.querySelector('#pkh');
      let hvTouched = false; hv.addEventListener('change', () => { hvTouched = true; });
      const syncBoxes = () => {
        nIn.value = Math.max(1, boxes());
        const kg = f.waiting.filter((x) => form.querySelector(`[data-wb="${x.orderId}"]`).checked).reduce((n, x) => n + (x.kg || 0), 0);
        if (!hvTouched) hv.checked = kg >= f.heavyKg;
      };
      form.querySelectorAll('[data-wb]').forEach((c) => c.addEventListener('change', syncBoxes)); syncBoxes();
      const fixUntil = () => {
        const minEnd = toMin(fSel.value) + Math.round(f.minHours * 60);
        [...uSel.options].forEach((op) => { op.disabled = toMin(op.value) < minEnd; });
        if (uSel.selectedOptions[0] && uSel.selectedOptions[0].disabled) uSel.value = [...uSel.options].find((op) => !op.disabled).value;
      };
      const fill = () => {
        const d = f.days.find((x) => x.date === dSel.value) || f.days[0];
        fSel.innerHTML = opts(d.from, d.from[0]);
        uSel.innerHTML = opts(d.until, d.until[d.until.length - 1]);
        fixUntil();
      };
      dSel.addEventListener('change', fill); fSel.addEventListener('change', fixUntil); fill();
      el.querySelector('#pkok').addEventListener('click', async (e) => {
        clearErrors(form);
        const ids = [...form.querySelectorAll('[data-wb]:checked')].map((x) => +x.dataset.wb);
        if (!ids.length) { toast('Tick at least one waybill.', 'err'); return; }
        const win = live ? window.open('', '_blank') : null;   // opened now, so the browser does not block it
        try {
          const res = await busy(e.currentTarget, () => api('pickups', { method: 'POST', body: {
            date: dSel.value, from: fSel.value, until: uSel.value, note: form.note.value, orderIds: ids, parcels: +nIn.value || 0, heavy: hv.checked } }));
          if (win) { try { win.opener = null; } catch (x) { /* ignore */ } win.location = res.pickup.dashboardUrl; }
          close(); done();
          fillModal(res.pickup, !!win, done);
        } catch (err) { if (win) win.close(); showErrors(form, err); toastErr(err); }
      });
    },
  });
}
const toMin = (t) => { const [h, m] = String(t).split(':').map(Number); return h * 60 + m; };

/* The label as SMSA made it, or with a share of the goods value printed on this copy only (never sent to SMSA). */
export function labelModal(id, subtotal) {
  modal({
    title: 'Print SMSA label',
    body: `<form id="lvf" class="form-grid" novalidate>
      <div class="field full"><span class="field-label">Declared value on the label</span><div class="seg" role="radiogroup" aria-label="Declared value on the label">
        <label><input type="radio" name="lv" value="100" checked><span>Full value</span></label>
        <label><input type="radio" name="lv" value="custom"><span>Part of the value</span></label></div></div>
      <div class="field" data-field="percentage"><label for="lvp">Percentage of the goods value</label><input id="lvp" type="number" min="0" max="100" step="1" value="50" dir="ltr" disabled>
        <span class="hint">Printed on this copy only. SMSA keeps the full value (<span data-noi18n>${esc(money(subtotal))}</span>).</span></div></form>`,
    foot: '<button class="btn" data-close>Cancel</button><button class="btn btn-primary" id="lvok">Open the label</button>',
    onOpen: (el, close) => {
      const pct = el.querySelector('#lvp');
      el.querySelectorAll('[name=lv]').forEach((r) => r.addEventListener('change', () => { pct.disabled = el.querySelector('[name=lv]:checked').value !== 'custom'; }));
      el.querySelector('#lvok').addEventListener('click', () => {
        const custom = el.querySelector('[name=lv]:checked').value === 'custom';
        const v = custom ? Math.max(0, Math.min(100, +pct.value || 0)) : 100;
        window.open(`/api/admin/orders/${id}/label${v < 100 ? '?percentage=' + v : ''}`, '_blank', 'noopener');
        close();
      });
    },
  });
}

function bindShip(main, o, smsa, act, reload) {
  const ship = main.querySelector('#ship');
  if (ship) ship.addEventListener('click', async () => {
    if (smsa.mode === 'live' && !(await confirmBox('Create a real SMSA waybill for this order?', { ok: 'Send to SMSA' }))) return;
    const parcels = main.querySelector('#parcels'); const kg = main.querySelector('#kg');
    act(ship, 'ship', { parcels: parcels ? +parcels.value || 1 : 1, weightKg: kg ? +kg.value || null : null }, 'Sent to SMSA');
  });
  const lb = main.querySelector('#slabel');
  if (lb) lb.addEventListener('click', () => labelModal(o.id, o.subtotal - o.discount));
  const tr = main.querySelector('#track');
  if (tr) tr.addEventListener('click', () => act(tr, 'track', {}, 'Tracking updated'));

  const cs = main.querySelector('#cship');
  if (cs) cs.addEventListener('click', async () => {
    if (!(await confirmBox('Cancel this SMSA waybill? It can no longer be used.', { ok: 'Cancel waybill', danger: true }))) return;
    act(cs, 'cancel-shipment', {}, 'Waybill cancelled');
  });
}
