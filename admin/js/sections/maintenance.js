import { api, esc, pill, toggle, busy, toast, toastErr, confirmBox, icon, fmtDate } from '../core.js';

/* Maintenance mode: customers see a "back soon" page; the team keeps working. Managers and owners. */
export default {
  key: 'maintenance', label: 'Maintenance', icon: 'tool', role: 'manager', group: 'settings',
  async view(main, route, alive) {
    const r = await api('maintenance');
    if (!alive()) return;
    const m = r.maintenance;
    main.innerHTML = `
      <div class="phead"><div><h1>Maintenance mode</h1><p class="sub">Close the shop for a short while – for example while you change prices or add products – without losing anything.</p></div></div>
      <form id="mf" novalidate>
      <div class="card maint-card${m.on ? ' is-on' : ''}">
        <div class="card-head"><h2>${icon('tool')} Status</h2>${m.on ? pill('ON – the shop is closed', 'red') : pill('Off – the shop is open', 'green')}</div>
        ${m.on && m.since ? `<p class="muted small"><span>Switched on</span> <span data-noi18n>${esc(fmtDate(m.since))}</span>${m.by ? ` · <span data-noi18n>${esc(m.by)}</span>` : ''}</p>` : ''}
        ${toggle('on', 'Maintenance mode', m.on, 'Customers see the “back soon” page. New orders pause until you switch it off.')}
      </div>
      <div class="grid-2">
        <div class="card"><h2>Message for customers</h2>
          <div class="field"><label for="mar">Arabic</label><textarea id="mar" name="messageAr" rows="3" dir="rtl" maxlength="500" placeholder="المتجر في صيانة سريعة. اطلبوا الحين على واتساب، أو ارجعوا لنا بعد شوي.">${esc(m.messageAr || '')}</textarea></div>
          <div class="field"><label for="men">English</label><textarea id="men" name="message" rows="3" dir="ltr" maxlength="500" placeholder="The shop is down for a quick update. Order on WhatsApp now, or come back in a little while.">${esc(m.message || '')}</textarea></div>
          <p class="muted small">Leave empty to use the text shown in grey. The page also has your WhatsApp and Instagram buttons.</p>
          <p><a class="btn btn-sm" href="${esc(r.preview)}" target="_blank" rel="noopener">${icon('eye')} Preview the page</a></p>
        </div>
        <div class="card"><h2>While it is on</h2>
          <ul class="ticks">
            <li>Every shop page shows the “back soon” page. Google is told it is temporary, so the shop keeps its place in search.</li>
            <li>New orders, stock reservations and back-in-stock requests pause.</li>
            <li>You and your team, signed in, still see and test the whole shop.</li>
            <li>The admin, the orders board, Tap payment updates and customers’ order pages keep working.</li>
            <li>Locked out of the admin? On the server: php artisan store:maintenance off</li>
          </ul>
        </div>
      </div>
      <div class="savebar"><button type="submit" class="btn btn-primary" id="save">Save</button></div>
      </form>`;
    const form = main.querySelector('#mf');
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const on = form.querySelector('[name=on]').checked;
      if (on && !m.on && !(await confirmBox('Close the shop? Customers will see the “back soon” page until you switch maintenance off.', { ok: 'Switch on', danger: true }))) return;
      try {
        await busy(main.querySelector('#save'), () => api('maintenance', { method: 'PUT', body: { on, message: form.message.value, messageAr: form.messageAr.value } }));
        toast(on ? 'Maintenance mode is on' : 'The shop is open');
        document.dispatchEvent(new CustomEvent('admin:maintenance', { detail: on }));
        this.view(main, route, alive);
      } catch (err) { toastErr(err); }
    });
  },
};
