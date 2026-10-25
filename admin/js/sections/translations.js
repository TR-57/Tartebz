import { api, esc, d, can, toast, toastErr, setQuery, debounce, confirmBox, nf } from '../core.js';

const FILTERS = [['missing', 'Missing Arabic'], ['all', 'All text'], ['changed', 'Changed here'], ['keep', 'No translation needed']];

export default {
  key: 'translations', label: 'Translations', icon: 'lang', role: 'manager',
  async view(main, route, alive) {
    const q = route.query;
    const scope = q.scope === 'admin' ? 'admin' : 'site';
    const filter = FILTERS.some((f) => f[0] === q.filter) ? q.filter : 'missing';
    const page = Math.max(1, +q.page || 1);
    const r = await api('translations', { query: { scope, filter, q: q.q || '', page } });
    if (!alive()) return;
    const edit = can('manager');
    const pages = Math.max(1, Math.ceil(r.total / r.perPage));

    main.innerHTML = `
      <div class="phead"><div><h1>Translations</h1><p class="sub">Every text of the website and the admin, in English and Arabic. Type the Arabic and click away – it saves by itself and shows on the next page load.</p></div></div>
      <div class="card"><div class="card-head"><div><h2>Language the website opens in</h2><p class="muted small">For first-time visitors. A visitor's own choice is remembered in their browser.</p></div>
        <div class="seg" role="group" aria-label="Language the website opens in">
          <button type="button" data-dl="en" aria-pressed="${r.defaultLang === 'en'}" ${edit ? '' : 'disabled'}>English</button>
          <button type="button" data-dl="ar" aria-pressed="${r.defaultLang === 'ar'}" ${edit ? '' : 'disabled'}><span data-noi18n>العربية</span></button>
        </div></div></div>
      <div class="tabs" role="tablist">
        <a href="#/translations?scope=site" role="tab" aria-selected="${scope === 'site'}">Website text ${r.missing.site ? `<span class="count-pill red" data-noi18n>${nf(r.missing.site)}</span>` : ''}</a>
        <a href="#/translations?scope=admin" role="tab" aria-selected="${scope === 'admin'}">Admin panel text ${r.missing.admin ? `<span class="count-pill red" data-noi18n>${nf(r.missing.admin)}</span>` : ''}</a>
      </div>
      <div class="toolbar">
        <div class="chips">${FILTERS.map(([k, l]) => `<button type="button" class="chip" data-filter="${k}" aria-pressed="${filter === k}">${esc(l)} <span class="count-pill" data-noi18n>${nf(r.counts[k])}</span></button>`).join('')}</div>
        <input class="input grow" type="search" id="trq" value="${esc(q.q || '')}" placeholder="Search in English or Arabic" aria-label="Search in English or Arabic">
      </div>
      <div class="card" id="rows">
        ${r.rows.length ? r.rows.map((x) => row(x, edit)).join('') : `<p class="empty">${filter === 'missing' ? 'Nothing is missing – every text has its Arabic.' : 'Nothing matches.'}</p>`}
      </div>
      ${pages > 1 ? `<div class="pager"><span class="muted" data-noi18n>${nf(r.total)}</span>
        <span>${page > 1 ? `<button class="btn btn-sm" data-page="${page - 1}">Previous</button>` : ''} <span data-noi18n>${page} / ${pages}</span> ${page < pages ? `<button class="btn btn-sm" data-page="${page + 1}">Next</button>` : ''}</span></div>` : ''}`;

    const reload = (obj) => { setQuery({ scope, filter, q: q.q || '', page, ...obj }); this.view(main, { ...route, query: { scope, filter, q: q.q, page, ...obj } }, alive); };
    main.querySelectorAll('[data-dl]').forEach((b) => b.addEventListener('click', async () => {
      try {
        await api('translations/settings', { method: 'PUT', body: { defaultLang: b.dataset.dl } });
        main.querySelectorAll('[data-dl]').forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
        toast(b.dataset.dl === 'ar' ? 'Saved – new visitors now see the site in Arabic' : 'Saved – new visitors now see the site in English');
      } catch (e) { toastErr(e); }
    }));
    main.querySelectorAll('[data-filter]').forEach((b) => b.addEventListener('click', () => reload({ filter: b.dataset.filter, page: 1 })));
    main.querySelectorAll('[data-page]').forEach((b) => b.addEventListener('click', () => reload({ page: +b.dataset.page })));
    main.querySelector('#trq').addEventListener('input', debounce((e) => reload({ q: e.target.value.trim(), page: 1 }), 400));

    // Save on blur.
    main.querySelectorAll('.tr-row').forEach((el) => {
      const source = el.dataset.source;
      const stateEl = el.querySelector('.tr-state');
      const save = async (body) => {
        stateEl.className = 'tr-state'; stateEl.textContent = 'Saving…';
        try {
          await api('translations', { method: 'PUT', body: { scope, source, ...body } });
          stateEl.className = 'tr-state ok'; stateEl.textContent = '✓ Saved';
        } catch (e) { stateEl.className = 'tr-state err'; stateEl.textContent = e.message; }
      };
      el.querySelectorAll('textarea[data-k]').forEach((ta) => {
        ta.dataset.orig = ta.value;
        ta.addEventListener('blur', () => { if (ta.value !== ta.dataset.orig) { ta.dataset.orig = ta.value; save({ [ta.dataset.k]: ta.value }); } });
      });
      const keep = el.querySelector('input[data-keep]');
      if (keep) keep.addEventListener('change', () => save({ keep: keep.checked }));
      const showEn = el.querySelector('[data-show-en]');
      if (showEn) showEn.addEventListener('click', () => { el.querySelector('.en-edit').hidden = false; showEn.hidden = true; el.querySelector('.en-edit textarea').focus(); });
      el.querySelectorAll('[data-reset]').forEach((b) => b.addEventListener('click', async () => {
        const remove = b.dataset.reset === 'remove';
        if (remove && !(await confirmBox('Remove this text from the list? It comes back if a page reports it again.', { ok: 'Remove' }))) return;
        try { await api('translations/reset', { method: 'POST', body: { scope, source, remove } }); toast(remove ? 'Removed from the list' : 'Back to the original text'); reload({}); } catch (e) { toastErr(e); }
      }));
    });
  },
};

