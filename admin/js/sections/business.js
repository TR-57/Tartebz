import { api, upload, esc, field, formData, showErrors, clearErrors, busy, toast, toastErr, icon } from '../core.js';

/* Owners: the shop's own details. They show on the website (header, footer, contact page), in every email and on invoices. */
export default {
  key: 'business', label: 'Business details', icon: 'home', role: 'owner', group: 'settings',
  async view(main, route, alive) {
    const r = await api('business');
    if (!alive()) return;
    const b = r.business;
    const br = r.brand;
    main.innerHTML = `
      <div class="phead"><div><h1>Business details</h1><p class="sub">Shown on the website, in every email and on invoices. Changes show on the website within a minute.</p></div></div>
      <form id="bf" novalidate>
      <div class="grid-2">
        <div class="card"><h2>${icon('store')} Shop</h2>
          <div class="form-grid">
            ${field('name', 'Shop name (English)', br.name)}
            ${field('name_ar', 'Shop name (Arabic)', br.name_ar, { dir: 'rtl' })}
          </div>
          <div class="field full" data-field="logo"><span class="field-label">Logo</span>
            <div class="toolbar"><span id="logoPrev" class="logo-prev">${br.logoUrl ? `<img src="${esc(br.logoUrl)}" alt="" style="width:64px;height:64px;object-fit:contain;border-radius:12px;border:1px solid var(--line);background:#fff">` : '<span class="muted small">No logo – the drawn logo is used.</span>'}</span>
              <label class="btn btn-sm">${icon('upload')} <span>Upload a new logo</span><input type="file" id="logoFile" accept="image/png,image/jpeg,image/webp,image/svg+xml" hidden></label></div>
            <input type="hidden" name="logo" value="${esc(br.logo)}"><span class="hint">A square PNG with a clear background works best.</span></div>
        </div>
        <div class="card"><h2>${icon('phone')} Contact</h2>
          <div class="form-grid">
            ${field('phone', 'Phone', b.phone, { dir: 'ltr', type: 'tel', hint: 'With the country code.' })}
            ${field('whatsapp', 'WhatsApp (optional)', b.whatsapp, { dir: 'ltr', type: 'tel', hint: 'Empty = the phone number.' })}
            ${field('email', 'Email', b.email, { dir: 'ltr', type: 'email', hint: 'Shown to customers. Emails are sent from Settings › Email sending.' })}
          </div></div>
      </div>
      <div class="grid-2">
        <div class="card"><h2>${icon('tag')} Legal (invoices and emails)</h2>
          <div class="form-grid">
            ${field('legalName', 'Legal name', b.legalName, { full: true, hint: 'As on the commercial registration.' })}
            ${field('cr', 'CR number', b.cr, { dir: 'ltr' })}
            ${field('vat', 'VAT number (optional)', b.vat, { dir: 'ltr', hint: 'Leave empty if the shop is not VAT registered.' })}
          </div></div>
        <div class="card"><h2>${icon('globe')} Address</h2>
          <div class="form-grid">
            ${field('addressLine1', 'Address (English)', b.addressLine1, { full: true })}
            ${field('addressLine1_ar', 'Address (Arabic)', b.addressLine1_ar, { full: true, dir: 'rtl' })}
            ${field('addressLine2', 'Address line 2 (optional)', b.addressLine2)}
            ${field('area', 'Area / city', b.area)}
            ${field('mapsUrl', 'Google Maps link (optional)', br.mapsUrl, { dir: 'ltr', full: true })}
          </div></div>
      </div>
      <div class="grid-2">
        <div class="card"><h2>${icon('star')} Social</h2>
          <div class="form-grid">
            ${field('instagram', 'Instagram link', br.instagram, { dir: 'ltr', full: true, placeholder: 'https://www.instagram.com/…' })}
            ${field('tiktok', 'TikTok link', br.tiktok, { dir: 'ltr', full: true, placeholder: 'https://www.tiktok.com/@…' })}
          </div></div>
        <div class="card"><h2>${icon('log')} Time zone</h2>
          <div class="field" data-field="timezone"><label for="tz">Time zone</label>
            <select class="input" id="tz" name="timezone" dir="ltr" data-noi18n>${r.timezones.map((z) => `<option value="${esc(z)}"${z === b.timezone ? ' selected' : ''}>${esc(z)}</option>`).join('')}</select>
            <span class="hint">Dates and times in emails and on invoices.</span></div></div>
      </div>
      <div class="savebar"><button type="submit" class="btn btn-primary" id="save">Save</button></div>
      </form>`;

    const form = main.querySelector('#bf');
    main.querySelector('#logoFile').addEventListener('change', async (e) => {
      const f = e.target.files[0];
      if (!f) return;
      try {
        const res = await upload('uploads', f, { query: { kind: 'brand' } });
        form.logo.value = res.media.path;
        main.querySelector('#logoPrev').innerHTML = `<img src="${esc(res.media.thumb || res.url)}" alt="" style="width:64px;height:64px;object-fit:contain;border-radius:12px;border:1px solid var(--line);background:#fff">`;
        toast('Logo uploaded – press Save to use it');
      } catch (err) { toastErr(err); }
    });
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearErrors(form);
      try { await busy(main.querySelector('#save'), () => api('business', { method: 'PUT', body: formData(form) })); toast('Saved'); }
      catch (err) { showErrors(form, err); toastErr(err); }
    });
  },
};
