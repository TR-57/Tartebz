import { api, esc, d, ltr, pill, toggle, field, formData, busy, toast, toastErr, confirmBox, icon, fmtDate, go, setQuery, debounce, modal, showErrors, clearErrors } from '../core.js';

/* Managers: every email the shop sends – when, to whom, the words (Arabic and English), on/off, preview, test, reset –
   and the log of everything sent. Words are plain text with simple markup, never HTML. */
const STATUS = { sent: ['Sent', 'green'], failed: ['Failed', 'red'], queued: ['Waiting', 'amber'], sending: ['Sending', 'amber'], skipped: ['Not sent', 'hidden'] };

export default {
  key: 'emails', label: 'Emails', icon: 'mail', role: 'manager', group: 'marketing',
  async view(main, route, alive) {
    if (route.sub === 'log') return log(main, route, alive);
    if (route.sub) return editor(main, route, alive);
    const r = await api('emails');
    if (!alive()) return;
    const s = r.settings;
    const groups = Object.entries(r.groups).map(([g, label]) => {
      const rows = r.emails.filter((x) => x.group === g);
      return `<div class="card"><h2>${esc(label)}</h2><div class="table-wrap"><table class="t cards"><thead><tr><th>Email</th><th>When it is sent</th><th>To</th><th>On</th><th></th></tr></thead><tbody>
        ${rows.map((x) => `<tr>
          <td data-label="Email"><a href="#/emails/${esc(x.key)}"><b>${esc(x.label)}</b></a>${x.custom ? ' ' + pill('Edited', 'amber') : ''}<div class="muted small">${x.lastSent ? `<span>Last sent</span> <span data-noi18n>${esc(fmtDate(x.lastSent))}</span>` : '<span>Not sent yet</span>'}</div></td>
          <td data-label="When it is sent" class="small">${esc(x.when)}</td>
          <td data-label="To" class="small">${esc(x.to)}</td>
          <td data-label="On">${x.optional ? `<label class="switch"><input type="checkbox" data-toggle="${esc(x.key)}"${x.enabled ? ' checked' : ''} aria-label="On"><span class="sw" aria-hidden="true"></span></label>` : '<span class="muted small">Always</span>'}</td>
          <td><a class="btn btn-sm" href="#/emails/${esc(x.key)}">${icon('edit')} Edit</a></td></tr>`).join('')}
        </tbody></table></div></div>`;
    }).join('');
    main.innerHTML = `
      <div class="phead"><div><h1>Emails</h1><p class="sub">Every email your shop sends, in Arabic and English. Change the words, switch the optional ones off, preview and send yourself a test.</p></div>
        <div class="btn-row"><a class="btn" href="#/emails/log">${icon('log')} Sent emails</a><button class="btn" id="all">${icon('send')} Send me one of every email</button></div></div>
      ${r.mode === 'website' ? '<div class="box warn">No SMTP is set, so the website sends email by itself. It works, but for the best delivery fill in Settings › Email sending.</div>' : ''}
      ${groups}
      <form id="es" class="card" novalidate><h2>Settings</h2><div class="form-grid">
        ${field('unknownLang', 'When a customer’s language is unknown', s.unknownLang, { options: [['ar', 'Arabic'], ['en', 'English'], ['both', 'Both languages in one email']] })}
        ${field('staffLang', 'Language of the team’s emails', s.staffLang, { options: [['en', 'English'], ['ar', 'Arabic']] })}
        ${field('reminderMinutes', 'Payment reminder: minutes after an unpaid card order', s.reminderMinutes, { type: 'number', min: 5, max: 1440, dir: 'ltr', hint: 'Sent once, while the items are still held for the customer.' })}
        ${field('reviewDays', 'Review request: days after shipping or delivery', s.reviewDays, { type: 'number', min: 0, max: 60, dir: 'ltr' })}
        ${field('payNote_ar', 'How to pay – orders not paid online (Arabic)', s.payNote_ar, { textarea: true, rows: 2, dir: 'rtl', hint: 'Shown in the “order received” email for WhatsApp orders.' })}
        ${field('payNote', 'How to pay – orders not paid online (English)', s.payNote, { textarea: true, rows: 2, dir: 'ltr' })}
        ${field('payMethods_ar', 'Payment methods (Arabic)', s.payMethods_ar, { dir: 'rtl', hint: 'Used in the payment link email: {pay_methods}.' })}
        ${field('payMethods', 'Payment methods (English)', s.payMethods, { dir: 'ltr' })}
      </div>
        ${toggle('reviewsEnabled', 'Reviews on product pages', s.reviewsEnabled, 'Customers who bought can rate and review; you approve each review first.')}
        ${toggle('reviewsAsk', 'Ask for a review by email', s.reviewsAsk)}
        ${toggle('utm', 'Tag links to the shop (utm_source=email)', s.utm, 'So sales from emails show in your analytics.')}
        <div class="savebar"><button class="btn btn-primary" type="submit">Save settings</button></div></form>`;
    main.querySelectorAll('[data-toggle]').forEach((el) => el.addEventListener('change', async () => {
      try { await api(`emails/${el.dataset.toggle}/toggle`, { method: 'POST', body: { on: el.checked } }); toast(el.checked ? 'Switched on' : 'Switched off'); } catch (err) { el.checked = !el.checked; toastErr(err); }
    }));
    main.querySelector('#all').addEventListener('click', async (e) => {
      if (!(await confirmBox('Send yourself one of every email (with sample details)? About 30 emails.', { ok: 'Send them' }))) return;
      try { const res = await busy(e.currentTarget, () => api('emails/send-all', { method: 'POST', body: {} })); toast(`Sending ${res.queued} emails to ${res.to}`); } catch (err) { toastErr(err); }
    });
    const form = main.querySelector('#es');
    form.addEventListener('submit', async (e) => {
      e.preventDefault(); clearErrors(form);
      try { await busy(form.querySelector('[type=submit]'), () => api('emails/settings', { method: 'PUT', body: formData(form) })); toast('Saved'); } catch (err) { showErrors(form, err); }
    });
  },
};

