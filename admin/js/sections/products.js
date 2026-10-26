import { api, esc, d, pill, field, toggle, formData, showErrors, clearErrors, busy, toast, toastErr, confirmBox, modal, money, nf, debounce, setQuery, go, can, icon, isAr } from '../core.js';
import { pickMedia } from './media.js';

const TABS = [['all', 'All'], ['active', 'On the website'], ['hidden', 'Hidden'], ['out', 'Out of stock'], ['low', 'Low stock'], ['missing', 'Missing information']];
const MISSING = {
  photo: 'No photo', arabic_name: 'No Arabic name', description: 'No English description',
  arabic_description: 'No Arabic description', category: 'No category', weight: 'No weight (delivery cost)',
};
const SOUR = [['', 'Not shown'], ['1', '1 – Gentle'], ['2', '2 – Mild'], ['3', '3 – Balanced'], ['4', '4 – Strong'], ['5', '5 – Electric ⚡']];
const BULK = [['show', 'Show on the website'], ['hide', 'Hide from the website'], ['best_seller', 'Mark as best seller'], ['not_best_seller', 'Remove best seller mark'], ['category', 'Move to category…'], ['delete', 'Delete']];

const pname = (p) => (isAr() && p.nameAr ? p.nameAr : p.name);
const catLabel = (c) => '— '.repeat(c.depth) + (isAr() && c.nameAr ? c.nameAr : c.name);

function stockCell(p, edit) {
  if (p.type === 'variable') return `<span class="muted small">By variation</span> ${p.available ? pill('In stock', 'green') : pill('Out of stock', 'red')}`;
  if (p.manageStock) {
    return edit
      ? `<input class="input qty-in${p.stockQty <= 0 ? ' neg' : ''}" type="number" step="1" value="${p.stockQty}" data-qty="${p.id}" aria-label="Stock" dir="ltr">`
      : `<b data-noi18n>${nf(p.stockQty)}</b>`;
  }
  return p.inStock ? pill('In stock', 'green') : pill('Out of stock', 'red');
}

