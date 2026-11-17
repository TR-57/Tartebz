import { api, esc, d, pill, busy, toast, toastErr, confirmBox, icon, fmtDate } from '../core.js';

/* Managers: product reviews wait here for approval before they show on the product page. */
export default {
  key: 'reviews', label: 'Reviews', icon: 'star', role: 'manager', group: 'marketing',
  async view(main, route, alive) {
    const st = route.query.status || 'pending';
    const r = await api('reviews', { query: { status: st } });
    if (!alive()) return;
    const n = r.counts || {};
    const stars = (x) => '<span style="color:#F5A400" aria-label="' + x + ' / 5">' + '★'.repeat(x) + '<span style="color:#ddd">' + '★'.repeat(5 - x) + '</span></span>';
    main.innerHTML = `
      <div class="phead"><div><h1>Reviews</h1><p class="sub">From customers who bought (each review link is for their own order). Approve a review to show it on the product page.</p></div></div>
      <div class="tabs">${[['pending', 'Waiting'], ['approved', 'Approved'], ['rejected', 'Hidden']].map(([k, l]) =>
        `<a href="#/reviews?status=${k}"${st === k ? ' aria-current="page"' : ''}>${esc(l)} <span class="count-pill${k === 'pending' && n.pending ? ' red' : ''}" data-noi18n>${n[k] || 0}</span></a>`).join('')}</div>
      ${r.reviews.length ? `<div class="table-wrap"><table class="t cards"><thead><tr><th>Product</th><th>Review</th><th>From</th><th></th></tr></thead><tbody>
      ${r.reviews.map((x) => `<tr>
        <td data-label="Product"><a href="/product/${encodeURIComponent(x.slug)}" target="_blank" rel="noopener">${d(x.product)}</a></td>
        <td data-label="Review">${stars(+x.rating)}${x.body ? `<div dir="auto" data-noi18n style="white-space:pre-wrap">${esc(x.body)}</div>` : ''}</td>
        <td data-label="From" class="small">${d(x.name)}${x.order_number ? `<div class="muted"><span>Order</span> <a href="#/orders/${x.order_id}">${d(x.order_number)}</a></div>` : ''}<div class="muted" data-noi18n>${esc(fmtDate(x.updated_at))}</div></td>
        <td><div class="btn-row">${st !== 'approved' ? `<button class="btn btn-sm btn-primary" data-st="approved" data-id="${x.id}">${icon('check')} Approve</button>` : ''}
          ${st !== 'rejected' ? `<button class="btn btn-sm" data-st="rejected" data-id="${x.id}">Hide</button>` : ''}
          <button class="btn btn-sm btn-ghost" data-del="${x.id}" aria-label="Delete">${icon('trash')}</button></div></td></tr>`).join('')}
      </tbody></table></div>` : '<p class="empty">Nothing here.</p>'}`;
    const reload = () => this.view(main, route, alive);
    main.querySelectorAll('[data-st]').forEach((b) => b.addEventListener('click', async () => {
      try { await busy(b, () => api('reviews/' + b.dataset.id, { method: 'POST', body: { status: b.dataset.st } })); toast(b.dataset.st === 'approved' ? 'Approved – it shows on the product page' : 'Hidden'); reload(); } catch (err) { toastErr(err); }
    }));
    main.querySelectorAll('[data-del]').forEach((b) => b.addEventListener('click', async () => {
      if (!(await confirmBox('Delete this review for good?', { ok: 'Delete', danger: true }))) return;
      try { await api('reviews/' + b.dataset.del, { method: 'DELETE' }); toast('Deleted'); reload(); } catch (err) { toastErr(err); }
    }));
  },
};
