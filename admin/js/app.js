/* Admin app: sign-in screens, the shell (sidebar + app bar), the router and the section registry. */
import { state, api, esc, icon, can, canAny, field, formData, showErrors, clearErrors, busy, toast, parseHash, isAr } from './core.js';
import { sections } from './sections/index.js';

const app = document.getElementById('app');
const STORE = 'Tartebz';

/* ---------------- boot ---------------- */
async function boot() {
  const reset = new URLSearchParams(location.search).get('reset');
  if (reset) return renderReset(reset);
  try {
    const me = await api('me');
    state.admin = me.admin;
  } catch (e) { state.admin = null; }
  if (!state.admin) return renderLogin();
  renderShell();
}
document.addEventListener('admin:signedout', () => { state.admin = null; document.body.removeAttribute('data-signed-in'); renderLogin('Your session ended. Please sign in again.'); });

function langToggle() {
  return `<button type="button" class="langbtn" data-lang title="${isAr() ? 'English' : 'العربية'}">${isAr() ? 'EN' : 'ع'}</button>`;
}
document.addEventListener('click', (e) => {
  if (e.target.closest('[data-lang]')) window.AdminI18n.set(isAr() ? 'en' : 'ar');
});

/* ---------------- sign in ---------------- */
function authShell(inner) {
  document.body.removeAttribute('data-signed-in');
  app.innerHTML = `<div class="auth-wrap"><div class="auth-box">
    <div class="auth-brand"><span class="logo" aria-hidden="true">ت</span><span><b data-noi18n>${STORE}</b><span class="muted small">Admin</span></span>
      <span style="margin-inline-start:auto">${langToggle()}</span></div>${inner}</div></div>`;
}

function renderLogin(notice = '') {
  authShell(`<h1>Sign in</h1>${notice ? `<div class="box warn">${esc(notice)}</div>` : ''}
    <form id="login" novalidate>
      ${field('email', 'Email', '', { type: 'email', autocomplete: 'username', dir: 'ltr', required: true })}
      ${field('password', 'Password', '', { type: 'password', autocomplete: 'current-password', dir: 'ltr', required: true })}
      <button class="btn btn-primary" type="submit">Sign in</button>
    </form>
    <div class="auth-foot"><button class="linkbtn" type="button" id="forgot">Forgot your password?</button><a href="/" class="small">Go to the store</a></div>`);
  const form = document.getElementById('login');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    clearErrors(form);
    const btn = form.querySelector('button[type=submit]');
    try {
      const r = await busy(btn, () => api('login', { method: 'POST', body: formData(form) }));
      state.admin = r.admin;
      if (!location.hash || location.hash === '#') location.hash = '#/';
      renderShell();
    } catch (err) { showErrors(form, err); }
  });
  document.getElementById('forgot').addEventListener('click', () => renderForgot(form.email.value));
}

function renderForgot(email = '') {
  authShell(`<h1>Forgot your password?</h1><p class="muted">Enter the email of your staff account. We send a link to set a new password; it works for one hour.</p>
    <form id="forgotf" novalidate>${field('email', 'Email', email, { type: 'email', autocomplete: 'username', dir: 'ltr' })}
    <button class="btn btn-primary" type="submit">Send the link</button></form>
    <div class="auth-foot"><button class="linkbtn" type="button" id="back">Back to sign in</button></div>`);
  const form = document.getElementById('forgotf');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    clearErrors(form);
    try {
      const r = await busy(form.querySelector('button'), () => api('forgot', { method: 'POST', body: formData(form) }));
      form.outerHTML = `<div class="box ok">${esc(r.message)}</div>`;
    } catch (err) { showErrors(form, err); }
  });
  document.getElementById('back').addEventListener('click', () => renderLogin());
}

function renderReset(token) {
  authShell(`<h1>Set a new password</h1><p class="muted">At least 10 characters. Other devices are signed out.</p>
    <form id="resetf" novalidate>
      ${field('password', 'New password', '', { type: 'password', autocomplete: 'new-password', dir: 'ltr' })}
      ${field('password2', 'Type it again', '', { type: 'password', autocomplete: 'new-password', dir: 'ltr' })}
      <button class="btn btn-primary" type="submit">Save and sign in</button></form>
    <div class="auth-foot"><a href="./" class="small">Back to sign in</a></div>`);
  const form = document.getElementById('resetf');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    clearErrors(form);
    const v = formData(form);
    if (v.password !== v.password2) return showErrors(form, { fields: { password2: 'The two passwords are not the same.' } });
    try {
      const r = await busy(form.querySelector('button'), () => api('reset', { method: 'POST', body: { token, password: v.password } }));
      state.admin = r.admin;
      history.replaceState(null, '', location.pathname + '#/');
      renderShell();
      toast('Password saved – you are signed in');
    } catch (err) { showErrors(form, err); }
  });
}

/* ---------------- shell ---------------- */
function visibleSections() {
  return sections.filter((s) => !s.hidden && (s.roles ? canAny(s.roles) : can(s.role || 'staff')));
}
function homeKey() {
  const r = state.admin.role;
  if (r === 'order_manager' || r === 'order_assistant') {
    const home = sections.find((s) => s.homeFor && s.homeFor.includes(r));
    return home ? home.key : 'account';
  }
  return '';
}

