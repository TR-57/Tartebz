import { api, esc, d, ltr, pill, field, toggle, formData, busy, toast, toastErr, confirmBox, icon, fmtDate, modal, showErrors, clearErrors, money } from '../core.js';

/* Managers: discount codes. A use counts when the order is paid and comes back if the order is cancelled. */
const SOURCE = { manual: 'Made here', cart: 'Abandoned cart' };

export default {
  key: 'coupons', label: 'Discounts & coupons', icon: 'percent', role: 'manager', group: 'marketing',
  async view(main, route, alive) {
    const src = route.query.source || '';
    const r = await api('coupons', { query: { source: src } });
    if (!alive()) return;
    const offer = (c) => c.type === 'percent' ? `${c.value}% off` : c.type === 'fixed' ? `${money(c.value)} off` : 'Free delivery';
    main.innerHTML = `
      <div class="phead"><div><h1>Discounts & coupons</h1><p class="sub">Codes customers type at checkout. Personal codes from abandoned-cart emails appear here too.</p></div>
        <button class="btn btn-primary" id="new">${icon('plus')} New code</button></div>
      <div class="tabs">${[['', 'All'], ...Object.entries(SOURCE)].map(([k, l]) => `<a href="#/coupons${k ? '?source=' + k : ''}"${src === k ? ' aria-current="page"' : ''}>${esc(l)}</a>`).join('')}</div>
      ${r.coupons.length ? `<div class="table-wrap"><table class="t cards"><thead><tr><th>Code</th><th>Gives</th><th>Valid</th><th>Used</th><th>Status</th><th></th></tr></thead><tbody>
      ${r.coupons.map((c) => `<tr>
        <td data-label="Code">${ltr(c.code)}<div class="muted small">${esc(SOURCE[c.source] || c.source)}${c.emails ? ` · <span data-noi18n>${esc(c.emails)}</span>` : ''}</div></td>
        <td data-label="Gives"><span>${esc(offer(c))}</span>${c.minSubtotal != null ? `<div class="muted small"><span>From</span> <span data-noi18n>${money(c.minSubtotal)}</span></div>` : ''}</td>
        <td data-label="Valid" class="small">${c.endsAt ? `<span>Until</span> <span data-noi18n>${esc(fmtDate(c.endsAt, { time: false }))}</span>` : '<span>No end date</span>'}</td>
        <td data-label="Used"><span data-noi18n>${c.used}${c.usageLimit ? ' / ' + c.usageLimit : ''}</span></td>
        <td data-label="Status">${c.enabled ? (c.endsAt && new Date(c.endsAt.replace(' ', 'T') + '+03:00') < new Date() ? pill('Expired', 'hidden') : pill('On', 'green')) : pill('Off', 'hidden')}</td>
        <td><div class="btn-row"><button class="btn btn-sm" data-edit="${c.id}">${icon('edit')} Edit</button><button class="btn btn-sm btn-ghost" data-del="${c.id}" aria-label="Delete">${icon('trash')}</button></div></td></tr>`).join('')}
      </tbody></table></div>` : '<p class="empty">No codes yet.</p>'}`;
    const reload = () => this.view(main, route, alive);
    main.querySelector('#new').addEventListener('click', () => edit(null, reload));
    main.querySelectorAll('[data-edit]').forEach((b) => b.addEventListener('click', () => edit(r.coupons.find((c) => c.id === +b.dataset.edit), reload)));
    main.querySelectorAll('[data-del]').forEach((b) => b.addEventListener('click', async () => {
      if (!(await confirmBox('Delete this code? If it was used it is switched off instead (the orders keep it).', { ok: 'Delete', danger: true }))) return;
      try { const res = await api('coupons/' + b.dataset.del, { method: 'DELETE' }); toast(res.disabled ? 'Used before – switched off instead' : 'Deleted'); reload(); } catch (err) { toastErr(err); }
    }));
  },
};

