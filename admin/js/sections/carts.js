import { api, esc, d, ltr, pill, toggle, field, busy, toast, toastErr, confirmBox, icon, fmtDate, modal, money, showErrors, clearErrors } from '../core.js';

/* Managers: abandoned carts – the list, the three reminder emails (with an optional personal code), previews, stats. */
const REASON = {
  ordered: 'Ordered', paid: 'Paid', emptied: 'Cart emptied', newer_cart: 'A newer cart took over', unsubscribed: 'Unsubscribed',
  too_old: 'Older than 30 days', finished: 'All emails sent', cancelled_by_staff: 'Order cancelled by staff', reopened: 'Order reopened',
};

export default {
  key: 'carts', label: 'Abandoned carts', icon: 'bag', role: 'manager', group: 'marketing',
  async view(main, route, alive) {
    const f = route.query.filter || '';
    const r = await api('carts', { query: { filter: f } });
    if (!alive()) return;
    const s = r.settings;
    const st = r.stats;
    const step = (i, label, hint) => {
      const x = s.steps[i] || { on: false, wait: 1, unit: 'hours' };
      return `<div class="card"><div class="card-head"><h3>${esc(label)}</h3><label class="switch"><input type="checkbox" data-step-on="${i}"${x.on ? ' checked' : ''}><span class="sw" aria-hidden="true"></span><span>On</span></label></div>
        <p class="muted small">${esc(hint)}</p>
        <div class="toolbar"><span>Wait</span><input class="input" type="number" min="0" style="width:90px" dir="ltr" data-step-wait="${i}" value="${x.wait}" aria-label="Wait">
          <select class="input" data-step-unit="${i}" aria-label="Unit"><option value="hours"${x.unit === 'hours' ? ' selected' : ''}>hours</option><option value="days"${x.unit === 'days' ? ' selected' : ''}>days</option></select></div></div>`;
    };
    const cc = s.coupon;
    main.innerHTML = `
      <div class="phead"><div><h1>Abandoned carts</h1><p class="sub">Up to three emails to people who left items in their cart (or did not finish paying), with an optional personal code.</p></div></div>
      <div class="kpis">
        <div class="kpi"><div class="k-label">Emails sent</div><div class="k-val" data-noi18n>${st.emailsSent}</div></div>
        <div class="kpi"><div class="k-label">Carts recovered</div><div class="k-val" data-noi18n>${st.recovered}</div></div>
        <div class="kpi"><div class="k-label">Sales recovered</div><div class="k-val" data-noi18n>${money(st.revenue)}</div></div>
        <div class="kpi"><div class="k-label">Codes used</div><div class="k-val" data-noi18n>${st.codesUsed}</div></div></div>
      <form id="cs" novalidate>
      <div class="card"><div class="card-head"><h2>Reminder emails</h2>${s.enabled ? pill('On', 'green') : pill('Off', 'hidden')}</div>
        ${toggle('enabled', 'Send abandoned-cart emails', s.enabled, 'Only carts left after you switch this on are emailed. Only people with an email address (typed at checkout).')}
        ${toggle('unpaidOrders', 'Also unpaid card orders', s.unpaidOrders, 'Orders whose payment was not finished in time get the same three emails, worded for an unfinished order. Orders you cancel are never emailed.')}</div>
      <div class="grid-3">
        ${step(0, 'Email 1 – reminder', 'The wait counts from when the cart was left.')}
        ${step(1, 'Email 2 – with a code', 'The wait counts from email 1.')}
        ${step(2, 'Email 3 – your code ends soon', 'The wait counts from email 2.')}</div>
      <div class="card"><h2>Personal code (email 2)</h2>
        ${toggle('couponOn', 'Give a personal code in email 2', cc.on, 'Locked to the customer’s email, one use, ends after the days below. Without it, email 2 is a reminder without a code.')}
        <div class="form-grid">
          ${field('couponType', 'Gives', cc.type, { options: [['percent', 'A percentage off'], ['fixed', 'An amount off (BHD)'], ['free_shipping', 'Free delivery']] })}
          ${field('coupon.value', 'Value', cc.value, { type: 'number', step: '0.001', dir: 'ltr' })}
          ${field('couponMin', 'Minimum order (BHD, optional)', cc.min ?? '', { type: 'number', step: '0.001', dir: 'ltr' })}
          ${field('couponDays', 'Valid for (days)', cc.days, { type: 'number', min: 1, dir: 'ltr' })}
          ${field('couponCooldown', 'No new code if they got one in the last (days)', cc.cooldownDays, { type: 'number', min: 0, dir: 'ltr' })}
        </div></div>
      <div class="savebar"><button type="button" class="btn" id="pv">${icon('eye')} Preview the emails</button><button class="btn btn-primary" type="submit">Save</button></div>
      </form>
      <h2>Carts</h2>
      <div class="tabs">${[['', 'All'], ['active', 'Emails still to come'], ['recovered', 'Recovered']].map(([k, l]) => `<a href="#/carts${k ? '?filter=' + k : ''}"${f === k ? ' aria-current="page"' : ''}>${esc(l)}</a>`).join('')}</div>
      ${r.carts.length ? `<div class="table-wrap"><table class="t cards"><thead><tr><th>Customer</th><th>Items</th><th class="num">Value</th><th>Last seen</th><th>Emails</th><th>Status</th><th></th></tr></thead><tbody>
      ${r.carts.map((c) => `<tr>
        <td data-label="Customer">${ltr(c.email)}${c.name ? `<div class="small">${d(c.name)}</div>` : ''}${c.unpaidOrder ? `<div class="small muted"><span>Unpaid order</span> ${d(c.unpaidOrder)}</div>` : ''}</td>
        <td data-label="Items" class="small">${c.items.map((i) => d(i.name + ' × ' + i.qty)).join('<br>')}</td>
        <td data-label="Value" class="num"><span data-noi18n>${money(c.value)}</span></td>
        <td data-label="Last seen" class="small"><span data-noi18n>${esc(fmtDate(c.lastSeen))}</span></td>
        <td data-label="Emails" class="small">${c.sent.map((x, i) => x ? `<div><span>Email</span> ${i + 1}: <span data-noi18n>${esc(fmtDate(x))}</span></div>` : '').join('') || '<span class="muted">None yet</span>'}${c.code ? `<div><span>Code</span> ${ltr(c.code)}${c.codeUsed ? ' ' + pill('Used', 'green') : ''}</div>` : ''}</td>
        <td data-label="Status">${c.recovered ? `${pill('Recovered', 'green')} <a href="#/orders/${c.recoveredOrderId}">${d(c.recovered)}</a>` : c.stopped ? pill(REASON[c.stopReason] || c.stopReason, 'hidden') : pill('Waiting', 'amber')}</td>
        <td>${!c.stopped && !c.recovered ? `<button class="btn btn-sm" data-send="${c.id}">${icon('send')} Send reminder now</button>` : ''}</td></tr>`).join('')}
      </tbody></table></div>` : '<p class="empty">No saved carts yet.</p>'}`;
    const form = main.querySelector('#cs');
    form.addEventListener('submit', async (e) => {
      e.preventDefault(); clearErrors(form);
      const q = (sel) => form.querySelector(sel);
      const body = {
        enabled: q('[name=enabled]').checked, unpaidOrders: q('[name=unpaidOrders]').checked,
        steps: [0, 1, 2].map((i) => ({ on: q(`[data-step-on="${i}"]`).checked, wait: +q(`[data-step-wait="${i}"]`).value, unit: q(`[data-step-unit="${i}"]`).value })),
        coupon: { on: q('[name=couponOn]').checked, type: q('[name=couponType]').value, value: q('[name="coupon.value"]').value, min: q('[name=couponMin]').value,
          days: q('[name=couponDays]').value, cooldownDays: q('[name=couponCooldown]').value },
      };
      if (body.enabled && !s.enabled && !(await confirmBox('Switch on abandoned-cart emails? Only carts left from now on are emailed.', { ok: 'Switch on' }))) return;
      try { await busy(form.querySelector('[type=submit]'), () => api('carts/settings', { method: 'PUT', body })); toast('Saved'); this.view(main, route, alive); } catch (err) { showErrors(form, err); }
    });
    main.querySelector('#pv').addEventListener('click', () => previews());
    main.querySelectorAll('[data-send]').forEach((b) => b.addEventListener('click', async () => {
      if (!(await confirmBox('Send this customer their next reminder email now?', { ok: 'Send now' }))) return;
      try { const res = await busy(b, () => api(`carts/${b.dataset.send}/send`, { method: 'POST', body: {} })); toast(res.sent ? `Email ${res.step} sent` : 'Not sent – ' + (res.stopped || 'nothing due')); this.view(main, route, alive); } catch (err) { toastErr(err); }
    }));
  },
};

