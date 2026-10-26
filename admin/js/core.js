/* Admin core: API calls, toasts, modals, field errors, formatting, icons, roles. */

export const state = { admin: null, badges: {} };

const RANK = { staff: 1, manager: 2, owner: 3, order_manager: 0, order_assistant: 0 };

/** Ladder check (manager or owner…). Team roles never pass a ladder check. Mirrors the server. */
export function can(role) {
  const have = state.admin ? state.admin.role : '';
  if (role === 'managers') return have === 'owner' || have === 'manager';
  if (RANK[role] === 0) return have === role;
  return RANK[role] > 0 && (RANK[have] || 0) >= RANK[role];
}
export function canAny(roles) { return roles.some(can); }

export const isAr = () => document.documentElement.lang === 'ar';
export const t = (s) => (window.AdminI18n ? window.AdminI18n.t(s) : s);

/* ---------- API ---------- */
export class ApiError extends Error {
  constructor(status, body) {
    const e = (body && body.error) || {};
    super(e.message || 'Something went wrong on our side. Please try again.');
    this.status = status; this.code = e.code || 'error'; this.fields = e.fields || {};
  }
}
export async function api(path, { method = 'GET', body, query } = {}) {
  let url = '/api/admin/' + path.replace(/^\//, '');
  if (query) {
    const q = new URLSearchParams();
    Object.entries(query).forEach(([k, v]) => { if (v !== undefined && v !== null && v !== '') q.set(k, v); });
    if ([...q].length) url += (url.includes('?') ? '&' : '?') + q;
  }
  const opt = { method, credentials: 'same-origin', headers: { Accept: 'application/json' } };
  if (body !== undefined) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
  let res;
  try { res = await fetch(url, opt); } catch (e) { throw new ApiError(0, { error: { message: 'Could not reach the server. Check your connection and try again.' } }); }
  if (res.status === 204) return { ok: true };
  let data = null;
  try { data = await res.json(); } catch (e) { /* not json */ }
  if (!res.ok || !data || data.ok === false) {
    const err = new ApiError(res.status, data);
    if (res.status === 401 && path !== 'login' && path !== 'me') document.dispatchEvent(new CustomEvent('admin:signedout'));
    throw err;
  }
  return data;
}

/** Sends files as multipart/form-data. `files` is a File or a list of Files, sent under `field`. */
export async function upload(path, files, { field = 'file', query } = {}) {
  let url = '/api/admin/' + path.replace(/^\//, '');
  if (query) url += '?' + new URLSearchParams(query);
  const fd = new FormData();
  [].concat(files).forEach((f) => fd.append(field, f));
  let res;
  try { res = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' }, body: fd }); } catch (e) {
    throw new ApiError(0, { error: { message: 'Could not reach the server. Check your connection and try again.' } });
  }
  let data = null;
  try { data = await res.json(); } catch (e) { /* not json */ }
  if (res.status === 413) throw new ApiError(413, { error: { message: 'That file is over 8 MB.' } });
  if (!res.ok || !data || data.ok === false) {
    const err = new ApiError(res.status, data);
    err.data = data;
    if (res.status === 401) document.dispatchEvent(new CustomEvent('admin:signedout'));
    throw err;
  }
  return data;
}

/* ---------- escaping & small builders ---------- */
export function esc(v) {
  return String(v == null ? '' : v).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
/** Data that must never be translated (names, emails, codes…). */
export const d = (v) => `<span data-noi18n>${esc(v)}</span>`;
export const ltr = (v) => `<bdi dir="ltr" data-noi18n>${esc(v)}</bdi>`;

export function pill(text, cls = '') { return `<span class="pill ${esc(cls)}">${esc(text)}</span>`; }

export function field(name, label, value = '', o = {}) {
  const id = 'f-' + name.replace(/[^\w-]/g, '_') + '-' + Math.random().toString(36).slice(2, 6);
  const attrs = [
    `id="${id}"`, `name="${esc(name)}"`,
    o.type ? `type="${esc(o.type)}"` : 'type="text"',
    o.placeholder ? `placeholder="${esc(o.placeholder)}"` : '',
    o.autocomplete ? `autocomplete="${esc(o.autocomplete)}"` : '',
    o.inputmode ? `inputmode="${esc(o.inputmode)}"` : '',
    o.dir ? `dir="${esc(o.dir)}"` : '',
    o.required ? 'required' : '', o.disabled ? 'disabled' : '',
    o.min != null ? `min="${esc(o.min)}"` : '', o.max != null ? `max="${esc(o.max)}"` : '', o.step != null ? `step="${esc(o.step)}"` : '',
  ].filter(Boolean).join(' ');
  let control;
  if (o.options) {
    control = `<select id="${id}" name="${esc(name)}" ${o.disabled ? 'disabled' : ''}>` + o.options.map(([v, l]) =>
      `<option value="${esc(v)}"${String(v) === String(value) ? ' selected' : ''}>${esc(l)}</option>`).join('') + '</select>';
  } else if (o.textarea) {
    control = `<textarea id="${id}" name="${esc(name)}" rows="${o.rows || 3}" ${o.dir ? `dir="${esc(o.dir)}"` : ''} ${o.placeholder ? `placeholder="${esc(o.placeholder)}"` : ''}>${esc(value)}</textarea>`;
  } else {
    control = `<input ${attrs} value="${esc(value)}">`;
  }
  return `<div class="field${o.full ? ' full' : ''}" data-field="${esc(name)}"><label for="${id}">${esc(label)}</label>${control}${o.hint ? `<span class="hint">${esc(o.hint)}</span>` : ''}</div>`;
}

export function toggle(name, label, checked, hint = '') {
  return `<div class="field" data-field="${esc(name)}"><label class="switch"><input type="checkbox" name="${esc(name)}"${checked ? ' checked' : ''}><span class="sw" aria-hidden="true"></span><span>${esc(label)}</span></label>${hint ? `<span class="hint">${esc(hint)}</span>` : ''}</div>`;
}

/** Puts each server field error under its input; errors with no matching input go on top of the form. */
export function showErrors(form, err) {
  clearErrors(form);
  const fields = (err && err.fields) || {};
  const loose = [];
  Object.entries(fields).forEach(([k, msg]) => {
    const wrap = form.querySelector(`[data-field="${CSS.escape(k)}"]`);
    if (wrap) {
      wrap.classList.add('has-err');
      wrap.insertAdjacentHTML('beforeend', `<small class="f-err">${esc(msg)}</small>`);
    } else loose.push(msg);
  });
  if (loose.length || !Object.keys(fields).length) {
    form.insertAdjacentHTML('afterbegin', `<div class="box err form-err">${esc(loose.length ? loose.join(' ') : err.message)}</div>`);
  }
  const first = form.querySelector('.has-err input, .has-err select, .has-err textarea');
  if (first) first.focus();
}
export function clearErrors(form) {
  form.querySelectorAll('.f-err, .form-err').forEach((e) => e.remove());
  form.querySelectorAll('.has-err').forEach((e) => e.classList.remove('has-err'));
}
export function formData(form) {
  const out = {};
  form.querySelectorAll('input[name], select[name], textarea[name]').forEach((el) => {
    if (el.type === 'checkbox') out[el.name] = el.checked;
    else if (el.type === 'radio') { if (el.checked) out[el.name] = el.value; }
    else out[el.name] = el.value;
  });
  return out;
}
export async function busy(btn, fn) {
  if (!btn) return fn();
  btn.classList.add('busy'); btn.disabled = true;
  try { return await fn(); } finally { btn.classList.remove('busy'); btn.disabled = false; }
}

/* ---------- toast ---------- */
let toastTimer;
export function toast(msg, kind = '') {
  const el = document.getElementById('toast');
  el.textContent = t(msg);
  el.className = 'toast show ' + kind;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { el.className = 'toast'; }, 2800);
}
export const toastErr = (e) => toast((e && e.message) || String(e), 'err');

/* ---------- modal ---------- */
export function modal({ title, body = '', foot = '', wide = false, onOpen } = {}) {
  const wrap = document.createElement('div');
  wrap.className = 'modal-wrap';
  wrap.innerHTML = `<div class="modal${wide ? ' wide' : ''}" role="dialog" aria-modal="true" aria-labelledby="m-title">
    <div class="modal-head"><h2 id="m-title">${esc(title)}</h2><button class="iconbtn" data-close aria-label="Close">${icon('close')}</button></div>
    <div class="modal-body">${body}</div>${foot ? `<div class="modal-foot">${foot}</div>` : ''}</div>`;
  const prev = document.activeElement;
  const close = () => { wrap.remove(); document.removeEventListener('keydown', onKey); if (prev && prev.focus) prev.focus(); };
  const onKey = (e) => { if (e.key === 'Escape') close(); };
  wrap.addEventListener('click', (e) => { if (e.target === wrap || e.target.closest('[data-close]')) close(); });
  document.addEventListener('keydown', onKey);
  document.body.appendChild(wrap);
  const first = wrap.querySelector('.modal-body input:not([type=hidden]), .modal-body select, .modal-body textarea, .modal-foot .btn-primary');
  if (first) first.focus();
  if (onOpen) onOpen(wrap, close);
  return { el: wrap, close };
}
export function confirmBox(message, { title = 'Are you sure?', ok = 'Continue', danger = false } = {}) {
  return new Promise((resolve) => {
    let answered = false;
    const m = modal({
      title, body: `<p>${esc(message)}</p>`,
      foot: `<button class="btn" data-close>Cancel</button><button class="btn ${danger ? 'btn-danger' : 'btn-primary'}" data-ok>${esc(ok)}</button>`,
      onOpen: (el, close) => el.querySelector('[data-ok]').addEventListener('click', () => { answered = true; close(); resolve(true); }),
    });
    const obs = new MutationObserver(() => { if (!document.body.contains(m.el)) { obs.disconnect(); if (!answered) resolve(false); } });
    obs.observe(document.body, { childList: true });
  });
}

/* ---------- formatting ---------- */
const TZ = 'Asia/Bahrain';
export function fmtDate(v, { time = true, year = true } = {}) {
  if (!v) return '–';
  const dt = new Date(String(v).replace(' ', 'T') + (/[zZ]|[+-]\d\d:?\d\d$/.test(v) ? '' : '+03:00'));
  if (isNaN(dt)) return String(v);
  const loc = isAr() ? 'ar-BH-u-nu-latn' : 'en-GB';
  const date = dt.toLocaleDateString(loc, { day: 'numeric', month: 'short', year: year ? 'numeric' : undefined, timeZone: TZ });
  if (!time) return date;
  const tm = dt.toLocaleTimeString(isAr() ? 'ar-BH-u-nu-latn' : 'en-US', { hour: 'numeric', minute: '2-digit', hour12: true, timeZone: TZ }).replace('AM', 'am').replace('PM', 'pm');
  return `${date}, ${tm}`;
}
export function money(v, dec = 3) {
  const n = Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec });
  return isAr() ? `${n} د.ب` : `BHD ${n}`;
}
export const nf = (v, dec = 0) => Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec });

