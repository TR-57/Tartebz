import { api, esc, d, pill, field, toggle, formData, showErrors, busy, toast, modal, fmtDate, state } from '../core.js';

const ROLE_HELP = {
  owner: 'Everything, including settings, payments, integrations, staff accounts and the activity log.',
  manager: 'Staff, plus products, stock, shipping rates, refunds, reports, marketing reports, content, emails and promotions.',
  staff: 'Orders (status, notes, tracking), enquiries, customers (view), abandoned carts and viewing the catalogue.',
  order_manager: 'Only Prepare orders: checks paid orders and reports missing items.',
  order_assistant: 'Only Item requests: answers the requests sent to them.',
};

export default {
  key: 'staff', label: 'Staff', icon: 'users', role: 'owner',
  async view(main, route, alive) {
    const r = await api('staff');
    if (!alive()) return;
    const roles = r.roles;
    main.innerHTML = `
      <div class="phead"><div><h1>Staff</h1><p class="sub">Who can sign in to the admin and what each person can do.</p></div>
        <div class="actions"><button class="btn btn-primary" id="add">+ Add a person</button></div></div>
      <div class="table-wrap"><table class="t cards"><thead><tr><th>Name</th><th>Role</th><th>Status</th><th>Last sign in</th><th></th></tr></thead><tbody>
      ${r.staff.map((s) => `<tr>
        <td data-label="Name"><b>${d(s.name)}</b><div class="muted small">${d(s.email)}</div></td>
        <td data-label="Role">${esc(roles[s.role] || s.role)}${s.role === 'owner' ? `<div class="muted small">${s.orderEmails ? '✉ Order emails on' : 'Order emails off'}</div>` : ''}</td>
        <td data-label="Status">${s.active ? pill('Active', 'active') : pill('Disabled', 'disabled')}</td>
        <td data-label="Last sign in"><span data-noi18n>${esc(fmtDate(s.lastLoginAt))}</span></td>
        <td class="num"><button class="btn btn-sm" data-edit="${s.id}">Edit</button></td></tr>`).join('')}
      </tbody></table></div>
      <div class="card" style="margin-top:16px"><h2>Roles</h2>${Object.entries(ROLE_HELP).map(([k, v]) => `<p><b>${esc(roles[k])}</b> – ${esc(v)}</p>`).join('')}</div>`;

    const open = (s) => {
      const isNew = !s;
      s = s || { name: '', email: '', role: 'staff', active: true, orderEmails: true };
      const self = !isNew && s.id === state.admin.id;
      modal({
        title: isNew ? 'Add a person' : 'Edit ' + s.name,
        body: `<form id="sf" class="form-grid" novalidate>
          ${field('name', 'Name', s.name, { autocomplete: 'off' })}
          ${field('email', 'Email', s.email, { type: 'email', dir: 'ltr', autocomplete: 'off' })}
          ${field('role', 'Role', s.role, { full: true, options: Object.entries(roles).map(([k, v]) => [k, k === 'order_manager' ? 'Order manager (prepares paid orders only)' : k === 'order_assistant' ? 'Order assistant (item requests only)' : v]) })}
          ${field('password', isNew ? 'Password' : 'New password (leave empty to keep)', '', { type: 'password', dir: 'ltr', autocomplete: 'new-password', hint: 'At least 10 characters. Give it to them in person.', full: true })}
          ${self ? '' : toggle('active', 'Can sign in', s.active)}
          ${toggle('orderEmails', 'Order emails (owners)', s.orderEmails, 'An owner gets an email for every new order and every order update.')}
        </form>`,
        foot: '<button class="btn" data-close>Cancel</button><button class="btn btn-primary" id="save">Save</button>',
        onOpen: (el, close) => {
          el.querySelector('#save').addEventListener('click', async (e) => {
            const form = el.querySelector('#sf');
            const body = formData(form);
            if (self) body.active = true;
            try {
              await busy(e.currentTarget, () => api(isNew ? 'staff' : 'staff/' + s.id, { method: isNew ? 'POST' : 'PUT', body }));
              close(); toast('Saved'); this.view(main, route, alive);
            } catch (err) { showErrors(form, err); }
          });
        },
      });
    };
    main.querySelector('#add').addEventListener('click', () => open(null));
    main.querySelectorAll('[data-edit]').forEach((b) => b.addEventListener('click', () => open(r.staff.find((x) => x.id === +b.dataset.edit))));
  },
};
