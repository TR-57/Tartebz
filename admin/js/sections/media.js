import { api, upload, esc, d, ltr, pill, field, formData, showErrors, busy, toast, toastErr, modal, confirmBox, fmtDate, nf, debounce, setQuery, can, icon } from '../core.js';

const FILTERS = [['all', 'All files'], ['images', 'Pictures'], ['documents', 'PDF files'], ['unused', 'Not used anywhere']];

export const fileSize = (b) => (b >= 1048576 ? nf(b / 1048576, 1) + ' MB' : nf(Math.max(1, Math.round(b / 1024))) + ' KB');

function tile(m, selectable) {
  return `<div class="m-tile${m.used === false ? ' unused' : ''}" data-id="${m.id}">
    ${selectable ? `<label class="m-check"><input type="checkbox" data-sel="${m.id}" aria-label="Select"></label>` : ''}
    <button type="button" class="m-open" data-open="${m.id}">
      ${m.kind === 'image' ? `<img src="${esc(m.thumb)}" alt="${esc(m.altEn || '')}" loading="lazy">` : `<span class="m-doc">${icon('log')}<b>PDF</b></span>`}
    </button>
    <div class="m-meta"><b>${d(m.title || m.originalName)}</b>
      <span class="muted small"><span data-noi18n>${esc(fileSize(m.size))}${m.width ? ` · ${m.width}×${m.height}` : ''}</span>${m.used === false ? ' · ' + pill('Not used', 'amber') : ''}</span></div>
  </div>`;
}