export default {
  key: 'products', label: 'Products', icon: 'box', role: 'staff', group: 'catalogue',
  async view(main, route, alive) {
    if (route.sub) return editor(main, route, alive);
    const q = route.query;
    const tab = TABS.some((t) => t[0] === q.tab) ? q.tab : 'all';
    const page = Math.max(1, +q.page || 1);
    const r = await api('products', { query: { tab, q: q.q || '', category: q.category || '', page } });
    if (!alive()) return;
    const edit = can('manager');
    const pages = Math.max(1, Math.ceil(r.total / r.per));
    const catById = Object.fromEntries(r.categories.map((c) => [c.id, isAr() && c.nameAr ? c.nameAr : c.name]));

    main.innerHTML = `
      <div class="phead"><div><h1>Products</h1><p class="sub">Prices are in BHD. Customers in other countries see them converted with the rates in Currencies.</p></div>
        <div class="actions">${edit ? `<a class="btn btn-primary" href="#/products/new">${icon('plus')} Add a product</a>` : ''}</div></div>
      <div class="tabs" role="tablist">${TABS.map(([k, l]) => `<a href="#/products?tab=${k}" role="tab" aria-selected="${tab === k}">${esc(l)} <span class="count-pill${(k === 'missing' || k === 'low' || k === 'out') && r.counts[k] ? ' red' : ''}" data-noi18n>${nf(r.counts[k])}</span></a>`).join('')}</div>
      <div class="toolbar">
        <input class="input grow" type="search" id="pq" value="${esc(q.q || '')}" placeholder="Search by name, flavour, SKU or barcode" aria-label="Search products">
        <select class="input" id="pc" aria-label="Category"><option value="">All categories</option>${r.categories.map((c) => `<option value="${c.id}"${+q.category === c.id ? ' selected' : ''}>${esc(catLabel(c))}</option>`).join('')}</select>
      </div>
      ${edit ? `<div class="bulkbar" id="bulk" hidden><span id="bulk-n"></span>
        <select class="input" id="bulk-a" aria-label="Action"><option value="">Choose an action…</option>${BULK.map(([k, l]) => `<option value="${k}">${esc(l)}</option>`).join('')}</select>
        <select class="input" id="bulk-c" aria-label="Category" hidden>${r.categories.map((c) => `<option value="${c.id}">${esc(catLabel(c))}</option>`).join('')}</select>
        <button class="btn btn-sm btn-primary" id="bulk-go">Apply</button></div>` : ''}
      <div class="table-wrap"><table class="t cards"><thead><tr>
        ${edit ? '<th class="chk"><input type="checkbox" id="all" aria-label="Select all"></th>' : ''}
        <th></th><th>Product</th><th>Category</th><th class="num">Price</th><th>Stock</th><th>Status</th></tr></thead><tbody>
      ${r.products.length ? r.products.map((p) => `<tr class="rowlink" data-href="#/products/${p.id}">
        ${edit ? `<td class="chk"><input type="checkbox" data-sel="${p.id}" aria-label="Select"></td>` : ''}
        <td class="thumb">${p.thumb ? `<img src="${esc(p.thumb)}" alt="" loading="lazy">` : `<span class="noimg">${icon('image')}</span>`}</td>
        <td data-label="Product"><a href="#/products/${p.id}"><b>${d(pname(p))}</b></a>
          <div class="muted small"><span data-noi18n>#${p.id}${p.flavor ? ' · ' + esc(isAr() && p.flavorAr ? p.flavorAr : p.flavor) : ''}</span>${p.bestSeller ? ' · ★ <span>Best seller</span>' : ''}</div>
          ${p.missing.length ? `<div class="miss">${p.missing.map((m) => pill(MISSING[m] || m, 'amber')).join(' ')}</div>` : ''}</td>
        <td data-label="Category">${p.categoryId && catById[p.categoryId] ? d(catById[p.categoryId]) : pill('No category', 'red')}</td>
        <td data-label="Price" class="num"><span data-noi18n>${esc(money(p.price))}</span>${p.regularPrice > p.price ? `<div class="muted small"><s data-noi18n>${esc(money(p.regularPrice))}</s></div>` : ''}</td>
        <td data-label="Stock">${stockCell(p, edit)}</td>
        <td data-label="Status">${p.status === 'active' ? pill('On the website', 'green') : pill('Hidden', 'hidden')}</td></tr>`).join('')
        : `<tr><td colspan="7"><p class="empty">${q.q ? 'No product matches your search.' : 'No products here.'}</p></td></tr>`}
      </tbody></table></div>
      ${pages > 1 ? `<div class="pager"><span class="muted" data-noi18n>${nf(r.total)}</span>
        <span>${page > 1 ? `<button class="btn btn-sm" data-page="${page - 1}">Previous</button>` : ''} <span data-noi18n>${page} / ${pages}</span> ${page < pages ? `<button class="btn btn-sm" data-page="${page + 1}">Next</button>` : ''}</span></div>` : ''}`;

    const reload = (obj = {}) => { const nq = { tab, q: q.q || '', category: q.category || '', page, ...obj }; setQuery(nq); this.view(main, { ...route, query: nq }, alive); };
    main.querySelectorAll('[data-page]').forEach((b) => b.addEventListener('click', () => reload({ page: +b.dataset.page })));
    main.querySelector('#pq').addEventListener('input', debounce((e) => reload({ q: e.target.value.trim(), page: 1 }), 400));
    main.querySelector('#pc').addEventListener('change', (e) => reload({ category: e.target.value, page: 1 }));
    main.querySelectorAll('tr.rowlink').forEach((tr) => tr.addEventListener('click', (e) => {
      if (e.target.closest('input, a, button, select, label')) return;
      go(tr.dataset.href);
    }));

    if (!edit) return;
    // Quick stock: saves when the field loses focus or Enter is pressed.
    main.querySelectorAll('[data-qty]').forEach((inp) => {
      inp.dataset.orig = inp.value;
      const save = async () => {
        if (inp.value === inp.dataset.orig) return;
        try {
          await api(`products/${inp.dataset.qty}/stock`, { method: 'POST', body: { stock_qty: inp.value } });
          inp.dataset.orig = inp.value; inp.classList.toggle('neg', +inp.value <= 0);
          toast('Stock saved');
        } catch (e) { toastErr(e); inp.value = inp.dataset.orig; }
      };
      inp.addEventListener('blur', save);
      inp.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); inp.blur(); } });
    });
    const bar = main.querySelector('#bulk');
    const picked = () => [...main.querySelectorAll('[data-sel]:checked')].map((c) => +c.dataset.sel);
    const sync = () => { const n = picked().length; bar.hidden = !n; main.querySelector('#bulk-n').textContent = n === 1 ? '1 product selected' : `${n} products selected`; };
    main.querySelectorAll('[data-sel]').forEach((c) => c.addEventListener('change', sync));
    const all = main.querySelector('#all');
    if (all) all.addEventListener('change', () => { main.querySelectorAll('[data-sel]').forEach((c) => { c.checked = all.checked; }); sync(); });
    main.querySelector('#bulk-a').addEventListener('change', (e) => { main.querySelector('#bulk-c').hidden = e.target.value !== 'category'; });
    main.querySelector('#bulk-go').addEventListener('click', async (e) => {
      const action = main.querySelector('#bulk-a').value;
      if (!action) { toast('Choose an action first', 'err'); return; }
      const ids = picked();
      if (action === 'delete' && !(await confirmBox(`Delete ${ids.length} products? Products that are in past orders are kept – hide those instead.`, { ok: 'Delete', danger: true }))) return;
      try {
        const res = await busy(e.currentTarget, () => api('products/bulk', { method: 'POST', body: { ids, action, category_id: main.querySelector('#bulk-c').value } }));
        toast(res.skipped.length ? `${res.done} done. Skipped: ${res.skipped.join(', ')}` : `${res.done} done`, res.skipped.length ? 'err' : '');
        reload();
      } catch (err) { toastErr(err); }
    });
  },
};

