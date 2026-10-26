/* Tartebz — core app: language, currency, header/footer, floating cart, animations. */
(function () {
  var TZ = window.TZ;
  var doc = document;
  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------- storage (fails quietly in private mode) ---------- */
  var store = {
    get: function (k, d) { try { var v = localStorage.getItem('tz_' + k); return v == null ? d : JSON.parse(v); } catch (e) { return d; } },
    set: function (k, v) { try { localStorage.setItem('tz_' + k, JSON.stringify(v)); } catch (e) { /* ignore */ } }
  };

  /* ---------- language ---------- */
  var params = new URLSearchParams(location.search);
  var lang = params.get('lang') || store.get('lang', 'ar');
  if (lang !== 'ar' && lang !== 'en') lang = 'ar';
  store.set('lang', lang);

  TZ.lang = function () { return lang; };
  TZ.t = function (key, vars) {
    var s = (TZ.i18n[lang] && TZ.i18n[lang][key]);
    if (s == null) s = TZ.i18n.ar[key];
    if (s == null) return key;
    if (vars && typeof s === 'string') s = s.replace(/\{(\w+)\}/g, function (m, k) { return vars[k] != null ? vars[k] : m; });
    return s;
  };
  TZ.L = function (o) { return o ? (o[lang] != null ? o[lang] : o.ar) : ''; };
  TZ.esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };

  function applyLang() {
    var html = doc.documentElement;
    html.lang = lang;
    html.dir = lang === 'ar' ? 'rtl' : 'ltr';
    doc.body.classList.toggle('lang-en', lang === 'en');
    doc.body.classList.toggle('lang-ar', lang === 'ar');
    var tk = doc.body.getAttribute('data-title');
    var brand = TZ.L(TZ.store.name);
    doc.title = tk ? TZ.t(tk) + ' | ' + brand : brand + ' | ' + (lang === 'ar' ? 'قمر الدين الأصلي' : 'The original qamar al-din');
  }

  /* Fills [data-i18n], [data-i18n-html], [data-i18n-ph], [data-i18n-aria] inside root. */
  TZ.applyI18n = function (root) {
    root = root || doc;
    root.querySelectorAll('[data-i18n]').forEach(function (el) { el.textContent = TZ.t(el.getAttribute('data-i18n')); });
    root.querySelectorAll('[data-i18n-html]').forEach(function (el) { el.innerHTML = TZ.t(el.getAttribute('data-i18n-html')); });
    root.querySelectorAll('[data-i18n-ph]').forEach(function (el) { el.placeholder = TZ.t(el.getAttribute('data-i18n-ph')); });
    root.querySelectorAll('[data-i18n-aria]').forEach(function (el) { el.setAttribute('aria-label', TZ.t(el.getAttribute('data-i18n-aria'))); });
    /* hooks for static pages */
    root.querySelectorAll('[data-href]').forEach(function (el) { el.href = TZ.url(el.getAttribute('data-href')); });
    root.querySelectorAll('[data-icon]').forEach(function (el) { el.innerHTML = TZ.icon(el.getAttribute('data-icon')); });
    root.querySelectorAll('[data-brand-icon]').forEach(function (el) { el.innerHTML = TZ.brandIcon(el.getAttribute('data-brand-icon')); });
    root.querySelectorAll('[data-logo]').forEach(function (el) { el.innerHTML = TZ.logoSVG(el.getAttribute('data-logo')); });
    root.querySelectorAll('[data-art]').forEach(function (el) { var p = TZ.find(el.getAttribute('data-art')); if (p) el.innerHTML = TZ.productArt(p); });
  };

  /* Internal links: clean, site-absolute addresses ('shop.html?cat=x' -> '/shop?cat=x', 'index.html' -> '/'),
     keeping ?lang=en so shared links open in the same language. Absolute paths also work on /product/<slug> pages. */
  TZ.url = function (href) {
    if (/^(https?:|mailto:|tel:|#)/.test(href)) return href;
    var h = '/' + href.replace(/^\/+/, '').replace(/^index\.html/, '').replace(/^([a-z-]+)\.html/, '$1');
    if (lang === 'ar') return h;
    return h + (h.indexOf('?') > -1 ? '&' : '?') + 'lang=en';
  };

  /* ---------- country & money ---------- */
  var countryCode = store.get('country', 'BH');
  TZ.country = function () {
    return TZ.countries.filter(function (c) { return c.code === countryCode; })[0] || TZ.countries[0];
  };
  TZ.setCountry = function (code) {
    if (code === countryCode) return;
    countryCode = code; store.set('country', code); TZ.rerender();
  };
  TZ.amount = function (bhd, c) {
    c = c || TZ.country();
    var v = bhd * c.rate, f = Math.pow(10, c.dec);
    return Math.round(v * f) / f;
  };
  /* Formats a BHD amount in the chosen country's currency (or the given one). */
  TZ.money = function (bhd, c) {
    c = c || TZ.country();
    var v = TZ.amount(bhd, c);
    var dec = c.dec === 3 ? 3 : (Math.round(v) === v ? 0 : 2);
    var num = v.toLocaleString('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec });
    var sym = TZ.L(c.sym);
    return lang === 'ar' ? num + ' ' + sym : sym + ' ' + num;
  };
  /* "🇧🇭 Bahrain · BHD ▾" – a real <select> laid over a readable face. */
  TZ.curPicker = function (cls) {
    var c = TZ.country();
    return '<label class="cur-pick ' + (cls || '') + '"><span class="sr-only">' + TZ.esc(TZ.t('country_label')) + '</span>' +
      '<span class="cur-face" aria-hidden="true"><span class="cur-flag">' + c.flag + '</span>' + TZ.esc(TZ.L(c.name)) + ' · ' + TZ.esc(TZ.L(c.sym)) + TZ.icon('chevron', 'chev') + '</span>' +
      '<select data-country>' + TZ.countryOptions() + '</select></label>';
  };

  /* Payment marks the shop really accepts (Admin › Settings › Website). */
  var PAY = {
    benefit: ['BenefitPay', '<b>Benefit</b><i>Pay</i>'],
    visa: ['Visa', '<b>VISA</b>'],
    mastercard: ['Mastercard', '<svg viewBox="0 0 34 21" aria-hidden="true"><circle cx="12.5" cy="10.5" r="8.5" fill="#EB001B"/><circle cx="21.5" cy="10.5" r="8.5" fill="#F79E1B" fill-opacity=".92"/><path d="M17 3.3a8.5 8.5 0 0 1 0 14.4 8.5 8.5 0 0 1 0-14.4z" fill="#FF5F00"/></svg>'],
    applepay: ['Apple Pay', '<b>Apple Pay</b>'],
    mada: ['mada', '<b>mada</b>'],
    knet: ['KNET', '<b>KNET</b>'],
    tabby: ['tabby', '<b>tabby</b>'],
    cod: [null, null]
  };
  TZ.payLogos = function (cls) {
    var list = (TZ.shop && TZ.shop.paymentLogos) || [];
    if (!list.length) return '';
    return '<span class="pay-logos ' + (cls || '') + '">' + list.map(function (k) {
      var d = PAY[k]; if (!d) return '';
      if (k === 'cod') return '<span class="pl pl-cod">' + TZ.icon('cash') + TZ.esc(TZ.t('pay_cod')) + '</span>';
      return '<span class="pl pl-' + k + '" role="img" aria-label="' + TZ.esc(d[0]) + '" title="' + TZ.esc(d[0]) + '">' + d[1] + '</span>';
    }).join('') + '</span>';
  };

  /* "Arrives tomorrow" / "Arrives Sun 3 Nov – Wed 6 Nov", from the delivery days set per country. */
  TZ.delivery = function (c) {
    c = c || TZ.country();
    var row = ((TZ.shop && TZ.shop.delivery) || []).filter(function (d) { return d.country === c.code; })[0];
    if (!row) return '';
    var loc = lang === 'ar' ? 'ar-BH-u-nu-latn' : 'en-GB';
    var day = function (n) { return new Date(Date.now() + n * 864e5).toLocaleDateString(loc, { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'Asia/Bahrain' }); };
    if (row.max === 0) return TZ.t('arrives_today');
    if (row.min === 1 && row.max === 1) return TZ.t('arrives_tomorrow');
    if (row.min === row.max) return TZ.t('arrives_on', { date: day(row.min) });
    return TZ.t('arrives_range', { from: day(row.min), to: day(row.max) });
  };
  TZ.deliveryChip = function (c) {
    var txt = TZ.delivery(c);
    return txt ? '<span class="eta">' + TZ.icon('truck') + '<span>' + TZ.esc(txt) + '</span></span>' : '';
  };

  TZ.countryOptions = function () {
    var cur = TZ.country().code;
    return TZ.countries.map(function (c) {
      return '<option value="' + c.code + '"' + (c.code === cur ? ' selected' : '') + '>' + c.flag + ' ' + TZ.esc(TZ.L(c.name)) + ' · ' + TZ.esc(TZ.L(c.sym)) + '</option>';
    }).join('');
  };

  /* ---------- products ---------- */
  TZ.find = function (key) {
    return TZ.products.filter(function (p) { return p.id === +key || p.slug === key; })[0];
  };
  TZ.cat = function (id) { return TZ.categories.filter(function (c) { return c.id === id; })[0]; };
  TZ.mainCat = function (p) {
    var c = p.cats.filter(function (x) { return x !== 'best-sellers'; })[0] || p.cats[0];
    return TZ.cat(c);
  };
  TZ.productUrl = function (p) { return TZ.url('product/' + encodeURIComponent(p.slug)); };
  function byOrder(a, b) { return a.order - b.order; }
  /* Best sellers first, then the rest; only what can be bought now. */
  TZ.suggestions = function (n, exclude) {
    var ok = function (p) { return p.inStock && (!exclude || exclude.indexOf(p.id) === -1); };
    return TZ.products.filter(function (p) { return ok(p) && p.bestSeller; }).sort(byOrder)
      .concat(TZ.products.filter(function (p) { return ok(p) && !p.bestSeller; }).sort(byOrder)).slice(0, n);
  };

  /* Recently viewed products (this browser only). */
  TZ.recent = {
    add: function (id) { var l = (store.get('recent', []) || []).filter(function (x) { return x !== id; }); l.unshift(id); store.set('recent', l.slice(0, 8)); },
    list: function (except) { return (store.get('recent', []) || []).filter(function (x) { return x !== except; }).map(TZ.find).filter(Boolean); }
  };

  /* The cart as a ready WhatsApp message to the shop. */
  TZ.waCartLink = function () {
    var c = TZ.country();
    var msg = [TZ.t('wa_order_intro'), ''].concat(TZ.cart.lines().map(function (l) {
      return '• ' + TZ.L(l.p.name) + ' × ' + l.qty + ' = ' + TZ.money(l.p.price * l.qty);
    })).concat(['', TZ.t('cart_subtotal') + ': ' + TZ.money(TZ.cart.subtotal()),
      TZ.t('cart_shipping') + ' (' + c.flag + ' ' + TZ.L(c.name) + '): ' + TZ.money(TZ.cart.shipping()),
      TZ.t('cart_total') + ': ' + TZ.money(TZ.cart.total())]).join('\n');
    return 'https://wa.me/' + ((TZ.shop && TZ.shop.whatsapp) || TZ.store.phoneRaw) + '?text=' + encodeURIComponent(msg);
  };

  /* ---------- shipping ---------- */
  TZ.shipCost = function (weightKg, countryObj) {
    var c = countryObj || TZ.country();
    if (weightKg <= 0) return 0;
    if (c.ship === 'flat') return TZ.shipping.flatBH;
    var t = TZ.shipping.ubex;
    for (var i = 0; i < t.length; i++) { if (weightKg <= t[i][0] + 1e-9) return t[i][1]; }
    var last = t[t.length - 1];
    return last[1] + Math.ceil((weightKg - last[0]) / 0.5) * 1.05;
  };

  /* ---------- cart ---------- */
  var cart = (store.get('cart', []) || []).filter(function (l) { var p = TZ.find(l.id); return p && p.inStock && l.qty > 0; });
  function saveCart() { store.set('cart', cart); renderCart(); doc.dispatchEvent(new CustomEvent('tz:cart')); }
  TZ.cart = {
    lines: function () { return cart.map(function (l) { return { p: TZ.find(l.id), qty: l.qty }; }); },
    count: function () { return cart.reduce(function (s, l) { return s + l.qty; }, 0); },
    subtotal: function () { return cart.reduce(function (s, l) { return s + TZ.find(l.id).price * l.qty; }, 0); },
    weight: function () { return cart.reduce(function (s, l) { return s + TZ.find(l.id).weight * l.qty; }, 0); },
    shipping: function () { return TZ.shipCost(TZ.cart.weight()); },
    total: function () { return TZ.cart.subtotal() + TZ.cart.shipping(); },
    add: function (id, qty) {
      var p = TZ.find(id); if (!p || !p.inStock) return;
      var l = cart.filter(function (x) { return x.id === p.id; })[0];
      if (l) l.qty = Math.min(99, l.qty + (qty || 1)); else cart.push({ id: p.id, qty: qty || 1 });
      saveCart();
    },
    set: function (id, qty) {
      cart = cart.map(function (l) { if (l.id === +id) l.qty = Math.max(0, Math.min(99, qty)); return l; }).filter(function (l) { return l.qty > 0; });
      saveCart();
    },
    remove: function (id) { cart = cart.filter(function (l) { return l.id !== +id; }); saveCart(); },
    clear: function () { cart = []; saveCart(); }
  };

  /* ---------- shared components ---------- */
  TZ.sourMeter = function (level, big) {
    if (!level) return '';
    var segs = '';
    for (var i = 1; i <= 5; i++) {
      segs += '<span class="seg' + (i <= level ? ' on' : '') + '" style="--i:' + i + '">' + (level === 5 && i <= level ? TZ.icon('bolt') : '') + '</span>';
    }
    return '<div class="sour' + (big ? ' sour-lg' : '') + (level === 5 ? ' is-electric' : '') + '" role="img" aria-label="' +
      TZ.esc(TZ.t('sour_meter') + ': ' + TZ.t('sour_' + level) + ' (' + level + '/5)') + '">' +
      '<span class="sour-label">' + (big ? TZ.esc(TZ.t('sour_meter')) + ' · ' : '') + '<b>' + TZ.esc(TZ.t('sour_' + level)) + '</b></span>' +
      '<span class="sour-bar">' + segs + '</span></div>';
  };

  TZ.badges = function (p, noStock) {
    return '<span class="badges">' +
      (noStock ? '<span></span>' : '<span class="badge badge-stock ' + (p.inStock ? 'in' : 'out') + '">' + TZ.esc(TZ.t(p.inStock ? 'in_stock' : 'out_of_stock')) + '</span>') +
      (p.bestSeller ? '<span class="badge badge-bs">★ ' + TZ.esc(TZ.t('best_seller')) + '</span>' : '') +
      '</span>';
  };

  TZ.card = function (p, i) {
    var name = TZ.esc(TZ.L(p.name)), cat = TZ.mainCat(p);
    var c = p.art.colors;
    return '<article class="pcard reveal' + (p.inStock ? '' : ' is-out') + '" style="--c1:' + c[0] + ';--c2:' + c[1] + ';--d:' + ((i || 0) % 4) * 80 + 'ms" data-id="' + p.id + '">' +
      '<a class="pcard-media" href="' + TZ.productUrl(p) + '" aria-label="' + name + '">' + TZ.badges(p) +
      '<span class="pcard-glow"></span>' + TZ.productArt(p, 'pcard-art') + '</a>' +
      '<div class="pcard-body">' +
      (cat ? '<a class="pcard-cat" href="' + TZ.url('shop.html?cat=' + cat.id) + '">' + TZ.esc(TZ.L(cat.name)) + '</a>' : '') +
      '<h3 class="pcard-title"><a href="' + TZ.productUrl(p) + '">' + name + '</a></h3>' +
      TZ.sourMeter(p.sour) +
      '<div class="pcard-foot"><span class="price">' + TZ.esc(TZ.money(p.price)) + '</span>' +
      (p.inStock
        ? '<button class="btn-add" type="button" data-add="' + p.id + '" aria-label="' + TZ.esc(TZ.t('add_to_cart') + ': ' + TZ.L(p.name)) + '">' + TZ.icon('plus') + '<span>' + TZ.esc(TZ.t('add')) + '</span></button>'
        : (TZ.shop && TZ.shop.backInStock
          ? '<a class="btn-notify" href="' + TZ.productUrl(p) + '#notify">' + TZ.icon('bell') + '<span>' + TZ.esc(TZ.t('notify_card')) + '</span></a>'
          : '<span class="sold-out">' + TZ.esc(TZ.t('sold_out')) + '</span>')) +
      '</div></div></article>';
  };

  /* ---------- header ---------- */
  var NAV = [['index.html', 'home', 'nav_home'], ['shop.html', 'shop', 'nav_shop'], ['about.html', 'about', 'nav_about'], ['contact.html', 'contact', 'nav_contact']];
  function navLinks(cls) {
    var page = doc.body.getAttribute('data-page');
    if (page === 'product') page = 'shop';
    return NAV.map(function (n) {
      return '<a class="' + cls + (page === n[1] ? ' is-active' : '') + '" href="' + TZ.url(n[0]) + '"' + (page === n[1] ? ' aria-current="page"' : '') + '>' + TZ.esc(TZ.t(n[2])) + '</a>';
    }).join('');
  }

  function renderHeader() {
    var el = doc.getElementById('site-header');
    if (!el) return;
    el.innerHTML =
      '<a class="skip-link" href="#main">' + TZ.esc(TZ.t('skip')) + '</a>' +
      '<header id="masthead" class="site-header">' +
      '<div class="container header-inner">' +
      '<button class="hamb" type="button" data-open-menu aria-controls="mobile-panel" aria-expanded="false" aria-label="' + TZ.esc(TZ.t('menu')) + '"><span></span><span></span><span></span></button>' +
      '<a class="brand" href="' + TZ.url('index.html') + '">' + TZ.logoSVG('brand-logo') + '<span class="brand-name">' + TZ.esc(TZ.L(TZ.store.name)) + '</span></a>' +
      '<nav class="main-nav" aria-label="' + TZ.esc(TZ.t('menu')) + '">' + navLinks('nav-link') + '</nav>' +
      '<div class="header-actions">' +
      '<label class="country-select" title="' + TZ.esc(TZ.t('country_label')) + '"><span class="sr-only">' + TZ.esc(TZ.t('country_label')) + '</span><select data-country>' + TZ.countryOptions() + '</select>' + TZ.icon('chevron', 'chev') + '</label>' +
      '<button class="lang-btn" type="button" data-lang-toggle aria-label="' + TZ.esc(TZ.t('lang_label')) + '">' + TZ.icon('globe') + '<span>' + TZ.esc(TZ.t('lang_short')) + '</span></button>' +
      '<button class="icon-btn cart-btn" type="button" data-open-cart aria-label="' + TZ.esc(TZ.t('cart')) + '">' + TZ.icon('bag') + '<span class="cart-count" data-cart-count></span></button>' +
      '<a class="btn btn-order" href="' + TZ.url('shop.html') + '">' + TZ.esc(TZ.t('order_now')) + '</a>' +
      '</div></div></header>';

    var mp = doc.getElementById('mobile-panel');
    if (!mp) {
      var ov = doc.createElement('div'); ov.className = 'overlay menu-ov'; ov.setAttribute('data-close-menu', '');
      mp = doc.createElement('aside'); mp.id = 'mobile-panel'; mp.className = 'mobile-panel'; mp.setAttribute('aria-hidden', 'true');
      doc.body.appendChild(ov); doc.body.appendChild(mp);
    }
    mp.setAttribute('aria-label', TZ.t('menu'));
    mp.innerHTML =
      '<div class="mp-head">' + TZ.logoSVG('mp-logo') + '<button class="icon-btn mp-close" type="button" data-close-menu aria-label="' + TZ.esc(TZ.t('close')) + '">' + TZ.icon('close') + '</button></div>' +
      '<nav class="mp-nav">' + navLinks('mp-link') + '</nav>' +
      '<div class="mp-tools">' +
      '<label class="field-label">' + TZ.esc(TZ.t('country_label')) + '<span class="select-wrap"><select data-country>' + TZ.countryOptions() + '</select>' + TZ.icon('chevron', 'chev') + '</span></label>' +
      '<button class="btn btn-outline" type="button" data-lang-toggle>' + TZ.icon('globe') + TZ.esc(TZ.t('lang_switch')) + '</button>' +
      '</div>' +
      '<a class="btn btn-primary btn-block" href="' + TZ.url('shop.html') + '">' + TZ.esc(TZ.t('order_now')) + '</a>' +
      '<div class="mp-social">' + socialLinks() + '</div>';
    updateCount();
    onScroll();
  }

  function socialLinks() {
    return '<a class="social-link" href="' + TZ.store.social.instagram + '" target="_blank" rel="noopener" aria-label="Instagram">' + TZ.brandIcon('instagram') + '</a>' +
      '<a class="social-link" href="' + TZ.store.social.tiktok + '" target="_blank" rel="noopener" aria-label="TikTok">' + TZ.brandIcon('tiktok') + '</a>' +
      '<a class="social-link" href="https://wa.me/' + TZ.store.phoneRaw + '" target="_blank" rel="noopener" aria-label="WhatsApp">' + TZ.brandIcon('whatsapp') + '</a>';
  }
  TZ.socialLinks = socialLinks;

  /* ---------- footer ---------- */
  function renderFooter() {
    var el = doc.getElementById('site-footer');
    if (!el) return;
    var cats = TZ.categories.map(function (c) { return '<li><a href="' + TZ.url('shop.html?cat=' + c.id) + '">' + TZ.esc(TZ.L(c.name)) + '</a></li>'; }).join('');
    el.innerHTML =
      '<footer class="site-footer">' +
      '<div class="footer-wave" aria-hidden="true"></div>' +
      '<div class="container footer-grid">' +
      '<div class="footer-brand">' + TZ.logoSVG('footer-logo') +
      '<p>' + TZ.esc(TZ.t('footer_about')) + '</p>' +
      '<div class="footer-social" aria-label="' + TZ.esc(TZ.t('footer_follow')) + '">' + socialLinks() + '</div></div>' +
      '<div><h3>' + TZ.esc(TZ.t('footer_links')) + '</h3><ul>' +
      NAV.map(function (n) { return '<li><a href="' + TZ.url(n[0]) + '">' + TZ.esc(TZ.t(n[2])) + '</a></li>'; }).join('') +
      '<li><a href="' + TZ.url('checkout.html') + '">' + TZ.esc(TZ.t('co_title')) + '</a></li></ul></div>' +
      '<div><h3>' + TZ.esc(TZ.t('footer_cats')) + '</h3><ul>' + cats + '</ul></div>' +
      '<div><h3>' + TZ.esc(TZ.t('footer_contact')) + '</h3><ul class="footer-contact">' +
      '<li>' + TZ.icon('mail') + '<a href="mailto:' + TZ.store.email + '">' + TZ.store.email + '</a></li>' +
      '<li>' + TZ.icon('phone') + '<a href="tel:+' + TZ.store.phoneRaw + '" dir="ltr">' + TZ.store.phone + '</a></li>' +
      '<li>' + TZ.icon('pin') + '<span>' + TZ.esc(TZ.L(TZ.store.address)) + '</span></li>' +
      '</ul></div></div>' +
      '<div class="container footer-bottom">' +
      '<p>' + TZ.esc(TZ.t('footer_rights', { y: new Date().getFullYear() })) + ' · ' + TZ.esc(TZ.t('footer_cr', { cr: TZ.store.cr })) + '</p>' +
      '<div class="pay-row"><span>' + TZ.esc(TZ.t('footer_pay')) + '</span>' + TZ.payLogos() + '</div>' +
      '</div></footer>';
  }

  /* ---------- floating cart + drawer ---------- */
  var drawer, fab, toastEl, lastFocus;
  function buildCartShell() {
    var ov = doc.createElement('div'); ov.className = 'overlay cart-ov'; ov.setAttribute('data-close-cart', '');
    drawer = doc.createElement('aside');
    drawer.className = 'cart-drawer'; drawer.id = 'cart-drawer';
    drawer.setAttribute('role', 'dialog'); drawer.setAttribute('aria-modal', 'true'); drawer.setAttribute('aria-hidden', 'true');
    fab = doc.createElement('button');
    fab.type = 'button'; fab.className = 'cart-fab'; fab.setAttribute('data-open-cart', '');
    toastEl = doc.createElement('div'); toastEl.className = 'toast'; toastEl.setAttribute('role', 'status'); toastEl.setAttribute('aria-live', 'polite');
    doc.body.appendChild(ov); doc.body.appendChild(drawer); doc.body.appendChild(fab); doc.body.appendChild(toastEl);
  }

  function updateCount() {
    var n = TZ.cart.count();
    doc.querySelectorAll('[data-cart-count]').forEach(function (el) { el.textContent = n || ''; el.classList.toggle('has', n > 0); });
    if (fab) fab.classList.toggle('has-items', n > 0);
  }

  function renderCart() {
    if (!drawer) return;
    var lines = TZ.cart.lines(), n = TZ.cart.count(), c = TZ.country();
    fab.setAttribute('aria-label', TZ.t('cart') + (n ? ' (' + n + ')' : ''));
    fab.innerHTML = TZ.icon('bag') + '<span class="fab-count" data-cart-count></span>' +
      (n ? '<span class="fab-total">' + TZ.esc(TZ.money(TZ.cart.subtotal())) + '</span>' : '');
    drawer.setAttribute('aria-label', TZ.t('cart_title'));

    var body;
    if (!lines.length) {
      body = '<div class="cart-empty">' + emptyArt() + '<h3>' + TZ.esc(TZ.t('cart_empty_title')) + '</h3><p>' + TZ.esc(TZ.t('cart_empty_text')) + '</p>' +
        '<a class="btn btn-primary" href="' + TZ.url('shop.html') + '">' + TZ.esc(TZ.t('order_now')) + '</a></div>' + TZ.miniList(TZ.suggestions(4));
    } else {
      body = '<ul class="cart-lines">' + lines.map(function (l) {
        var p = l.p;
        return '<li class="cart-line" style="--c1:' + p.art.colors[0] + '">' +
          '<a class="cl-thumb" href="' + TZ.productUrl(p) + '">' + TZ.productArt(p) + '</a>' +
          '<div class="cl-info"><a class="cl-name" href="' + TZ.productUrl(p) + '">' + TZ.esc(TZ.L(p.name)) + '</a>' +
          '<span class="cl-unit">' + TZ.esc(TZ.money(p.price)) + '</span>' +
          '<div class="qty qty-sm">' +
          '<button type="button" data-qty="' + p.id + '" data-step="-1" aria-label="' + TZ.esc(TZ.t('decrease')) + '">' + TZ.icon('minus') + '</button>' +
          '<span aria-live="polite">' + l.qty + '</span>' +
          '<button type="button" data-qty="' + p.id + '" data-step="1" aria-label="' + TZ.esc(TZ.t('increase')) + '">' + TZ.icon('plus') + '</button></div></div>' +
          '<div class="cl-side"><b>' + TZ.esc(TZ.money(p.price * l.qty)) + '</b>' +
          '<button class="cl-remove" type="button" data-remove="' + p.id + '" aria-label="' + TZ.esc(TZ.t('cart_remove') + ': ' + TZ.L(p.name)) + '">' + TZ.icon('trash') + '</button></div></li>';
      }).join('') + '</ul>';
    }
    var w = Math.round(TZ.cart.weight() * 10) / 10;
    var foot = lines.length ?
      '<div class="cart-foot">' +
      '<label class="ship-to">' + TZ.icon('truck') + '<span>' + TZ.esc(TZ.t('ship_to_label')) + '</span><span class="select-wrap"><select data-country>' + TZ.countryOptions() + '</select>' + TZ.icon('chevron', 'chev') + '</span></label>' +
      '<dl class="totals"><div><dt>' + TZ.esc(TZ.t('cart_subtotal')) + '</dt><dd>' + TZ.esc(TZ.money(TZ.cart.subtotal())) + '</dd></div>' +
      '<div><dt>' + TZ.esc(TZ.t('cart_shipping')) + '<small>' + TZ.esc(c.ship === 'flat' ? TZ.t('cart_ship_note_flat') : TZ.t('cart_ship_note_ubex', { w: w })) + '</small></dt><dd>' + TZ.esc(TZ.money(TZ.cart.shipping())) + '</dd></div>' +
      '<div class="grand"><dt>' + TZ.esc(TZ.t('cart_total')) + '</dt><dd>' + TZ.esc(TZ.money(TZ.cart.total())) + '</dd></div></dl>' +
      (TZ.delivery(c) ? '<p class="cart-eta">' + TZ.deliveryChip(c) + '</p>' : '') +
      '<a class="btn btn-primary btn-block btn-lg" href="' + TZ.url('checkout.html') + '">' + TZ.esc(TZ.t('cart_checkout')) + TZ.icon('arrow', 'flip-rtl') + '</a>' +
      (TZ.shop && TZ.shop.whatsappOrders ? '<a class="btn btn-whatsapp btn-block" href="' + TZ.esc(TZ.waCartLink()) + '" target="_blank" rel="noopener">' + TZ.brandIcon('whatsapp') + TZ.esc(TZ.t('wa_order')) + '</a>' : '') +
      '<div class="cart-pay">' + TZ.payLogos() + '</div>' +
      '<button class="btn-link" type="button" data-close-cart>' + TZ.esc(TZ.t('cart_continue')) + '</button></div>' : '';

    drawer.innerHTML =
      '<div class="cart-head"><h2>' + TZ.icon('bag') + TZ.esc(TZ.t('cart_title')) + (n ? ' <span class="pill-count">' + TZ.esc(TZ.t('cart_items', { n: n })) + '</span>' : '') + '</h2>' +
      '<button class="icon-btn" type="button" data-close-cart aria-label="' + TZ.esc(TZ.t('close')) + '">' + TZ.icon('close') + '</button></div>' +
      '<div class="cart-body">' + body + '</div>' + foot;
    updateCount();
  }

  /* A short list of products with a quick "+" (empty cart, empty checkout). */
  TZ.miniList = function (list) {
    if (!list.length) return '';
    return '<div class="mini-sugg"><h4>' + TZ.icon('star') + TZ.esc(TZ.t('cart_suggest')) + '</h4><ul>' + list.map(function (p) {
      return '<li data-id="' + p.id + '"><a class="ms-thumb" href="' + TZ.productUrl(p) + '">' + TZ.productArt(p) + '</a>' +
        '<span class="ms-info"><a href="' + TZ.productUrl(p) + '">' + TZ.esc(TZ.L(p.name)) + '</a><b>' + TZ.esc(TZ.money(p.price)) + '</b></span>' +
        '<button class="btn-add btn-add-sm" type="button" data-add="' + p.id + '" aria-label="' + TZ.esc(TZ.t('add_to_cart') + ': ' + TZ.L(p.name)) + '">' + TZ.icon('plus') + '</button></li>';
    }).join('') + '</ul></div>';
  };

  function emptyArt() {
    return '<svg class="empty-art" viewBox="0 0 160 140" aria-hidden="true"><ellipse cx="80" cy="128" rx="52" ry="7" fill="#000" opacity=".08"/>' +
      '<path d="M38 50h84l-7 70a8 8 0 0 1-8 7H53a8 8 0 0 1-8-7z" fill="#FFF8DC" stroke="#E30613" stroke-width="4" stroke-linejoin="round"/>' +
      '<path d="M60 50v-8a20 20 0 0 1 40 0v8" fill="none" stroke="#E30613" stroke-width="4" stroke-linecap="round"/>' +
      '<circle cx="66" cy="84" r="4" fill="#1F2024"/><circle cx="94" cy="84" r="4" fill="#1F2024"/><path d="M68 102q12-8 24 0" fill="none" stroke="#1F2024" stroke-width="4" stroke-linecap="round"/>' +
      '<path class="art-spark" transform="translate(122 18)" fill="#FFD700" d="M8 0l1.6 6.4L16 8l-6.4 1.6L8 16l-1.6-6.4L0 8l6.4-1.6z"/></svg>';
  }

  function openPanel(panel, ovSel) {
    lastFocus = doc.activeElement;
    panel.classList.add('open'); panel.setAttribute('aria-hidden', 'false');
    var ov = doc.querySelector(ovSel); if (ov) ov.classList.add('open');
    doc.body.classList.add('no-scroll');
    setTimeout(function () { var f = panel.querySelector('button, a, select'); if (f) f.focus(); }, 60);
  }
  function closePanel(panel, ovSel) {
    if (!panel || !panel.classList.contains('open')) return;
    panel.classList.remove('open'); panel.setAttribute('aria-hidden', 'true');
    var ov = doc.querySelector(ovSel); if (ov) ov.classList.remove('open');
    doc.body.classList.remove('no-scroll');
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }
  TZ.openCart = function () { closeMenu(); openPanel(drawer, '.cart-ov'); };
  TZ.closeCart = function () { closePanel(drawer, '.cart-ov'); };
  function openMenu() {
    var mp = doc.getElementById('mobile-panel');
    openPanel(mp, '.menu-ov');
    doc.querySelectorAll('[data-open-menu]').forEach(function (b) { b.classList.add('open'); b.setAttribute('aria-expanded', 'true'); });
  }
  function closeMenu() {
    closePanel(doc.getElementById('mobile-panel'), '.menu-ov');
    doc.querySelectorAll('[data-open-menu]').forEach(function (b) { b.classList.remove('open'); b.setAttribute('aria-expanded', 'false'); });
  }

  /* Add-to-cart: little pack flies into the floating cart */
  TZ.addToCart = function (id, qty, fromEl) {
    var p = TZ.find(id); if (!p || !p.inStock) return;
    TZ.cart.add(id, qty || 1);
    toast(TZ.t('added', { name: TZ.L(p.name) }));
    qty = qty || 1;
    var art = fromEl && (fromEl.closest('[data-id]') || doc).querySelector('.art');
    var fabShown = fab.offsetParent && !fab.classList.contains('is-away') && !doc.body.classList.contains('has-buybar');
    if (!art || reduced) { bump(qty); return; }
    // Fly to whichever cart is on screen: the floating one, or the one in the header.
    var target = fabShown ? fab : doc.querySelector('.site-header .cart-btn');
    if (!target) { bump(qty); return; }
    var r = art.getBoundingClientRect(), t = target.getBoundingClientRect();
    var clone = art.cloneNode(true);
    clone.classList.add('fly');
    clone.style.cssText = 'left:' + r.left + 'px;top:' + r.top + 'px;width:' + r.width + 'px;height:' + r.height + 'px';
    doc.body.appendChild(clone);
    var dx = t.left + t.width / 2 - (r.left + r.width / 2), dy = t.top + t.height / 2 - (r.top + r.height / 2);
    var anim = clone.animate([
      { transform: 'translate(0,0) scale(1) rotate(0)', opacity: 1 },
      { transform: 'translate(' + dx * 0.5 + 'px,' + (dy * 0.5 - 80) + 'px) scale(.55) rotate(-12deg)', opacity: 1, offset: 0.55 },
      { transform: 'translate(' + dx + 'px,' + dy + 'px) scale(.12) rotate(8deg)', opacity: 0.4 }
    ], { duration: 750, easing: 'cubic-bezier(.45,.05,.55,1)' });
    anim.onfinish = function () { clone.remove(); bump(qty); };
  };
  /* The cart icons bounce, their count pops and a little "+1" floats up. */
  function bump(qty) {
    [fab].concat([].slice.call(doc.querySelectorAll('.cart-btn'))).forEach(function (b) {
      if (!b) return;
      b.classList.remove('bump'); void b.offsetWidth; b.classList.add('bump');
      if (reduced) return;
      var plus = doc.createElement('span');
      plus.className = 'plus-one'; plus.setAttribute('aria-hidden', 'true'); plus.textContent = '+' + (qty || 1);
      b.appendChild(plus);
      setTimeout(function () { plus.remove(); }, 900);
    });
    doc.querySelectorAll('[data-cart-count]').forEach(function (c) { c.classList.remove('pop'); void c.offsetWidth; c.classList.add('pop'); });
  }

  var toastTimer;
  function toast(msg, kind) {
    toastEl.innerHTML = '<span class="toast-ico">' + TZ.icon(kind === 'cart' ? 'bag' : 'check') + '</span><span class="toast-msg">' + TZ.esc(msg) + '</span>' +
      '<button type="button" class="toast-btn" data-open-cart>' + TZ.esc(TZ.t('view_cart')) + '</button>';
    toastEl.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastEl.classList.remove('show'); }, 3200);
  }
  TZ.toast = toast;

  /* ---------- scroll reveal ---------- */
  var io = ('IntersectionObserver' in window) ? new IntersectionObserver(function (entries) {
    entries.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } });
  }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 }) : null;
  TZ.reveal = function (root) {
    (root || doc).querySelectorAll('.reveal:not(.in), .sour:not(.in)').forEach(function (el) {
      if (!io || reduced) el.classList.add('in'); else io.observe(el);
    });
  };

  /* ---------- header scroll state ---------- */
  function onScroll() {
    var h = doc.getElementById('masthead');
    if (h) h.classList.toggle('scrolled', window.scrollY > 24);
    // The floating cart waits until the page is scrolled (the header cart is right there at the top).
    if (fab) fab.classList.toggle('is-away', window.scrollY < 320);
  }

  /* ---------- in-app browser banner (Instagram/TikTok can't finish Benefit payments) ---------- */
  function inAppBanner() {
    if (!/Instagram|FBAN|FBAV|FB_IAB|TikTok|musical_ly|Snapchat|Line\//i.test(navigator.userAgent || '')) return;
    if (store.get('iab_closed', false)) return;
    var bar = doc.createElement('div');
    bar.className = 'iab'; bar.setAttribute('role', 'alert');
    bar.innerHTML = '<p>' + TZ.esc(TZ.t('iab_text')) + '</p><div class="iab-btns"><button type="button" class="iab-copy">' + TZ.esc(TZ.t('iab_copy')) + '</button>' +
      '<button type="button" class="iab-x">' + TZ.esc(TZ.t('close')) + '</button></div>';
    doc.body.prepend(bar);
    doc.body.classList.add('has-iab');
    bar.querySelector('.iab-x').addEventListener('click', function () { bar.remove(); doc.body.classList.remove('has-iab'); store.set('iab_closed', true); });
    var copy = bar.querySelector('.iab-copy');
    copy.addEventListener('click', function () {
      var url = location.href, done = function () { copy.textContent = TZ.t('iab_copied'); };
      if (navigator.clipboard) navigator.clipboard.writeText(url).then(done).catch(done); else done();
    });
  }

  /* ---------- events ---------- */
  /* Card -> product page: the tapped card's photo grows into the product photo (browsers that support it). */
  doc.addEventListener('click', function (e) {
    var a = e.target.closest('a[href]');
    if (!a || e.defaultPrevented || e.metaKey || e.ctrlKey) return;
    var card = a.closest('.pcard, .mini-sugg li');
    var art = card && a.getAttribute('href').indexOf('/product/') === 0 && card.querySelector('.art');
    if (!art) return;
    doc.querySelectorAll('.pd-stage .pd-art').forEach(function (el) { el.style.viewTransitionName = 'none'; }); // one name per page
    art.style.viewTransitionName = 'pd-photo';
  }, true);
  window.addEventListener('pageshow', function () {
    doc.querySelectorAll('.art[style*="view-transition-name"]').forEach(function (el) { el.style.viewTransitionName = ''; });
  });

  doc.addEventListener('click', function (e) {
    var t = e.target.closest('[data-add],[data-open-cart],[data-close-cart],[data-open-menu],[data-close-menu],[data-lang-toggle],[data-qty],[data-remove]');
    if (!t) return;
    if (t.hasAttribute('data-add')) { e.preventDefault(); TZ.addToCart(+t.getAttribute('data-add'), +(t.getAttribute('data-qty-val') || 1), t); }
    else if (t.hasAttribute('data-open-cart')) { e.preventDefault(); toastEl.classList.remove('show'); TZ.openCart(); }
    else if (t.hasAttribute('data-close-cart')) { TZ.closeCart(); }
    else if (t.hasAttribute('data-open-menu')) { var mp = doc.getElementById('mobile-panel'); if (mp.classList.contains('open')) closeMenu(); else openMenu(); }
    else if (t.hasAttribute('data-close-menu')) { closeMenu(); }
    else if (t.hasAttribute('data-lang-toggle')) { switchLang(lang === 'ar' ? 'en' : 'ar'); }
    else if (t.hasAttribute('data-qty')) {
      var id = +t.getAttribute('data-qty'), line = TZ.cart.lines().filter(function (l) { return l.p.id === id; })[0];
      if (line) TZ.cart.set(id, line.qty + (+t.getAttribute('data-step')));
    }
    else if (t.hasAttribute('data-remove')) { TZ.cart.remove(+t.getAttribute('data-remove')); }
  });
  doc.addEventListener('change', function (e) {
    if (e.target.matches('select[data-country]')) TZ.setCountry(e.target.value);
  });
  doc.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { TZ.closeCart(); closeMenu(); }
  });
  window.addEventListener('scroll', onScroll, { passive: true });

  function switchLang(next) {
    lang = next; store.set('lang', lang);
    var u = new URL(location.href);
    if (lang === 'en') u.searchParams.set('lang', 'en'); else u.searchParams.delete('lang');
    history.replaceState(null, '', u.toString());
    closeMenu();
    TZ.rerender();
  }

  /* Re-renders everything after a language or country change. */
  TZ.rerender = function () {
    applyLang();
    renderHeader();
    renderFooter();
    renderCart();
    if (TZ.pages && TZ.pages[doc.body.getAttribute('data-page')]) TZ.pages[doc.body.getAttribute('data-page')]();
    TZ.applyI18n(doc);
    TZ.reveal(doc);
  };

  /* A returning visitor with things in the cart is reminded of it (once per visit). */
  function welcomeBack() {
    var last = store.get('last_seen', 0), now = Date.now();
    store.set('last_seen', now);
    var n = TZ.cart.count();
    if (n && last && now - last > 2 * 3600e3 && doc.body.getAttribute('data-page') !== 'checkout') {
      setTimeout(function () { toast(TZ.t('welcome_back', { n: n }), 'cart'); }, 1200);
    }
  }
  doc.addEventListener('visibilitychange', function () { if (doc.hidden) store.set('last_seen', Date.now()); });

  /* ---------- boot ---------- */
  function boot() {
    var start = function () {
      if (TZ.booted) return;
      TZ.booted = true;
      buildCartShell();
      TZ.rerender();
      inAppBanner();
      doc.body.classList.add('is-ready');
      onScroll();
      welcomeBack();
    };
    /* Wait for the live shop data (see live.js); fall back to the built-in data if it never comes. */
    if (TZ.live && TZ.live.ready) TZ.live.ready.then(start, start); else start();
  }
  /* Deferred scripts run before DOMContentLoaded, so waiting for it guarantees pages.js is loaded. */
  if (doc.readyState === 'complete') boot(); else doc.addEventListener('DOMContentLoaded', boot);
})();