export default {
  key: 'media', label: 'Media library', icon: 'image', role: 'staff', group: 'catalogue',
  async view(main, route, alive) {
    const q = route.query;
    const filter = FILTERS.some((f) => f[0] === q.filter) ? q.filter : 'all';
    const page = Math.max(1, +q.page || 1);
    const r = await api('media', { query: { filter, q: q.q || '', page } });
    if (!alive()) return;
    const manager = can('manager');
    const pages = Math.max(1, Math.ceil(r.total / r.per));

    main.innerHTML = `
      <div class="phead"><div><h1>Media library</h1><p class="sub">Every photo and PDF of the shop. Originals are kept exactly as uploaded; the website shows resized copies made from them.</p></div>
        <div class="actions">${manager && r.counts.unused ? `<button class="btn" id="del-unused">${icon('trash')} Delete files not used anywhere</button>` : ''}
        <label class="btn btn-primary">${icon('upload')} Upload<input type="file" id="up" multiple accept="image/jpeg,image/png,image/webp,image/gif,application/pdf" hidden></label></div></div>
      <div class="toolbar">
        <div class="chips">${FILTERS.map(([k, l]) => `<button type="button" class="chip" data-filter="${k}" aria-pressed="${filter === k}">${esc(l)} <span class="count-pill" data-noi18n>${nf(r.counts[k])}</span></button>`).join('')}</div>
        <input class="input grow" type="search" id="mq" value="${esc(q.q || '')}" placeholder="Search by name or description" aria-label="Search by name or description">
      </div>
      <div class="dropzone" id="drop"><span>${icon('upload')} Drop photos or PDF files here to upload them (up to 8 MB each)</span></div>
      ${manager ? `<div class="bulkbar" id="bulk" hidden><span id="bulk-n"></span><button class="btn btn-sm btn-danger" id="bulk-del">${icon('trash')} Delete selected</button></div>` : ''}
      ${r.media.length ? `<div class="m-grid">${r.media.map((m) => tile(m, manager)).join('')}</div>` : `<div class="card"><p class="empty">${filter === 'unused' ? 'Every file is used somewhere.' : 'No files yet. Upload the first one.'}</p></div>`}
      ${pages > 1 ? `<div class="pager"><span class="muted" data-noi18n>${nf(r.total)}</span>
        <span>${page > 1 ? `<button class="btn btn-sm" data-page="${page - 1}">Previous</button>` : ''} <span data-noi18n>${page} / ${pages}</span> ${page < pages ? `<button class="btn btn-sm" data-page="${page + 1}">Next</button>` : ''}</span></div>` : ''}`;

    const reload = (obj = {}) => { const nq = { filter, q: q.q || '', page, ...obj }; setQuery(nq); this.view(main, { ...route, query: nq }, alive); };
    main.querySelectorAll('[data-filter]').forEach((b) => b.addEventListener('click', () => reload({ filter: b.dataset.filter, page: 1 })));
    main.querySelectorAll('[data-page]').forEach((b) => b.addEventListener('click', () => reload({ page: +b.dataset.page })));
    main.querySelector('#mq').addEventListener('input', debounce((e) => reload({ q: e.target.value.trim(), page: 1 }), 400));

    const send = async (files) => {
      if (!files.length) return;
      toast(files.length > 1 ? 'Uploading files…' : 'Uploading…');
      try {
        const res = await upload('media', [...files], { field: 'files[]' });
        res.errors.forEach((e) => toast(`${e.name}: ${e.message}`, 'err'));
        if (!res.errors.length) toast('Uploaded');
        reload({ page: 1, filter: 'all' });
      } catch (e) {
        const errs = (e.data && e.data.errors) || [];
        toast(errs.length ? errs.map((x) => `${x.name}: ${x.message}`).join(' ') : e.message, 'err');
      }
    };
    main.querySelector('#up').addEventListener('change', (e) => send(e.target.files));
    const drop = main.querySelector('#drop');
    ['dragenter', 'dragover'].forEach((ev) => main.addEventListener(ev, (e) => { if (e.dataTransfer && [...e.dataTransfer.types].includes('Files')) { e.preventDefault(); drop.classList.add('on'); } }));
    ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, () => drop.classList.remove('on')));
    main.addEventListener('drop', (e) => { if (e.dataTransfer && e.dataTransfer.files.length) { e.preventDefault(); drop.classList.remove('on'); send(e.dataTransfer.files); } });

    main.querySelectorAll('[data-open]').forEach((b) => b.addEventListener('click', () => details(+b.dataset.open, () => reload())));

    if (manager) {
      const bar = main.querySelector('#bulk');
      const picked = () => [...main.querySelectorAll('[data-sel]:checked')].map((c) => +c.dataset.sel);
      main.querySelectorAll('[data-sel]').forEach((c) => c.addEventListener('change', () => {
        const n = picked().length;
        bar.hidden = !n;
        main.querySelector('#bulk-n').textContent = n === 1 ? '1 file selected' : `${n} files selected`;
      }));
      main.querySelector('#bulk-del').addEventListener('click', async (e) => {
        const ids = picked();
        if (!(await confirmBox('Delete the selected files? Files that are used somewhere are kept.', { ok: 'Delete', danger: true }))) return;
        try {
          const res = await busy(e.currentTarget, () => api('media/bulk-delete', { method: 'POST', body: { ids } }));
          toast(res.kept ? `${res.deleted} deleted, ${res.kept} kept because they are used` : `${res.deleted} deleted`);
          reload();
        } catch (err) { toastErr(err); }
      });
      const du = main.querySelector('#del-unused');
      if (du) du.addEventListener('click', async (e) => {
        if (!(await confirmBox(`Delete the ${r.counts.unused} files that are not used on the website, in products, settings or past orders? This cannot be undone.`, { ok: 'Delete', danger: true }))) return;
        try {
          const res = await busy(e.currentTarget, () => api('media/bulk-delete', { method: 'POST', body: { unused: true } }));
          toast(`${res.deleted} deleted`); reload({ filter: 'all', page: 1 });
        } catch (err) { toastErr(err); }
      });
    }
  },
};