function renderShell() {
  document.body.setAttribute('data-signed-in', '');
  const a = state.admin;
  const groups = [['store', 'Store'], ['marketing', 'Marketing']];
  const vis = visibleSections();
  const nav = groups.map(([g, label]) => {
    const items = vis.filter((s) => (s.group || 'store') === g && !s.noNav);
    if (!items.length) return '';
    return `<div class="side-group upper">${esc(label)}</div>` + items.map((s) =>
      `<a class="nav-link" href="#/${s.key}" data-key="${s.key}">${icon(s.icon || 'box')}<span>${esc((s.labelFor && s.labelFor[a.role]) || s.label)}</span><span class="nav-badge" data-badge="${s.key}" hidden></span></a>`).join('');
  }).join('');

  app.innerHTML = `<a class="skip" href="#main">Skip to content</a><div class="shell">
    <aside class="side" id="side" aria-label="Main menu">
      <a class="side-brand" href="#/${homeKey()}"><span class="logo" aria-hidden="true">ت</span><span data-noi18n>${STORE}</span></a>
      ${nav}
      <div class="side-foot">
        <a class="nav-link" href="/" target="_blank" rel="noopener">${icon('store')}<span>Open the store</span></a>
        <button class="nav-link" style="background:none;border:0;width:100%;text-align:start" id="signout">${icon('out', 'flip')}<span>Sign out</span></button>
      </div>
    </aside>
    <div class="scrim" id="scrim"></div>
    <div class="main-col">
      <header class="appbar">
        <button class="iconbtn menu-btn" id="menubtn" aria-label="Menu" aria-controls="side">${icon('menu')}</button>
        ${can('staff') ? `<form class="search" id="gsearch" role="search">${icon('search')}<input type="search" name="q" placeholder="Search orders by number, name, email or phone" aria-label="Search orders by number, name, email or phone"></form>` : '<span class="spacer"></span>'}
        ${langToggle()}
        ${can('staff') ? `<a class="iconbtn" href="#/enquiries?status=new" title="New enquiries" aria-label="New enquiries">${icon('bell')}<span class="dot" data-badge="bell" hidden></span></a>` : ''}
        <a class="avatar" href="#/account" title="${esc(a.name)}" data-noi18n aria-label="${esc(window.AdminI18n.t('Your account'))}"><span data-noi18n>${esc((a.name || '?').trim().charAt(0).toUpperCase())}</span></a>
      </header>
      <main class="view" id="main" tabindex="-1"></main>
    </div></div>`;

  document.getElementById('signout').addEventListener('click', async () => {
    try { await api('logout', { method: 'POST' }); } catch (e) { /* ignore */ }
    state.admin = null;
    location.hash = '';
    renderLogin();
  });
  document.getElementById('menubtn').addEventListener('click', () => document.body.classList.toggle('menu-open'));
  document.getElementById('scrim').addEventListener('click', () => document.body.classList.remove('menu-open'));
  const gs = document.getElementById('gsearch');
  if (gs) gs.addEventListener('submit', (e) => { e.preventDefault(); location.hash = '#/orders?q=' + encodeURIComponent(gs.q.value.trim()); });

  route();
  refreshBadges();
}

export async function refreshBadges() {
  if (!can('staff')) return;
  try {
    const d = await api('dashboard');
    setBadge('bell', d.counts.enquiries);
    setBadge('enquiries', d.counts.enquiries);
    setBadge('orders', d.counts.unhandled);
  } catch (e) { /* ignore */ }
}
function setBadge(key, n) {
  document.querySelectorAll(`[data-badge="${key}"]`).forEach((el) => { el.textContent = n > 99 ? '99+' : String(n || ''); el.hidden = !n; });
}

/* ---------------- router ---------------- */
let routeToken = 0;
async function route() {
  if (!state.admin) return;
  document.body.classList.remove('menu-open');
  const main = document.getElementById('main');
  if (!main) return;
  const p = parseHash();
  let key = p.key;
  if (key === 'mail') { location.hash = '#/emails/sent'; return; }
  const team = state.admin.role === 'order_manager' || state.admin.role === 'order_assistant';
  if (key === '' && team) key = homeKey();
  const sec = sections.find((s) => s.key === key);
  document.querySelectorAll('.nav-link[data-key]').forEach((l) => {
    const on = l.dataset.key === key;
    l.classList.toggle('active', on);
    if (on) l.setAttribute('aria-current', 'page'); else l.removeAttribute('aria-current');
  });
  const mine = ++routeToken;
  if (!sec) { main.innerHTML = '<div class="empty"><h1>No such page.</h1><p><a href="#/">Back to the dashboard</a></p></div>'; return; }
  const allowed = sec.roles ? canAny(sec.roles) : can(sec.role || 'staff');
  if (!allowed) {
    if (team) { location.hash = '#/' + homeKey(); return; }
    main.innerHTML = `<div class="empty"><h1>You cannot open this page.</h1><p>Your role (${esc(state.admin.role)}) cannot do this. Ask the store owner.</p></div>`;
    return;
  }
  main.innerHTML = '<div class="loading">Loading…</div>';
  try {
    await sec.view(main, p, () => mine === routeToken);
  } catch (e) {
    if (mine === routeToken) main.innerHTML = `<div class="box err">${esc(e.message || e)}</div>`;
  }
  if (mine === routeToken) { main.focus({ preventScroll: true }); window.scrollTo(0, 0); }
}
window.addEventListener('hashchange', route);

boot();
