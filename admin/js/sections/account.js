import { api, esc, d, field, formData, showErrors, clearErrors, busy, toast, state } from '../core.js';

export default {
  key: 'account', label: 'My account', icon: 'user', roles: ['staff', 'order_manager', 'order_assistant'], noNav: true,
  async view(main) {
    const a = state.admin;
    const team = a.role === 'order_manager' || a.role === 'order_assistant';
    main.innerHTML = `
      <div class="phead"><div><h1>My account</h1><p class="sub">${d(a.name)} · ${d(a.email)} · <span>${esc(a.role.replace('_', ' '))}</span></p></div></div>
      ${team ? '<div class="box info">Your screens (Prepare orders / Item requests) appear here once the order system is switched on.</div>' : ''}
      <div class="card" style="max-width:560px"><h2>Change your password</h2>
        <form id="pw" class="form-grid" novalidate>
          ${field('current', 'Current password', '', { type: 'password', autocomplete: 'current-password', dir: 'ltr', full: true })}
          ${field('password', 'New password', '', { type: 'password', autocomplete: 'new-password', dir: 'ltr', hint: 'At least 10 characters.' })}
          ${field('password2', 'Type it again', '', { type: 'password', autocomplete: 'new-password', dir: 'ltr' })}
          <div class="full"><button class="btn btn-primary" type="submit">Change password</button></div>
        </form></div>`;
    const form = main.querySelector('#pw');
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearErrors(form);
      const v = formData(form);
      if (v.password !== v.password2) return showErrors(form, { fields: { password2: 'The two passwords are not the same.' } });
      try {
        const r = await busy(form.querySelector('button'), () => api('password', { method: 'POST', body: { current: v.current, password: v.password } }));
        form.reset(); toast(r.message);
      } catch (err) { showErrors(form, err); }
    });
  },
};