/** The details window: preview, link, where it is used, name and descriptions; replace and delete for managers. */
async function details(id, onChange) {
  let r;
  try { r = await api('media/' + id); } catch (e) { toastErr(e); return; }
  const m = r.media;
  const manager = can('manager');
  const abs = location.origin + m.url;
  modal({
    title: 'File details', wide: true,
    body: `<div class="m-detail">
      <div class="m-preview">${m.kind === 'image' ? `<img src="${esc(m.thumb.replace('/img/400/', '/img/800/'))}" alt="">` : `<span class="m-doc big">${icon('log')}<b>PDF</b></span>`}
        <p class="muted small"><span data-noi18n>${esc(m.originalName)} · ${esc(fileSize(m.size))}${m.width ? ` · ${m.width}×${m.height}` : ''} · ${esc(m.mime)}</span></p>
        <p class="muted small">Uploaded <span data-noi18n>${esc(fmtDate(m.createdAt))}</span>${m.uploadedBy ? ` · ${d(m.uploadedBy)}` : ''}</p>
        <div class="copyrow"><input class="input" readonly value="${esc(abs)}" dir="ltr" aria-label="Link"><button type="button" class="btn btn-sm" id="cp">${icon('copy')} Copy link</button>
        <a class="btn btn-sm" href="${esc(m.url)}" target="_blank" rel="noopener">${icon('eye')} Open original</a></div>
      </div>
      <div>
        <form id="mf" class="form-grid" novalidate>
          ${field('title', 'Name', m.title, { full: true })}
          ${field('altEn', 'Description for screen readers (English)', m.altEn, { full: true, hint: 'What the picture shows, for people who cannot see it and for Google.' })}
          ${field('altAr', 'Description for screen readers (Arabic)', m.altAr, { full: true, dir: 'rtl' })}
        </form>
        <h3 style="margin-top:14px">Where it is used</h3>
        ${r.usage.length ? `<ul class="usage">${r.usage.map((u) => `<li>${u.link ? `<a href="${esc(u.link)}" data-close>${d(u.label)}</a>` : d(u.label)}</li>`).join('')}</ul>` : '<p class="muted">Not used anywhere.</p>'}
        ${manager ? `<div class="box info small">Replacing keeps the same link, so every product and page that uses it shows the new file.</div>` : ''}
      </div></div>`,
    foot: `${manager ? `<button class="btn btn-danger" id="del">${icon('trash')} Delete</button>
      <label class="btn">${icon('upload')} Replace file<input type="file" id="rep" hidden accept="${m.kind === 'image' ? 'image/jpeg,image/png,image/webp,image/gif' : 'application/pdf'}"></label>` : ''}
      <span style="flex:1"></span><button class="btn" data-close>Close</button><button class="btn btn-primary" id="save">Save</button>`,
    onOpen: (el, close) => {
      el.querySelector('#cp').addEventListener('click', async () => {
        try { await navigator.clipboard.writeText(abs); toast('Link copied'); } catch (e) { el.querySelector('.copyrow input').select(); }
      });
      el.querySelector('#save').addEventListener('click', async (e) => {
        const form = el.querySelector('#mf');
        try { await busy(e.currentTarget, () => api('media/' + id, { method: 'PUT', body: formData(form) })); toast('Saved'); close(); onChange(); } catch (err) { showErrors(form, err); }
      });
      const rep = el.querySelector('#rep');
      if (rep) rep.addEventListener('change', async () => {
        if (!rep.files[0]) return;
        try {
          const res = await upload('media/' + id + '/replace', rep.files[0]);
          toast(res.linksUpdated ? `Replaced – ${res.linksUpdated} places now show the new file` : 'Replaced');
          close(); onChange();
        } catch (err) { toastErr(err); }
      });
      const del = el.querySelector('#del');
      if (del) del.addEventListener('click', async () => {
        const used = r.usage.length;
        const msg = used ? `This file is used in ${used} places. Those places will show a broken picture. Delete it anyway?` : 'Delete this file? This cannot be undone.';
        if (!(await confirmBox(msg, { ok: 'Delete', danger: true }))) return;
        try { await api('media/' + id, { method: 'DELETE', body: { force: used > 0 } }); toast('Deleted'); close(); onChange(); } catch (err) { toastErr(err); }
      });
    },
  });
}