function row(x, edit) {
  const meta = [
    x.key ? `<span class="mono" data-noi18n>${esc(x.key)}</span>` : '',
    x.where ? `<span data-noi18n>${esc(x.where)}</span>` : '',
    x.reported ? `<span class="pill amber">Found on the Arabic site</span> <span data-noi18n>×${x.seen}</span>${x.page ? ` <span class="mono" data-noi18n>${esc(x.page)}</span>` : ''}` : '',
    x.changed ? '<span class="pill blue">Changed here</span>' : '',
    x.keep ? '<span class="pill">No translation needed</span>' : '',
  ].filter(Boolean).join(' ');
  const dis = edit ? '' : 'disabled';
  return `<div class="tr-row" data-source="${esc(x.source)}">
    <div class="tr-src"><b data-noi18n>${esc(x.en)}</b>${x.en !== x.enBuilt ? `<div class="muted small">Original: ${d(x.enBuilt)}</div>` : ''}<div class="meta">${meta}</div>
      ${edit ? `<button type="button" class="linkbtn small" data-show-en>Change the English wording</button>
      <div class="en-edit" hidden><textarea data-k="en" rows="2" dir="ltr" aria-label="English wording">${esc(x.en)}</textarea></div>` : ''}</div>
    <div class="tr-edit"><textarea data-k="ar" rows="2" dir="rtl" lang="ar" ${dis} aria-label="Arabic" placeholder="Arabic">${esc(x.ar)}</textarea>
      <div class="tr-tools">
        <label class="switch"><input type="checkbox" data-keep ${x.keep ? 'checked' : ''} ${dis}><span class="sw"></span><span>No translation needed</span></label>
        ${edit && x.changed ? '<button type="button" class="linkbtn" data-reset="reset">Back to original</button>' : ''}
        ${edit && x.reported && !x.builtIn ? '<button type="button" class="linkbtn" data-reset="remove">Remove from the list</button>' : ''}
        <span class="tr-state" aria-live="polite"></span>
      </div></div></div>`;
}
