import { api, esc, toggle, field, formData, busy, toast, showErrors, clearErrors, icon } from '../core.js';

/* Managers: the pop-up that gives a code for an email address (one code per address). */
export default {
  key: 'popup', label: 'Pop-up offer', icon: 'percent', role: 'manager', group: 'marketing',
  async view(main, route, alive) {
    const r = await api('popup');
    if (!alive()) return;
    const p = r.popup;
    main.innerHTML = `
      <div class="phead"><div><h1>Pop-up offer</h1><p class="sub">Visitors leave their email (and agree to get offers) and receive a personal code by email – one code per address.</p></div></div>
      <div class="kpis"><div class="kpi"><div class="k-label">Codes given</div><div class="k-val" data-noi18n>${r.given}</div></div><div class="kpi"><div class="k-label">Codes used</div><div class="k-val" data-noi18n>${r.used}</div></div><div class="kpi"><div class="k-label">Subscribers</div><div class="k-val" data-noi18n>${r.subscribers}</div></div></div>
      <form id="pf" novalidate>
      <div class="card">${toggle('enabled', 'Show the pop-up on the website', p.enabled)}
        <div class="form-grid">
          ${field('delaySeconds', 'Show after (seconds on the site)', p.delaySeconds, { type: 'number', min: 0, max: 120, dir: 'ltr' })}
          ${field('title_ar', 'Title (Arabic)', p.title_ar, { dir: 'rtl' })}${field('title', 'Title (English)', p.title, { dir: 'ltr' })}
          ${field('text_ar', 'Text (Arabic)', p.text_ar, { textarea: true, rows: 2, dir: 'rtl' })}${field('text', 'Text (English)', p.text, { textarea: true, rows: 2, dir: 'ltr' })}
        </div></div>
      <div class="card"><h2>The code</h2><div class="form-grid">
          ${field('coupon.type', 'Gives', p.coupon.type, { options: [['percent', 'A percentage off'], ['fixed', 'An amount off (BHD)'], ['free_shipping', 'Free delivery']] })}
          ${field('coupon.value', 'Value', p.coupon.value, { type: 'number', step: '0.001', dir: 'ltr' })}
          ${field('coupon.min', 'Minimum order (BHD, optional)', p.coupon.min ?? '', { type: 'number', step: '0.001', dir: 'ltr' })}
          ${field('coupon.days', 'Valid for (days)', p.coupon.days, { type: 'number', min: 1, dir: 'ltr' })}
        </div><p class="muted small">The email’s words: Emails › Pop-up code.</p></div>
      <div class="savebar"><a class="btn" href="#/emails/popup_code">${icon('edit')} Edit the email</a><button class="btn btn-primary" type="submit">Save</button></div></form>`;
    const form = main.querySelector('#pf');
    form.addEventListener('submit', async (e) => {
      e.preventDefault(); clearErrors(form);
      const v = formData(form);
      const body = { enabled: v.enabled, delaySeconds: v.delaySeconds, title: v.title, title_ar: v.title_ar, text: v.text, text_ar: v.text_ar,
        coupon: { type: v['coupon.type'], value: v['coupon.value'], min: v['coupon.min'], days: v['coupon.days'] } };
      try { await busy(form.querySelector('[type=submit]'), () => api('popup', { method: 'PUT', body })); toast('Saved'); } catch (err) { showErrors(form, err); }
    });
  },
};