/**
 * Lets the person choose pictures from the library (or upload new ones). Resolves to a list of URLs (empty when cancelled).
 * kind: product | content | logo … (where a new upload goes; uploading needs a manager).
 */
export function pickMedia({ multiple = true, kind = 'product', title = 'Choose photos' } = {}) {
  return new Promise((resolve) => {
    let done = false;
    const chosen = new Map();
    const m = modal({
      title, wide: true,
      body: `<div class="toolbar"><input class="input grow" type="search" id="pq" placeholder="Search by name or description" aria-label="Search by name or description">
          ${can('manager') ? `<label class="btn">${icon('upload')} Upload new<input type="file" id="pu" ${multiple ? 'multiple' : ''} accept="image/jpeg,image/png,image/webp" hidden></label>` : ''}</div>
        <div class="m-grid pick" id="pg"><p class="loading">Loading…</p></div><div id="pmore"></div>`,
      foot: `<span class="muted" id="pn"></span><span style="flex:1"></span><button class="btn" data-close>Cancel</button><button class="btn btn-primary" id="pok" disabled>Use selected</button>`,
      onOpen: (el, close) => {
        let page = 1;
        let term = '';
        const grid = el.querySelector('#pg');
        const count = () => {
          el.querySelector('#pn').textContent = chosen.size ? (chosen.size === 1 ? '1 selected' : `${chosen.size} selected`) : '';
          el.querySelector('#pok').disabled = !chosen.size;
        };
        const load = async (append) => {
          try {
            const r = await api('media', { query: { filter: 'images', q: term, page, per: 48 } });
            const html = r.media.map((x) => `<button type="button" class="m-pick" data-url="${esc(x.url)}" aria-pressed="${chosen.has(x.url)}"><img src="${esc(x.thumb)}" alt="${esc(x.altEn || '')}" loading="lazy"><span data-noi18n>${esc(x.title || x.originalName)}</span></button>`).join('');
            if (append) grid.insertAdjacentHTML('beforeend', html); else grid.innerHTML = html || '<p class="empty">No pictures found.</p>';
            el.querySelector('#pmore').innerHTML = page * r.per < r.total ? '<button type="button" class="btn btn-sm" id="pm">Show more</button>' : '';
            const more = el.querySelector('#pm');
            if (more) more.addEventListener('click', () => { page++; load(true); });
          } catch (e) { grid.innerHTML = `<p class="box err">${esc(e.message)}</p>`; }
        };
        grid.addEventListener('click', (e) => {
          const b = e.target.closest('.m-pick');
          if (!b) return;
          const url = b.dataset.url;
          if (!multiple) { chosen.clear(); grid.querySelectorAll('.m-pick').forEach((x) => x.setAttribute('aria-pressed', 'false')); }
          if (chosen.has(url)) chosen.delete(url); else chosen.set(url, true);
          b.setAttribute('aria-pressed', String(chosen.has(url)));
          count();
        });
        el.querySelector('#pq').addEventListener('input', debounce((e) => { term = e.target.value.trim(); page = 1; load(false); }, 350));
        const pu = el.querySelector('#pu');
        if (pu) pu.addEventListener('change', async () => {
          const files = [...pu.files];
          for (const f of files) {
            try {
              const res = await upload('uploads', f, { query: { kind } });
              if (!multiple) chosen.clear();
              chosen.set(res.url, true);
            } catch (e) { toast(`${f.name}: ${e.message}`, 'err'); }
          }
          page = 1; count(); load(false);
        });
        el.querySelector('#pok').addEventListener('click', () => { done = true; close(); resolve([...chosen.keys()]); });
        load(false);
      },
    });
    const obs = new MutationObserver(() => { if (!document.body.contains(m.el)) { obs.disconnect(); if (!done) resolve([]); } });
    obs.observe(document.body, { childList: true });
  });
}