function edit(c, done) {
  c = c || { type: 'percent', enabled: true, value: 10 };
  const dt = (v) => (v ? String(v).slice(0, 10) : '');
  modal({
    title: c.id ? 'Edit code' : 'New code', wide: true,
    body: `<form id="cf" class="form-grid" novalidate>
      ${field('code', 'Code', c.code || '', { dir: 'ltr', hint: 'Letters and numbers, e.g. EID10.' })}
      ${field('type', 'Gives', c.type, { options: [['percent', 'A percentage off'], ['fixed', 'An amount off (BHD)'], ['free_shipping', 'Free delivery']] })}
      ${field('value', 'Value', c.value ?? '', { type: 'number', step: '0.001', dir: 'ltr', hint: 'Percent (1–100) or BHD. Not used for free delivery.' })}
      ${field('maxDiscount', 'Largest discount (BHD, optional)', c.maxDiscount ?? '', { type: 'number', step: '0.001', dir: 'ltr' })}
      ${field('minSubtotal', 'Minimum order (BHD, optional)', c.minSubtotal ?? '', { type: 'number', step: '0.001', dir: 'ltr' })}
      ${field('startsAt', 'Starts', dt(c.startsAt), { type: 'date', dir: 'ltr' })}
      ${field('endsAt', 'Ends', dt(c.endsAt), { type: 'date', dir: 'ltr', hint: 'Ends at the start of this day. Empty = no end.' })}
      ${field('usageLimit', 'Total uses (optional)', c.usageLimit ?? '', { type: 'number', min: 1, dir: 'ltr' })}
      ${field('perCustomerLimit', 'Uses per customer (optional)', c.perCustomerLimit ?? '', { type: 'number', min: 1, dir: 'ltr' })}
      ${field('title', 'Title (English, optional)', c.title || '')}
      ${field('titleAr', 'Title (Arabic, optional)', c.titleAr || '', { dir: 'rtl' })}
      ${field('emails', 'Only for these emails (optional)', c.emails || '', { textarea: true, rows: 2, dir: 'ltr', full: true, hint: 'Separate with commas. Empty = anyone.' })}
      <div class="full field"><span class="field-label">Applies to</span><p class="muted small">Leave all unticked for the whole cart. Tick categories or products to limit the code to them.</p>
        <div id="scope" class="chips"><span class="muted small">Loading…</span></div></div>
      <div class="full">${toggle('enabled', 'Code is on', c.enabled)}${toggle('combinable', 'Can be used with other discounts', c.combinable)}</div>
    </form>`,
    foot: '<button class="btn" data-close>Cancel</button><button class="btn btn-primary" id="cs">Save</button>',
    onOpen: (el, close) => {
      const form = el.querySelector('#cf');
      Promise.all([api('categories'), api('products')]).then(([cats, prods]) => {
        const box = el.querySelector('#scope');
        const chk = (kind, id, label, on) => `<label class="chip-check"><input type="checkbox" data-${kind}="${id}"${on ? ' checked' : ''}><span data-noi18n>${esc(label)}</span></label>`;
        box.innerHTML = (cats.categories || []).map((x) => chk('cat', x.id, x.name, (c.categoryIds || []).includes(x.id))).join('')
          + (prods.products || []).map((x) => chk('prod', x.id, x.name, (c.productIds || []).includes(x.id))).join('');
      }).catch(() => { el.querySelector('#scope').textContent = ''; });
      el.querySelector('#cs').addEventListener('click', async (e) => {
        clearErrors(form);
        try {
          await busy(e.currentTarget, () => api(c.id ? 'coupons/' + c.id : 'coupons', { method: c.id ? 'PUT' : 'POST', body: { ...formData(form),
            productIds: [...form.querySelectorAll('[data-prod]:checked')].map((x) => +x.dataset.prod),
            categoryIds: [...form.querySelectorAll('[data-cat]:checked')].map((x) => +x.dataset.cat) } }));
          toast('Saved'); close(); done();
        } catch (err) { showErrors(form, err); }
      });
    },
  });
}
