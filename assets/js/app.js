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

  /* Keeps ?lang=en on internal links so shared URLs open in the same language. */
  TZ.url = function (href) {
    if (lang === 'ar') return href;
    return href + (href.indexOf('?') > -1 ? '&' : '?') + 'lang=en';
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
  TZ.productUrl = function (p) { return TZ.url('product.html?p=' + encodeURIComponent(p.slug)); };

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
    var segs = '';
    for (var i = 1; i <= 5; i++) {
      segs += '<span class="seg' + (i <= level ? ' on' : '') + '">' + (level === 5 && i <= level ? TZ.icon('bolt') : '') + '</span>';
    }
    return '<div class="sour' + (big ? ' sour-lg' : '') + (level === 5 ? ' is-electric' : '') + '" role="img" aria-label="' +
      TZ.esc(TZ.t('sour_meter') + ': ' + TZ.t('sour_' + level) + ' (' + level + '/5)') + '">' +
      '<span class="sour-label">' + (big ? TZ.esc(TZ.t('sour_meter')) + ' · ' : '') + '<b>' + TZ.esc(TZ.t('sour_' + level)) + '</b></span>' +
      '<span class="sour-bar">' + segs + '</span></div>';
  };

  TZ.badges = function (p) {
    return '<span class="badges">' +
      '<span class="badge badge-stock ' + (p.inStock ? 'in' : 'out') + '">' + TZ.esc(TZ.t(p.inStock ? 'in_stock' : 'out_of_stock')) + '</span>' +
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
        : '<span class="sold-out">' + TZ.esc(TZ.t('sold_out')) + '</span>') +
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
      '<div class="pay-row"><span>' + TZ.esc(TZ.t('footer_pay')) + '</span><span class="pay-chip">BenefitPay</span><span class="pay-chip">VISA</span><span class="pay-chip">Mastercard</span></div>' +
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
        '<a class="btn btn-primary" href="' + TZ.url('shop.html') + '">' + TZ.esc(TZ.t('order_now')) + '</a></div>';
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
      '<a class="btn btn-primary btn-block btn-lg" href="' + TZ.url('checkout.html') + '">' + TZ.esc(TZ.t('cart_checkout')) + TZ.icon('arrow', 'flip-rtl') + '</a>' +
      '<button class="btn-link" type="button" data-close-cart>' + TZ.esc(TZ.t('cart_continue')) + '</button></div>' : '';

    drawer.innerHTML =
      '<div class="cart-head"><h2>' + TZ.icon('bag') + TZ.esc(TZ.t('cart_title')) + (n ? ' <span class="pill-count">' + TZ.esc(TZ.t('cart_items', { n: n })) + '</span>' : '') + '</h2>' +
      '<button class="icon-btn" type="button" data-close-cart aria-label="' + TZ.esc(TZ.t('close')) + '">' + TZ.icon('close') + '</button></div>' +
      '<div class="cart-body">' + body + '</div>' + foot;
    updateCount();
  }

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
    var art = fromEl && (fromEl.closest('[data-id]') || doc).querySelector('.art');
    if (!art || reduced || !fab.offsetParent) { bump(); return; }
    var r = art.getBoundingClientRect(), t = fab.getBoundingClientRect();
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
    anim.onfinish = function () { clone.remove(); bump(); };
  };
  function bump() {
    [fab].concat([].slice.call(doc.querySelectorAll('.cart-btn'))).forEach(function (b) {
      if (!b) return; b.classList.remove('bump'); void b.offsetWidth; b.classList.add('bump');
    });
  }

  var toastTimer;
  function toast(msg) {
    toastEl.innerHTML = '<span class="toast-ico">' + TZ.icon('check') + '</span><span class="toast-msg">' + TZ.esc(msg) + '</span>' +
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
    (root || doc).querySelectorAll('.reveal:not(.in)').forEach(function (el) {
      if (!io || reduced) el.classList.add('in'); else io.observe(el);
    });
  };

  /* ---------- header scroll state ---------- */
  function onScroll() {
    var h = doc.getElementById('masthead');
    if (h) h.classList.toggle('scrolled', window.scrollY > 24);
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

  /* ---------- boot ---------- */
  function boot() {
    buildCartShell();
    TZ.rerender();
    inAppBanner();
    doc.body.classList.add('is-ready');
  }
  /* Deferred scripts run before DOMContentLoaded, so waiting for it guarantees pages.js is loaded. */
  if (doc.readyState === 'complete') boot(); else doc.addEventListener('DOMContentLoaded', boot);
})();