/* ------------------------------------------------------------------ editor */

async function editor(main, route, alive) {
  const isNew = route.sub === 'new';
  const edit = can('manager');
  if (isNew && !edit) { main.innerHTML = '<div class="empty"><h1>You cannot add products.</h1></div>'; return; }
  const r = isNew ? await api('categories') : await api('products/' + route.sub);
  if (!alive()) return;
  const cats = r.categories;
  const p = isNew ? {
    type: 'simple', name: '', nameAr: '', flavor: '', flavorAr: '', price: '', regularPrice: '', status: 'hidden', categoryId: cats[0] ? cats[0].id : '',
    images: [], description: '', descriptionAr: '', manageStock: true, stockQty: 0, inStock: true, weightGrams: 0, ownWeight: true,
    sku: '', barcode: '', sourLevel: '', bestSeller: false, isNew: true, seoTitle: '', seoTitleAr: '', seoDesc: '', seoDescAr: '', variations: [], missing: [],
  } : r.product;
  let images = p.images.map((i) => i.url);
  let variations = (p.variations || []).map((v) => ({ ...v }));
  const dis = edit ? {} : { disabled: true };

  main.innerHTML = `
    <div class="crumbs"><a href="#/products">Products</a> › <span data-noi18n>${esc(isNew ? '' : '#' + p.id)}</span></div>
    <div class="phead"><div><h1>${isNew ? 'Add a product' : d(pname(p))}</h1>
      ${!isNew ? `<p class="sub">${p.status === 'active' ? pill('On the website', 'green') : pill('Hidden', 'hidden')} ${p.available ? '' : pill('Out of stock', 'red')}</p>` : ''}</div>
      <div class="actions">${!isNew && p.status === 'active' ? `<a class="btn" href="${esc(p.storeUrl)}" target="_blank" rel="noopener">${icon('eye')} View on the website</a>` : ''}</div></div>
    ${p.missing && p.missing.length ? `<div class="box warn"><b>Still missing:</b> ${p.missing.map((m) => esc(MISSING[m] || m)).join(' · ')}</div>` : ''}
    <form id="pf" novalidate>
    <div class="ed-grid">
      <div class="ed-main">
        <div class="card"><h2>Name and flavour</h2><div class="form-grid">
          ${field('name', 'Name (English)', p.name, { ...dis })}
          ${field('name_ar', 'Name (Arabic)', p.nameAr, { dir: 'rtl', ...dis })}
          ${field('flavor', 'Flavour (English)', p.flavor, { placeholder: 'e.g. Mango', ...dis })}
          ${field('flavor_ar', 'Flavour (Arabic)', p.flavorAr, { dir: 'rtl', ...dis })}
        </div></div>
        <div class="card" data-field="images"><div class="card-head"><div><h2>Photos</h2><p class="muted small">The first photo is the main one. Originals are kept; the website shows resized copies.</p></div>
          ${edit ? `<button type="button" class="btn" id="addimg">${icon('plus')} Add photos</button>` : ''}</div>
          <div class="img-list" id="imgs"></div></div>
        <div class="card"><h2>Description</h2><div class="form-grid">
          ${field('description', 'Description (English)', p.description, { textarea: true, rows: 6, full: true, ...dis })}
          ${field('description_ar', 'Description (Arabic)', p.descriptionAr, { textarea: true, rows: 6, full: true, dir: 'rtl', ...dis })}
        </div><p class="muted small">Plain text. Leave an empty line between paragraphs.</p></div>
        <div class="card"><div class="card-head"><h2>Options</h2>
          ${field('type', 'Kind of product', p.type, { options: [['simple', 'One version'], ['variable', 'Several versions (sizes, colours…)']], ...dis })}</div>
          <div id="vars"></div></div>
        <details class="card"><summary><h2 style="display:inline">Search engines (SEO)</h2> <span class="muted small">optional</span></summary><div class="form-grid" style="margin-top:12px">
          ${field('seo_title', 'Page title (English)', p.seoTitle, { hint: 'Leave empty to use the product name.', ...dis })}
          ${field('seo_title_ar', 'Page title (Arabic)', p.seoTitleAr, { dir: 'rtl', ...dis })}
          ${field('seo_desc', 'Search description (English)', p.seoDesc, { textarea: true, rows: 2, ...dis })}
          ${field('seo_desc_ar', 'Search description (Arabic)', p.seoDescAr, { textarea: true, rows: 2, dir: 'rtl', ...dis })}
        </div></details>
      </div>
      <div class="ed-side">
        <div class="card"><h2>Website</h2>
          ${edit ? toggle('status_on', 'Show on the website', p.status === 'active', 'Hidden products cannot be found or bought.') : ''}
          ${field('category_id', 'Category', p.categoryId || '', { options: [['', 'Choose…'], ...cats.map((c) => [c.id, catLabel(c)])], ...dis })}
          ${field('sour_level', 'Sourness meter', p.sourLevel == null ? '' : String(p.sourLevel), { options: SOUR, ...dis })}
          ${edit ? toggle('best_seller', 'Best seller', p.bestSeller, 'Shows the ★ badge and puts it under Best sellers.') : ''}
          ${edit ? toggle('is_new', 'New', p.isNew) : ''}
        </div>
        <div class="card"><h2>Price (BHD)</h2><div class="form-grid">
          ${field('price', 'Price', p.price === '' ? '' : Number(p.price).toFixed(3), { type: 'number', step: '0.001', min: 0, dir: 'ltr', inputmode: 'decimal', ...dis })}
          ${field('regular_price', 'Price before discount', p.regularPrice > p.price ? Number(p.regularPrice).toFixed(3) : '', { type: 'number', step: '0.001', min: 0, dir: 'ltr', inputmode: 'decimal', hint: 'Only for a sale: shown crossed out.', ...dis })}
        </div><div id="conv" class="muted small"></div></div>
        <div class="card" id="stockcard"><h2>Stock</h2>
          ${edit ? toggle('manage_stock', 'Count the stock', p.manageStock, 'Sold out by itself at 0.') : ''}
          <div id="qtywrap">${field('stock_qty', 'In stock', p.stockQty, { type: 'number', step: 1, dir: 'ltr', ...dis })}</div>
          <div id="instockwrap">${edit ? toggle('in_stock', 'Available to buy', p.inStock) : ''}</div>
        </div>
        <div class="card"><h2>Delivery</h2>
          ${field('weight_grams', 'Weight with packing (grams)', p.weightGrams || '', { type: 'number', step: 1, min: 0, dir: 'ltr', hint: 'Used for the delivery price outside Bahrain.', ...dis })}
          ${!isNew ? `<p class="muted small">Used for delivery: <b data-noi18n>${nf(p.effectiveWeight)} g</b></p>` : ''}
        </div>
        <div class="card"><h2>Codes</h2>
          ${field('sku', 'SKU', p.sku, { dir: 'ltr', ...dis })}
          ${field('barcode', 'Barcode', p.barcode, { dir: 'ltr', inputmode: 'numeric', ...dis })}
        </div>
      </div>
    </div>
    ${edit ? `<div class="savebar">${!isNew ? `<button type="button" class="btn btn-danger" id="del">${icon('trash')} Delete</button><span style="flex:1"></span>` : ''}
      <a class="btn" href="#/products">Cancel</a><button type="submit" class="btn btn-primary" id="save">${isNew ? 'Add product' : 'Save'}</button></div>` : ''}
    </form>`;

  const form = main.querySelector('#pf');

  /* --- photos --- */
  const imgBox = main.querySelector('#imgs');
  const drawImages = () => {
    imgBox.innerHTML = images.length ? images.map((u, i) => `<div class="img-item">
      <img src="${esc(u.replace(/^\/uploads\//, '/img/400/uploads/'))}" alt="">${i === 0 ? '<span class="img-main">Main photo</span>' : ''}
      ${edit ? `<div class="img-tools">
        ${i > 0 ? `<button type="button" class="iconbtn" data-mv="${i}" data-dir="-1" aria-label="Move earlier">${icon('up', 'flip-x')}</button>` : ''}
        ${i < images.length - 1 ? `<button type="button" class="iconbtn" data-mv="${i}" data-dir="1" aria-label="Move later">${icon('down', 'flip-x')}</button>` : ''}
        <button type="button" class="iconbtn" data-rm="${i}" aria-label="Remove">${icon('close')}</button></div>` : ''}</div>`).join('')
      : '<p class="empty small">No photo yet. A product needs at least one photo to show on the website.</p>';
  };
  drawImages();
  imgBox.addEventListener('click', (e) => {
    const mv = e.target.closest('[data-mv]');
    const rm = e.target.closest('[data-rm]');
    if (mv) { const i = +mv.dataset.mv; const j = i + +mv.dataset.dir; [images[i], images[j]] = [images[j], images[i]]; drawImages(); }
    if (rm) { images.splice(+rm.dataset.rm, 1); drawImages(); }
  });
  const add = main.querySelector('#addimg');
  if (add) add.addEventListener('click', async () => {
    const urls = await pickMedia({ multiple: true, kind: 'product', title: 'Choose photos for this product' });
    urls.forEach((u) => { if (!images.includes(u)) images.push(u); });
    drawImages();
  });

  /* --- price in other currencies (what customers see) --- */
  let rates = null;
  const conv = async () => {
    const box = main.querySelector('#conv');
    const v = parseFloat(form.querySelector('[name=price]').value);
    if (!(v > 0)) { box.textContent = ''; return; }
    if (!rates) {
      try { rates = (await fetch('/api/storefront', { credentials: 'same-origin' }).then((x) => x.json())).currencies || []; } catch (e) { rates = []; }
    }
    box.innerHTML = rates.filter((c) => c.code !== 'BHD').map((c) => `<span data-noi18n>${esc(c.code)} ${nf(Math.round(v * c.rate * 10 ** c.dec) / 10 ** c.dec, c.dec)}</span>`).join(' · ');
  };
  form.querySelector('[name=price]').addEventListener('input', debounce(conv, 250));
  conv();

  /* --- stock fields follow the switch --- */
  const ms = form.querySelector('[name=manage_stock]');
  const syncStock = () => {
    const variable = form.querySelector('[name=type]').value === 'variable';
    main.querySelector('#stockcard').hidden = variable;
    const on = ms ? ms.checked : p.manageStock;
    main.querySelector('#qtywrap').hidden = !on;
    main.querySelector('#instockwrap').hidden = on;
  };
  if (ms) ms.addEventListener('change', syncStock);

  /* --- variations --- */
  const varBox = main.querySelector('#vars');
  const drawVars = () => {
    const variable = form.querySelector('[name=type]').value === 'variable';
    if (!variable) { varBox.innerHTML = '<p class="muted small">One price, one stock count.</p>'; syncStock(); return; }
    varBox.innerHTML = `<div class="table-wrap"><table class="t vt"><thead><tr><th>Colour</th><th>Size</th><th>Length</th><th>SKU</th><th>Barcode</th><th class="num">Stock</th><th>On</th><th></th></tr></thead><tbody>
      ${variations.map((v, i) => `<tr data-vi="${i}">
        ${['colour', 'size', 'length', 'sku', 'barcode'].map((k) => `<td data-field="variations.${i}.${k}"><input class="input" data-vk="${k}" value="${esc(v[k] || '')}" ${edit ? '' : 'disabled'} ${k === 'sku' || k === 'barcode' ? 'dir="ltr"' : ''} aria-label="${esc(k)}"></td>`).join('')}
        <td class="num" data-field="variations.${i}.stock_qty"><input class="input qty-in" type="number" data-vk="stockQty" value="${v.stockQty || 0}" ${edit ? '' : 'disabled'} dir="ltr" aria-label="Stock"></td>
        <td><input type="checkbox" data-vk="enabled" ${v.enabled !== false ? 'checked' : ''} ${edit ? '' : 'disabled'} aria-label="On"></td>
        <td>${edit ? `<button type="button" class="iconbtn" data-vrm="${i}" aria-label="Remove">${icon('close')}</button>` : ''}</td></tr>`).join('')}
      </tbody></table></div>
      ${edit ? `<button type="button" class="btn btn-sm" id="vadd" style="margin-top:10px">${icon('plus')} Add a version</button>` : ''}
      <p class="muted small">Each version has its own stock. The price is the same for all.</p>`;
    syncStock();
  };
  varBox.addEventListener('input', (e) => {
    const tr = e.target.closest('[data-vi]');
    if (!tr) return;
    const k = e.target.dataset.vk;
    variations[+tr.dataset.vi][k] = e.target.type === 'checkbox' ? e.target.checked : (k === 'stockQty' ? +e.target.value : e.target.value);
  });
  varBox.addEventListener('change', (e) => { if (e.target.type === 'checkbox') varBox.dispatchEvent(new Event('input', { bubbles: true })); });
  varBox.addEventListener('click', (e) => {
    if (e.target.closest('#vadd')) { variations.push({ colour: '', size: '', length: '', sku: '', barcode: '', stockQty: 0, enabled: true }); drawVars(); }
    const rm = e.target.closest('[data-vrm]');
    if (rm) { variations.splice(+rm.dataset.vrm, 1); drawVars(); }
  });
  form.querySelector('[name=type]').addEventListener('change', drawVars);
  drawVars();

  if (!edit) return;

  /* --- save --- */
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    clearErrors(form);
    const f = formData(form);
    const body = {
      type: f.type, name: f.name, name_ar: f.name_ar, flavor: f.flavor, flavor_ar: f.flavor_ar,
      description: f.description, description_ar: f.description_ar, images,
      category_id: f.category_id, sour_level: f.sour_level, best_seller: f.best_seller, is_new: f.is_new,
      status: f.status_on ? 'active' : 'hidden', price: f.price, regular_price: f.regular_price || f.price,
      manage_stock: f.manage_stock, stock_qty: f.stock_qty, in_stock: f.in_stock,
      weight_grams: f.weight_grams || 0, own_weight: +f.weight_grams > 0, sku: f.sku, barcode: f.barcode,
      seo_title: f.seo_title, seo_title_ar: f.seo_title_ar, seo_desc: f.seo_desc, seo_desc_ar: f.seo_desc_ar,
    };
    if (f.type === 'variable') {
      body.variations = variations.map((v) => ({ id: v.id, colour: v.colour, size: v.size, length: v.length, sku: v.sku, barcode: v.barcode, barcode2: v.barcode2 || '', stock_qty: v.stockQty, enabled: v.enabled !== false }));
    }
    try {
      const res = await busy(main.querySelector('#save'), () => api(isNew ? 'products' : 'products/' + p.id, { method: isNew ? 'POST' : 'PUT', body }));
      toast(isNew ? 'Product added' : 'Saved');
      if (isNew) go('#/products/' + res.product.id); else editor(main, route, alive);
    } catch (err) {
      showErrors(form, err);
      if (err.fields && err.fields.images) main.querySelector('[data-field="images"]').scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  });
  const del = main.querySelector('#del');
  if (del) del.addEventListener('click', async () => {
    if (!(await confirmBox('Delete this product? This cannot be undone. If it was ever ordered, hide it instead.', { ok: 'Delete', danger: true }))) return;
    try { await api('products/' + p.id, { method: 'DELETE' }); toast('Deleted'); go('#/products'); } catch (err) { toastErr(err); }
  });
}
