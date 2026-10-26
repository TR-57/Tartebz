import { api, esc, toast, busy, showErrors, clearErrors, nf, icon } from '../core.js';

/* Currencies customers can see and pay in. Prices stay in BHD; orders keep the rate they used,
   so sales and reports are always added up in BHD. */
export default {
  key: 'currencies', label: 'Currencies', icon: 'coins', role: 'owner', group: 'settings',
  async view(main, route, alive) {
    const r = await api('currencies');
    if (!alive()) return;
    let list = r.list.map((c) => ({ ...c, countries: (c.countries || []).join(', ') }));
    const base = r.base;

    const draw = () => {
      main.innerHTML = `
        <div class="phead"><div><h1>Currencies</h1>
          <p class="sub">${esc(`Every price is kept in ${base}. A customer in another country sees and pays the ${base} price × the rate below. Orders keep the rate they were paid at, and the admin, sales and reports always add everything up in ${base}.`)}</p></div></div>
        <form id="cf" novalidate>
        <div class="table-wrap"><table class="t cards"><thead><tr><th>Currency</th><th>${esc(`For 1 ${base}, the customer pays`)}</th><th>Decimals</th><th>Symbol (English)</th><th>Symbol (Arabic)</th><th>Countries</th><th>On</th><th>Example</th><th></th></tr></thead><tbody>
        ${list.map((c, i) => {
          const isBase = c.code === base;
          const ex = Math.round(3 * c.rate * 10 ** c.decimals) / 10 ** c.decimals;
          return `<tr data-i="${i}">
          <td data-label="Currency" data-field="list.${i}.code"><input class="input" data-k="code" value="${esc(c.code)}" maxlength="3" dir="ltr" ${isBase ? 'readonly' : ''} aria-label="Code" style="width:80px"></td>
          <td data-label="Rate" data-field="list.${i}.rate"><input class="input" type="number" step="0.0001" min="0" data-k="rate" value="${c.rate}" dir="ltr" ${isBase ? 'readonly' : ''} aria-label="Rate" style="width:110px"></td>
          <td data-label="Decimals"><select class="input" data-k="decimals" aria-label="Decimals">${[0, 1, 2, 3].map((n) => `<option${+c.decimals === n ? ' selected' : ''}>${n}</option>`).join('')}</select></td>
          <td data-label="Symbol (English)"><input class="input" data-k="symbol" value="${esc(c.symbol)}" dir="ltr" maxlength="10" style="width:80px" aria-label="Symbol"></td>
          <td data-label="Symbol (Arabic)"><input class="input" data-k="symbol_ar" value="${esc(c.symbol_ar)}" dir="rtl" maxlength="10" style="width:80px" aria-label="Arabic symbol"></td>
          <td data-label="Countries" data-field="list.${i}.countries"><input class="input" data-k="countries" value="${esc(c.countries)}" dir="ltr" placeholder="SA, AE" aria-label="Countries" style="width:110px"></td>
          <td data-label="On"><input type="checkbox" data-k="enabled" ${c.enabled ? 'checked' : ''} ${isBase ? 'disabled' : ''} aria-label="On"></td>
          <td data-label="Example" class="muted small"><span data-noi18n>3.000 ${esc(base)} = ${esc(c.code)} ${nf(ex, c.decimals)}</span></td>
          <td>${isBase ? '' : `<button type="button" class="iconbtn" data-rm="${i}" aria-label="Remove">${icon('close')}</button>`}</td></tr>`;
        }).join('')}
        </tbody></table></div>
        <p><button type="button" class="btn btn-sm" id="add">${icon('plus')} Add a currency</button></p>
        <div class="box info small">${esc(`Countries are 2-letter codes (SA, AE, QA, KW, OM). A country that is in no list pays in ${base}. Changing a rate changes prices on the website at once; orders already placed keep their rate.`)}</div>
        <div class="savebar"><button type="submit" class="btn btn-primary" id="save">Save</button></div>
        </form>`;
      const form = main.querySelector('#cf');
      form.addEventListener('input', (e) => {
        const tr = e.target.closest('[data-i]');
        if (!tr) return;
        const k = e.target.dataset.k;
        list[+tr.dataset.i][k] = e.target.type === 'checkbox' ? e.target.checked : e.target.value;
      });
      form.addEventListener('change', (e) => { if (e.target.dataset.k === 'rate' || e.target.dataset.k === 'decimals') draw(); });
      form.querySelector('#add').addEventListener('click', () => { list.push({ code: '', rate: 1, decimals: 2, symbol: '', symbol_ar: '', countries: '', enabled: true }); draw(); });
      form.querySelectorAll('[data-rm]').forEach((b) => b.addEventListener('click', () => { list.splice(+b.dataset.rm, 1); draw(); }));
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        clearErrors(form);
        const body = { list: list.map((c) => ({ ...c, rate: +c.rate, decimals: +c.decimals, countries: String(c.countries).split(/[\s,]+/).filter(Boolean) })) };
        try {
          const res = await busy(main.querySelector('#save'), () => api('currencies', { method: 'PUT', body }));
          list = res.list.map((c) => ({ ...c, countries: c.countries.join(', ') }));
          draw(); toast('Saved');
        } catch (err) { showErrors(form, err); }
      });
    };
    draw();
  },
};
