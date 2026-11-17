import { api, esc, d, pill, field, formData, busy, toast, toastErr, confirmBox, icon, fmtDate, go, showErrors, clearErrors, debounce } from '../core.js';

/* Managers: marketing campaigns – write it (Arabic and English), choose who gets it, preview, test, send in batches. */
export default {
  key: 'campaigns', label: 'Campaigns', icon: 'send', role: 'manager', group: 'marketing',
  async view(main, route, alive) {
    if (route.sub) return editor(main, route, alive);
    const r = await api('campaigns');
    if (!alive()) return;
    main.innerHTML = `
      <div class="phead"><div><h1>Campaigns</h1><p class="sub">Emails to your customers and subscribers – each in their own language, with their name and an unsubscribe link. People who unsubscribed are always left out.</p></div>
        <a class="btn btn-primary" href="#/campaigns/new">${icon('plus')} New campaign</a></div>
      ${r.campaigns.length ? `<div class="table-wrap"><table class="t cards"><thead><tr><th>Campaign</th><th>Status</th><th>Progress</th><th></th></tr></thead><tbody>
      ${r.campaigns.map((c) => `<tr>
        <td data-label="Campaign"><b>${d(c.name)}</b><div class="muted small" data-noi18n>${esc(c.subjectAr || c.subject)}</div></td>
        <td data-label="Status">${c.status === 'draft' ? pill('Draft', 'hidden') : c.status === 'sending' ? pill('Sending', 'amber') : pill('Sent', 'green')}${c.startedAt ? `<div class="muted small" data-noi18n>${esc(fmtDate(c.startedAt))}</div>` : ''}</td>
        <td data-label="Progress" class="small">${c.progress ? `<span>Sent</span> <span data-noi18n>${c.progress.sent} / ${c.progress.total}</span>${c.progress.failed ? ` · <span>Failed</span> <span data-noi18n>${c.progress.failed}</span>` : ''}${c.progress.remaining ? ` · <span>Left</span> <span data-noi18n>${c.progress.remaining}</span>` : ''}` : '–'}</td>
        <td><a class="btn btn-sm" href="#/campaigns/${c.id}">${c.status === 'draft' ? icon('edit') + ' Edit' : icon('eye') + ' View'}</a></td></tr>`).join('')}
      </tbody></table></div>` : '<p class="empty">No campaigns yet.</p>'}`;
  },
};