async function editor(main, route, alive) {
  const key = route.sub;
  const r = await api('emails/' + key);
  if (!alive()) return;
  const m = r.email;
  const vars = m.vars.map((v) => `<button type="button" class="chip" data-var="{${esc(v)}}" data-noi18n>{${esc(v)}}</button>`).join('');
  const lang = (l, label, dir) => `<div class="card"><h2>${esc(label)}</h2>
      <div class="field" data-field="${l}.subject"><label for="s-${l}">Subject</label><input id="s-${l}" class="input" dir="${dir}" data-l="${l}" data-k="subject" value="${esc(m[l].subject)}"></div>
      <div class="field" data-field="${l}.body"><label for="b-${l}">Message</label><textarea id="b-${l}" rows="14" dir="${dir}" data-l="${l}" data-k="body" style="font-family:inherit">${esc(m[l].body)}</textarea></div></div>`;
  main.innerHTML = `
    <div class="crumbs"><a href="#/emails">Emails</a> ›</div>
    <div class="phead"><div><h1>${esc(m.label)}</h1><p class="sub">${esc(m.when)} <b>To:</b> ${esc(m.to)}</p></div>
      ${m.optional ? `<label class="switch"><input type="checkbox" id="on"${m.enabled ? ' checked' : ''}><span class="sw" aria-hidden="true"></span><span>Send this email</span></label>` : ''}</div>
    <div class="box info small">Plain text: a blank line starts a new paragraph · **words** for bold · {name} and the other details below · {button: Label} for the main button · [button: Label | https://…] and [image: https://…] for extra buttons and pictures. Blocks such as {items} go on their own line.</div>
    <div class="card"><h2>Details you can use</h2><div class="chips">${vars}<button type="button" class="chip" data-var="{button: }">{button: …}</button></div><p class="muted small">Click to put it where the cursor is.</p></div>
    <div class="grid-2">${lang('ar', 'Arabic', 'rtl')}${lang('en', 'English', 'ltr')}</div>
    <div class="card"><div class="card-head"><h2>${icon('eye')} Preview</h2>
      <div class="seg" role="radiogroup" aria-label="Language"><label><input type="radio" name="pl" value="ar" checked><span>Arabic</span></label><label><input type="radio" name="pl" value="en"><span>English</span></label><label><input type="radio" name="pl" value="both"><span>Both</span></label></div></div>
      <p class="muted small" id="psub"></p>
      <iframe id="pv" title="Preview" style="width:100%;height:760px;border:1px solid var(--line);border-radius:12px;background:#fff"></iframe></div>
    <div class="savebar"><button class="btn" id="reset">Reset to default</button><button class="btn" id="test">${icon('send')} Send test to me</button><button class="btn btn-primary" id="save">Save</button></div>`;
  let lastEl = main.querySelector('#b-ar');
  main.querySelectorAll('[data-l]').forEach((el) => el.addEventListener('focus', () => { lastEl = el; }));
  main.querySelectorAll('[data-var]').forEach((b) => b.addEventListener('click', () => {
    const el = lastEl; const v = b.dataset.var; const at = el.selectionStart ?? el.value.length;
    el.value = el.value.slice(0, at) + v + el.value.slice(el.selectionEnd ?? at); el.focus(); el.selectionStart = el.selectionEnd = at + v.length; refresh();
  }));
  const words = () => {
    const o = { en: {}, ar: {} };
    main.querySelectorAll('[data-l]').forEach((el) => { o[el.dataset.l][el.dataset.k] = el.value; });
    return o;
  };
  const pl = () => main.querySelector('[name=pl]:checked').value;
  const refresh = debounce(async () => {
    try {
      const p = await api('emails/preview', { method: 'POST', body: { key, lang: pl(), ...words() } });
      main.querySelector('#pv').srcdoc = p.html; main.querySelector('#psub').innerHTML = `<b>Subject:</b> <span data-noi18n>${esc(p.subject)}</span>`;
    } catch (err) { toastErr(err); }
  }, 400);
  main.querySelectorAll('[data-l]').forEach((el) => el.addEventListener('input', refresh));
  main.querySelectorAll('[name=pl]').forEach((el) => el.addEventListener('change', refresh));
  refresh();
  const on = main.querySelector('#on');
  if (on) on.addEventListener('change', async () => { try { await api(`emails/${key}/toggle`, { method: 'POST', body: { on: on.checked } }); toast(on.checked ? 'Switched on' : 'Switched off'); } catch (err) { toastErr(err); } });
  main.querySelector('#save').addEventListener('click', async (e) => {
    try { await busy(e.currentTarget, () => api('emails/' + key, { method: 'PUT', body: words() })); toast('Saved'); } catch (err) { showErrors(main, err); toastErr(err); }
  });
  main.querySelector('#reset').addEventListener('click', async () => {
    if (!(await confirmBox('Go back to the original words for this email (both languages)?', { ok: 'Reset' }))) return;
    try { await api(`emails/${key}/reset`, { method: 'POST', body: {} }); toast('Back to the original words'); editor(main, route, alive); } catch (err) { toastErr(err); }
  });
  main.querySelector('#test').addEventListener('click', async (e) => {
    try {
      const res = await busy(e.currentTarget, () => api('emails/test', { method: 'POST', body: { key, lang: pl(), ...words() } }));
      res.sent ? toast(`Sent to ${res.to}`) : toast(`Not sent: ${res.error || res.status}`, 'err');
    } catch (err) { toastErr(err); }
  });
}

