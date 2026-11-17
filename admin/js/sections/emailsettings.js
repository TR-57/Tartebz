import { api, esc, ltr, pill, field, formData, showErrors, clearErrors, busy, toast, toastErr, icon, state } from '../core.js';

/* Owners: how email leaves the website – SMTP (e.g. Hostinger), or the website by itself – and a test email. */
export default {
  key: 'email-settings', label: 'Email sending', icon: 'mail', role: 'owner', group: 'settings',
  async view(main, route, alive) {
    const r = await api('email-settings');
    if (!alive()) return;
    const m = r.mail;
    const modeText = {
      smtp: ['Sending with SMTP', 'green', 'Emails go through the mail server below.'],
      env: ['Sending with the SMTP in the server file (.env)', 'green', 'The fields below are empty, so the server file is used.'],
      website: ['Sending from the website itself (no SMTP)', 'amber', 'No SMTP is filled in, so the website sends by itself. It works on Hostinger, but SMTP is more reliable – fill it in below.'],
      test: ['Test mode (emails are not really sent)', 'hidden', 'This is a test installation.'],
    }[r.mode];
    main.innerHTML = `
      <div class="phead"><div><h1>Email sending</h1><p class="sub">Every email to customers (orders, invoices, shipping, reminders, offers) and to the team goes out from the address below.</p></div></div>
      <div class="card"><div class="card-head"><h2>${icon('mail')} Status</h2>${pill(modeText[0], modeText[1])}</div>
        <p class="muted small">${esc(modeText[2])}</p>
        <p><span>Emails come from</span> ${ltr(r.from.name + ' <' + r.from.address + '>')}</p></div>
      <form id="ef" novalidate>
      <div class="grid-2">
        <div class="card"><div class="card-head"><h2>SMTP (mail server)</h2><button type="button" class="btn btn-sm" id="hostinger">Fill in Hostinger’s settings</button></div>
          <div class="form-grid">
            ${field('host', 'Mail server (host)', m.host, { dir: 'ltr', placeholder: 'smtp.hostinger.com', hint: 'Leave empty to let the website send by itself.' })}
            ${field('port', 'Port', m.port, { type: 'number', dir: 'ltr', hint: '465 with SSL, or 587 with TLS.' })}
            ${field('scheme', 'Security', m.scheme, { options: [['ssl', 'SSL (port 465)'], ['tls', 'TLS (port 587)'], ['', 'None']] })}
            ${field('username', 'Username', m.username, { dir: 'ltr', autocomplete: 'off', placeholder: 'sales@tartebz.com', hint: 'The full mailbox address.' })}
            <div class="field" data-field="password"><label for="pw">Password</label><input id="pw" name="password" type="password" dir="ltr" autocomplete="new-password" placeholder="${esc(m.password.set ? '•••• saved – type to replace' : 'The mailbox password')}">
              ${m.password.set ? '<label class="mini-check"><input type="checkbox" name="clearPassword"> <span>Remove the saved password</span></label>' : ''}<span class="hint">Stored encrypted and never shown again.</span></div>
          </div></div>
        <div class="card"><h2>Sender</h2>
          <div class="form-grid">
            ${field('fromAddress', 'From address', m.fromAddress, { dir: 'ltr', hint: 'A mailbox on your own domain, e.g. sales@tartebz.com (it must exist in Hostinger).' })}
            ${field('fromName', 'From name', m.fromName, { hint: 'What customers see as the sender.' })}
            ${field('replyTo', 'Reply-to address (optional)', m.replyTo, { dir: 'ltr', hint: 'Empty = replies go to the From address.' })}
            ${field('notifyEmails', 'Store notification addresses', (m.notifyEmails || []).join(', '), { textarea: true, rows: 2, dir: 'ltr', full: true, hint: 'They get new orders, order updates and reviews waiting. Separate with commas.' })}
          </div></div>
      </div>
      <div class="savebar"><button type="submit" class="btn btn-primary" id="save">Save</button></div>
      </form>
      <div class="card"><h2>${icon('send')} Send a test email</h2>
        <div class="toolbar"><input class="input grow" id="to" dir="ltr" value="${esc(state.admin.email)}" aria-label="Send the test to"><button class="btn" id="test">${icon('send')} Send test email</button></div>
        <div id="testres"></div><p class="muted small">Save first if you changed anything above.</p></div>
      <div class="card"><h2>So emails land in the inbox (not spam)</h2>
        <p class="muted small">In Hostinger › Emails › your domain › DNS / “Email deliverability”, make sure these records exist for ${r.domain ? `<b data-noi18n>${esc(r.domain)}</b>` : '<b>your domain</b>'}. Hostinger usually adds them for you when the domain’s email is with them.</p>
        <ul class="ticks">
          <li><b>SPF</b> – a TXT record on the domain: <span class="mono" dir="ltr" data-noi18n>v=spf1 include:_spf.mail.hostinger.com ~all</span> (only one SPF record per domain – add Hostinger to it if one exists).</li>
          <li><b>DKIM</b> – the TXT records Hostinger shows (names like <span class="mono" dir="ltr" data-noi18n>hostingermail-a._domainkey</span>).</li>
          <li><b>DMARC</b> – a TXT record named <span class="mono" dir="ltr" data-noi18n>_dmarc</span>: <span class="mono" dir="ltr" data-noi18n>v=DMARC1; p=none; rua=mailto:${esc(r.from.address)}</span> (later you can make it stricter: p=quarantine).</li>
        </ul></div>`;
    const form = main.querySelector('#ef');
    main.querySelector('#hostinger').addEventListener('click', () => {
      form.host.value = 'smtp.hostinger.com'; form.port.value = 465; form.scheme.value = 'ssl';
      if (!form.username.value) form.username.value = form.fromAddress.value;
      form.password.focus(); toast('Filled in – now type the mailbox password and save.');
    });
    form.addEventListener('submit', async (e) => {
      e.preventDefault(); clearErrors(form);
      try { await busy(main.querySelector('#save'), () => api('email-settings', { method: 'PUT', body: formData(form) })); toast('Saved'); this.view(main, route, alive); } catch (err) { showErrors(form, err); toastErr(err); }
    });
    main.querySelector('#test').addEventListener('click', async (e) => {
      const out = main.querySelector('#testres');
      try {
        const res = await busy(e.currentTarget, () => api('email-settings/test', { method: 'POST', body: { to: main.querySelector('#to').value } }));
        out.innerHTML = `<div class="box ${res.sent ? 'info' : 'err'}">${esc(res.message)}</div>`;
      } catch (err) { out.innerHTML = `<div class="box err">${esc(err.message)}</div>`; }
    });
  },
};