export function debounce(fn, ms = 300) { let h; return (...a) => { clearTimeout(h); h = setTimeout(() => fn(...a), ms); }; }

/* ---------- navigation ---------- */
export function parseHash() {
  const raw = location.hash.replace(/^#\/?/, '');
  const [path, qs] = raw.split('?');
  const parts = path.split('/').filter(Boolean);
  return { key: parts[0] || '', sub: parts[1] || '', rest: parts.slice(1), query: Object.fromEntries(new URLSearchParams(qs || '')) };
}
export function go(hash) { if (location.hash === hash) window.dispatchEvent(new HashChangeEvent('hashchange')); else location.hash = hash; }
export function setQuery(obj) {
  const p = parseHash();
  const q = new URLSearchParams();
  Object.entries({ ...p.query, ...obj }).forEach(([k, v]) => { if (v !== '' && v != null) q.set(k, v); });
  const path = '#/' + [p.key, ...p.rest].filter(Boolean).join('/');
  history.replaceState(null, '', path + ([...q].length ? '?' + q : ''));
}

/* ---------- icons ---------- */
const P = {
  home: '<path d="M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
  users: '<circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6"/><circle cx="17" cy="9" r="2.4"/><path d="M15.5 14.3c3 .1 5.5 2.5 5.5 5.7"/>',
  log: '<path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5M10 13h6M10 17h6"/>',
  lang: '<path d="M4 5h9M8.5 3v2M6 5c0 5 3 8 7 9M11 5c0 4-3 8-7 9"/><path d="M13 21l4-10 4 10M14.5 17h5"/>',
  user: '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8"/>',
  search: '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
  bell: '<path d="M6 16V11a6 6 0 0 1 12 0v5l2 2H4z"/><path d="M10 20a2 2 0 0 0 4 0"/>',
  menu: '<path d="M4 7h16M4 12h16M4 17h16"/>',
  close: '<path d="M6 6l12 12M18 6L6 18"/>',
  out: '<path d="M15 4h4v16h-4M10 8l-4 4 4 4M6 12h11"/>',
  store: '<path d="M4 9l1.5-5h13L20 9M4 9v11h16V9M4 9a2.7 2.7 0 0 0 5.3 0 2.7 2.7 0 0 0 5.4 0 2.7 2.7 0 0 0 5.3 0"/>',
  plus: '<path d="M12 5v14M5 12h14"/>',
  edit: '<path d="M4 20h4L19 9l-4-4L4 16z"/>',
  check: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
  key: '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M17 6l3 3"/>',
  chart: '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
  box: '<path d="M12 3l8 4.5v9L12 21l-8-4.5v-9z"/><path d="M4 7.5l8 4.5 8-4.5M12 12v9"/>',
  image: '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M21 17l-5-5-9 8"/>',
  tag: '<path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.5" cy="8.5" r="1.5"/>',
  coins: '<ellipse cx="9" cy="7" rx="6" ry="3"/><path d="M3 7v5c0 1.7 2.7 3 6 3s6-1.3 6-3V7"/><path d="M9 15v2c0 1.7 2.7 3 6 3s6-1.3 6-3v-5c0-1.7-2.7-3-6-3"/>',
  trash: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
  upload: '<path d="M12 16V4M7 9l5-5 5 5M4 20h16"/>',
  eye: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
  up: '<path d="M12 19V5M6 11l6-6 6 6"/>',
  down: '<path d="M12 5v14M6 13l6 6 6-6"/>',
  bell: '<path d="M6 16V11a6 6 0 0 1 12 0v5l2 2H4z"/><path d="M10 20a2 2 0 0 0 4 0"/>',
  mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
  phone: '<path d="M5 3h4l2 5-2.5 1.5a11 11 0 0 0 6 6L16 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 5a2 2 0 0 1 2-2z"/>',
  globe: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
  copy: '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V4H4v12h4"/>',
};
export function icon(name, cls = '') {
  return `<svg class="ico ${cls}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${P[name] || ''}</svg>`;
}
