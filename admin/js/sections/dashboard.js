import { api, esc, money, nf, state, fmtDate, pill, d } from '../core.js';

export default {
  key: '', label: 'Dashboard', icon: 'home', role: 'staff',
  async view(main, route, alive) {
    const r = await api('dashboard');
    if (!alive()) return;
    const c = r.counts;
    const tile = (label, val, sub, href, alert) =>
      `<${href ? `a href="${href}"` : 'div'} class="kpi${alert ? ' alert' : ''}${alert && val > 0 ? ' on' : ''}"><div class="k-label">${esc(label)}</div><div class="k-val" data-noi18n>${typeof val === 'number' ? nf(val) : val}</div>${sub ? `<div class="k-sub">${esc(sub)}</div>` : ''}</${href ? 'a' : 'div'}>`;
    const sales = (label, x) => tile(label, `<span data-noi18n>${money(x.revenue)}</span>`, `${nf(x.orders)} paid orders`);
    const max = Math.max(1, ...r.days.map((x) => x.revenue));
    main.innerHTML = `
      <div class="phead"><div><h1>Dashboard</h1><p class="sub"><span>Hello</span> <span data-noi18n>${esc(state.admin.name)}</span> · <span>Paid sales, net of refunds.</span></p></div></div>
      ${r.setup.length ? `<div class="box warn"><b>Before going fully live:</b><ul>${r.setup.map((s) => `<li><b>${esc(s.label)}</b> – ${esc(s.hint)}</li>`).join('')}</ul></div>` : ''}
      <div class="kpis">
        ${sales('Today', r.today)}${sales('Last 7 days', r.week)}${sales('Last 30 days', r.month)}
        ${tile('To handle', c.unhandled, 'paid orders not yet marked handled', '#/orders?status=unhandled', true)}
        ${tile('To ship', c.toShip, '', '#/orders?status=open')}
        ${tile('Awaiting payment', c.awaitingPayment, 'card, transfer or freight quote', '#/orders?status=awaiting_payment')}
        ${tile('New enquiries', c.enquiries, '', '#/enquiries?status=new', true)}
        ${tile('Low stock', c.lowStock, '', '#/products?tab=low', true)}
        ${c.mailFailed ? tile('Emails failed', c.mailFailed, 'check SMTP settings', '#/emails/sent?status=failed', true) : ''}
      </div>
      <div class="grid-2">
        <div class="card"><h2>Sales, last 14 days</h2>
          ${r.days.length ? `<div class="bars" role="img" aria-label="Sales, last 14 days">${r.days.map((x) => `<div class="bar" style="height:${Math.max(2, (x.revenue / max) * 100)}%" title="${esc(x.d)}: ${esc(money(x.revenue))}"></div>`).join('')}</div>
            <div class="bars-x" data-noi18n>${r.days.map((x) => `<span>${esc(x.d.slice(8))}</span>`).join('')}</div>` : '<p class="empty">No paid orders yet.</p>'}
        </div>
        <div class="card"><div class="card-head"><h2>Latest orders</h2><a href="#/orders">All orders</a></div>
          ${r.recent.length ? `<div class="list-rows">${r.recent.map((o) => `<a href="#/orders/${o.id}"><span>${d('#' + o.number)} ${d(o.name)}</span><span>${pill(o.statusLabel, o.status)} <b data-noi18n>${esc(money(o.total))}</b></span></a>`).join('')}</div>` : '<p class="empty">No orders yet.</p>'}
        </div>
      </div>`;
  },
};