async function log(main, route, alive) {
  const q = route.query;
  const r = await api('emails/outbox', { query: { q: q.q || '', status: q.status || '', page: q.page || 1 } });
  if (!alive()) return;
  const c = r.counts || {};
  main.innerHTML = `
    <div class="crumbs"><a href="#/emails">Emails</a> ›</div>
    <div class="phead"><div><h1>Sent emails</h1><p class="sub">Every email, newest first. Failed ones are tried again automatically (up to 6 times); you can also retry them here.</p></div></div>
    <div class="kpis"><div class="kpi"><div class="k-label">Sent (7 days)</div><div class="k-val" data-noi18n>${c.sent || 0}</div></div><div class="kpi"><div class="k-label">Failed (7 days)</div><div class="k-val" data-noi18n>${c.failed || 0}</div></div><div class="kpi"><div class="k-label">Waiting</div><div class="k-val" data-noi18n>${(c.queued || 0) + (c.sending || 0)}</div></div></div>
    <div class="toolbar"><input class="input grow" id="q" value="${esc(q.q || '')}" placeholder="Search by email address or order number" dir="auto">
      <select id="st" class="input" aria-label="Status"><option value="">All</option>${Object.entries(STATUS).map(([k, [l]]) => `<option value="${k}"${q.status === k ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select></div>
    ${r.rows.length ? `<div class="table-wrap"><table class="t cards"><thead><tr><th>When</th><th>To</th><th>Email</th><th>Status</th><th></th></tr></thead><tbody>
    ${r.rows.map((x) => `<tr>
      <td data-label="When"><span data-noi18n>${esc(fmtDate(x.created_at))}</span></td>
      <td data-label="To">${ltr(x.to_email)}${x.order_number ? `<div class="small"><a href="#/orders/${x.order_id}"><span>Order</span> ${d(x.order_number)}</a></div>` : ''}</td>
      <td data-label="Email"><span data-noi18n>${esc(x.subject)}</span><div class="muted small" data-noi18n>${esc(x.email_key || x.kind)}</div></td>
      <td data-label="Status">${pill(...(STATUS[x.status] || [x.status]))}${x.attempts > 1 ? ` <span class="muted small" data-noi18n>×${x.attempts}</span>` : ''}${x.last_error ? `<div class="small err-text" data-noi18n style="color:var(--red);word-break:break-word">${esc(x.last_error)}</div>` : ''}</td>
      <td><div class="btn-row">${x.status !== 'skipped' ? `<button class="btn btn-sm" data-view="${x.id}">${icon('eye')} View</button>` : ''}${['failed', 'queued'].includes(x.status) ? `<button class="btn btn-sm" data-retry="${x.id}">Retry</button>` : ''}</div></td></tr>`).join('')}
    </tbody></table></div>
    <div class="pager"><span class="muted small"><span>Page</span> <span data-noi18n>${r.page} / ${Math.max(1, r.pages)}</span></span><div class="btn-row">
      ${r.page > 1 ? `<button class="btn btn-sm" data-page="${r.page - 1}">Previous</button>` : ''}${r.page < r.pages ? `<button class="btn btn-sm" data-page="${r.page + 1}">Next</button>` : ''}</div></div>` : '<p class="empty">No emails yet.</p>'}`;
  const reload = () => go(location.hash);
  const search = debounce(() => { setQuery({ q: main.querySelector('#q').value, page: '' }); reload(); }, 500);
  main.querySelector('#q').addEventListener('input', search);
  main.querySelector('#st').addEventListener('change', (e) => { setQuery({ status: e.target.value, page: '' }); reload(); });
  main.querySelectorAll('[data-page]').forEach((b) => b.addEventListener('click', () => { setQuery({ page: b.dataset.page }); reload(); }));
  main.querySelectorAll('[data-view]').forEach((b) => b.addEventListener('click', async () => {
    try {
      const e = (await api('emails/outbox/' + b.dataset.view)).email;
      modal({ title: e.subject, wide: true, body: `<p class="muted small"><span>To</span> ${ltr(e.to)}</p><iframe title="Email" style="width:100%;height:70vh;border:1px solid var(--line);border-radius:12px" srcdoc="${esc(e.html)}"></iframe>` });
    } catch (err) { toastErr(err); }
  }));
  main.querySelectorAll('[data-retry]').forEach((b) => b.addEventListener('click', async () => {
    try { const res = await busy(b, () => api(`emails/outbox/${b.dataset.retry}/retry`, { method: 'POST', body: {} })); res.sent ? toast('Sent') : toast(`Not sent: ${res.error}`, 'err'); reload(); } catch (err) { toastErr(err); }
  }));
}