function previews() {
  const keys = [['cart_1', 'Cart · email 1'], ['cart_2', 'Cart · email 2 (code)'], ['cart_2_nocode', 'Cart · email 2 (no code)'], ['cart_3', 'Cart · email 3 (code)'], ['cart_3_nocode', 'Cart · email 3 (no code)'],
    ['unpaid_1', 'Unpaid order · email 1'], ['unpaid_2', 'Unpaid order · email 2 (code)'], ['unpaid_2_nocode', 'Unpaid order · email 2 (no code)'], ['unpaid_3', 'Unpaid order · email 3 (code)'], ['unpaid_3_nocode', 'Unpaid order · email 3 (no code)']];
  modal({
    title: 'Preview the emails', wide: true,
    body: `<div class="toolbar"><select class="input grow" id="pk" aria-label="Email">${keys.map(([k, l]) => `<option value="${k}">${esc(l)}</option>`).join('')}</select>
      <select class="input" id="pl" aria-label="Language"><option value="ar">Arabic</option><option value="en">English</option></select>
      <a class="btn btn-sm" href="#/emails/cart_1" id="pe" data-close>${icon('edit')} Edit the words</a></div>
      <p class="muted small" id="ps"></p><iframe id="pf" title="Preview" style="width:100%;height:65vh;border:1px solid var(--line);border-radius:12px"></iframe>`,
    onOpen: (el) => {
      const load = async () => {
        const key = el.querySelector('#pk').value;
        el.querySelector('#pe').href = '#/emails/' + key;
        try { const p = await api('carts/preview', { method: 'POST', body: { key, lang: el.querySelector('#pl').value } }); el.querySelector('#pf').srcdoc = p.html; el.querySelector('#ps').innerHTML = `<b>Subject:</b> <span data-noi18n>${esc(p.subject)}</span>`; } catch (err) { toastErr(err); }
      };
      el.querySelector('#pk').addEventListener('change', load); el.querySelector('#pl').addEventListener('change', load); load();
    },
  });
}