async function editor(main, route, alive) {
  const isNew = route.sub === 'new';
  const c = isNew ? { name: '', subject: '', subjectAr: '', body: '', bodyAr: '', audience: { type: 'all', countries: [], emails: [] }, status: 'draft' }
    : (await api('campaigns')).campaigns.find((x) => x.id === +route.sub);
  if (!alive()) return;
  if (!c) { main.innerHTML = '<p class="empty">That campaign does not exist.</p>'; return; }
  const sent = c.status !== 'draft';
  const a = c.audience || { type: 'all' };
  const ro = sent ? 'disabled' : '';
  main.innerHTML = `
    <div class="crumbs"><a href="#/campaigns">Campaigns</a> ›</div>
    <div class="phead"><div><h1>${isNew ? 'New campaign' : d(c.name)}</h1>${c.progress ? `<p class="sub"><span>Sent</span> <span data-noi18n>${c.progress.sent} / ${c.progress.total}</span> · <span>Failed</span> <span data-noi18n>${c.progress.failed}</span> · <span>Left</span> <span data-noi18n>${c.progress.remaining}</span></p>` : ''}</div></div>
    <form id="cf" novalidate>
    <div class="card"><div class="form-grid">${field('name', 'Name (only you see it)', c.name, { full: true })}</div></div>
    <div class="box info small">Plain text: a blank line starts a paragraph · **bold** · {name} · [button: Shop now | https://tartebz.shop/shop] · [image: https://…/picture.jpg]</div>
    <div class="grid-2">
      <div class="card"><h2>Arabic</h2>${field('subjectAr', 'Subject', c.subjectAr, { dir: 'rtl' })}${field('bodyAr', 'Message', c.bodyAr, { textarea: true, rows: 12, dir: 'rtl' })}</div>
      <div class="card"><h2>English</h2>${field('subject', 'Subject', c.subject, { dir: 'ltr' })}${field('body', 'Message', c.body, { textarea: true, rows: 12, dir: 'ltr' })}</div></div>
    <div class="card"><h2>Who gets it</h2>
      <div class="seg" role="radiogroup" aria-label="Audience">${[['all', 'All customers and subscribers'], ['countries', 'Customers in chosen countries'], ['list', 'A list of emails']].map(([k, l]) =>
        `<label><input type="radio" name="aud" value="${k}"${a.type === k ? ' checked' : ''} ${ro}><span>${esc(l)}</span></label>`).join('')}</div>
      <div id="aud-c" class="chips" style="margin-top:10px">${['BH', 'SA', 'AE', 'KW', 'QA', 'OM'].map((cc) => `<label class="chip-check"><input type="checkbox" data-cc="${cc}"${(a.countries || []).includes(cc) ? ' checked' : ''} ${ro}><span data-noi18n>${cc}</span></label>`).join('')}</div>
      <div id="aud-l" style="margin-top:10px">${field('emails', 'Emails (separated by commas or new lines)', (a.emails || []).join('\n'), { textarea: true, rows: 4, dir: 'ltr' })}</div>
      <p class="muted small" id="aud-n"></p></div>
    </form>
    <div class="card"><div class="card-head"><h2>${icon('eye')} Preview</h2><div class="seg"><label><input type="radio" name="pl" value="ar" checked><span>Arabic</span></label><label><input type="radio" name="pl" value="en"><span>English</span></label></div></div>
      <p class="muted small" id="ps">Save to see the preview.</p><iframe id="pf" title="Preview" style="width:100%;height:640px;border:1px solid var(--line);border-radius:12px;background:#fff"></iframe></div>
    ${sent ? '' : `<div class="savebar">${isNew ? '' : `<button class="btn btn-ghost" id="del">${icon('trash')} Delete</button><button class="btn" id="test">${icon('send')} Send test to me</button>`}<button class="btn" id="save">Save draft</button>${isNew ? '' : `<button class="btn btn-primary" id="send">${icon('send')} Send to everyone</button>`}</div>`}`;
  const form = main.querySelector('#cf');
  const audType = () => (form.querySelector('[name=aud]:checked') || {}).value || 'all';
  const audience = () => ({ type: audType(), countries: [...form.querySelectorAll('[data-cc]:checked')].map((x) => x.dataset.cc), emails: form.emails.value });
  const showAud = debounce(async () => {
    main.querySelector('#aud-c').hidden = audType() !== 'countries'; main.querySelector('#aud-l').hidden = audType() !== 'list';
    try { const n = await api('campaigns/audience', { method: 'POST', body: audience() }); main.querySelector('#aud-n').innerHTML = `<b data-noi18n>${n.count}</b> <span>people will get it.</span>`; } catch { /* ignore */ }
  }, 300);
  form.addEventListener('input', showAud); form.addEventListener('change', showAud); showAud();
  const preview = async () => {
    if (isNew) return;
    try { const p = await api(`campaigns/${c.id}/preview`, { method: 'POST', body: { lang: main.querySelector('[name=pl]:checked').value } }); main.querySelector('#pf').srcdoc = p.html; main.querySelector('#ps').innerHTML = `<b>Subject:</b> <span data-noi18n>${esc(p.subject)}</span>`; } catch (err) { toastErr(err); }
  };
  main.querySelectorAll('[name=pl]').forEach((x) => x.addEventListener('change', preview)); preview();
  const save = async (btn) => {
    clearErrors(form);
    const v = formData(form);
    const body = { name: v.name, subject: v.subject, subjectAr: v.subjectAr, body: v.body, bodyAr: v.bodyAr, audience: audience() };
    const res = await busy(btn, () => api(isNew ? 'campaigns' : 'campaigns/' + c.id, { method: isNew ? 'POST' : 'PUT', body }));
    return res.campaign;
  };
  const sv = main.querySelector('#save');
  if (sv) sv.addEventListener('click', async (e) => { try { const saved = await save(e.currentTarget); toast('Saved'); isNew ? go('#/campaigns/' + saved.id) : preview(); } catch (err) { showErrors(form, err); } });
  const tb = main.querySelector('#test');
  if (tb) tb.addEventListener('click', async (e) => { try { await save(null); const res = await busy(e.currentTarget, () => api(`campaigns/${c.id}/test`, { method: 'POST', body: { lang: main.querySelector('[name=pl]:checked').value } })); res.sent ? toast(`Sent to ${res.to}`) : toast(`Not sent: ${res.error}`, 'err'); } catch (err) { showErrors(form, err); } });
  const sb = main.querySelector('#send');
  if (sb) sb.addEventListener('click', async (e) => {
    try { await save(null); } catch (err) { return showErrors(form, err); }
    const n = await api('campaigns/audience', { method: 'POST', body: audience() });
    if (!(await confirmBox(`Send this campaign to ${n.count} people now? It cannot be stopped once it starts.`, { ok: 'Send now', danger: true }))) return;
    try { await busy(e.currentTarget, () => api(`campaigns/${c.id}/send`, { method: 'POST', body: {} })); toast('Sending – a batch goes out every minute'); editor(main, route, alive); } catch (err) { toastErr(err); }
  });
  const db = main.querySelector('#del');
  if (db) db.addEventListener('click', async () => { if (!(await confirmBox('Delete this draft?', { ok: 'Delete', danger: true }))) return; await api('campaigns/' + c.id, { method: 'DELETE' }); go('#/campaigns'); });
}
