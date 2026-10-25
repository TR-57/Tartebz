import { api, esc, d, fmtDate } from '../core.js';

export default {
  key: 'activity', label: 'Activity log', icon: 'log', role: 'owner',
  async view(main, route, alive) {
    const r = await api('activity');
    if (!alive()) return;
    main.innerHTML = `
      <div class="phead"><div><h1>Activity log</h1><p class="sub">The latest 300 changes made in the admin, newest first.</p></div></div>
      ${r.rows.length ? `<div class="table-wrap"><table class="t cards"><thead><tr><th>When</th><th>Who</th><th>Action</th><th>What</th><th>IP</th></tr></thead><tbody>
      ${r.rows.map((x) => `<tr>
        <td data-label="When"><span data-noi18n>${esc(fmtDate(x.at))}</span></td>
        <td data-label="Who">${d(x.admin)}</td>
        <td data-label="Action"><span data-noi18n>${esc(x.action.replace(/[_.]/g, ' '))}</span></td>
        <td data-label="What"><span data-noi18n>${esc([x.entity, x.entityId].filter(Boolean).join(' #'))}</span>${x.details ? `<div class="mono muted" data-noi18n style="word-break:break-word">${esc(JSON.stringify(x.details))}</div>` : ''}</td>
        <td data-label="IP">${d(x.ip)}</td></tr>`).join('')}
      </tbody></table></div>` : '<p class="empty">Nothing recorded yet.</p>'}`;
  },
};
