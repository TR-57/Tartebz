/* Tartebz — page renderers. Each one is re-run when the language or country changes. */
(function () {
  var TZ = window.TZ;
  var doc = document;
  var t = function (k, v) { return TZ.t(k, v); };
  var e = function (s) { return TZ.esc(s); };
  function $(s, r) { return (r || doc).querySelector(s); }
  function set(sel, html) { var el = $(sel); if (el) el.innerHTML = html; return el; }
  function byOrder(a, b) { return a.order - b.order; }
  function inCat(p, cat) { return !cat || cat === 'all' || p.cats.indexOf(cat) > -1; }
  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  function store(k, v) {
    try {
      if (arguments.length > 1) { localStorage.setItem('tz_' + k, JSON.stringify(v)); return v; }
      return JSON.parse(localStorage.getItem('tz_' + k) || 'null');
    } catch (err) { return null; }
  }

  /* "Tell me when it's back" – email or WhatsApp number, saved on the server (Admin › Products shows who is waiting). */
  function notifyForm(p, asked) {
    if (asked) {
      return '<div class="notify is-done" id="notify">' + TZ.icon('bell') + '<div><b>' + e(t('notify_title')) + '</b><p>' + e(t('notify_done')) + '</p></div></div>';
    }
    return '<form class="notify" id="notify" novalidate data-id="' + p.id + '">' +
      '<h3>' + TZ.icon('bell') + e(t('notify_title')) + '</h3><p>' + e(t('notify_text')) + '</p>' +
      '<div class="notify-kind" role="radiogroup" aria-label="' + e(t('notify_title')) + '">' +
      '<label><input type="radio" name="kind" value="phone" checked><span>' + TZ.brandIcon('whatsapp') + e(t('notify_phone')) + '</span></label>' +
      '<label><input type="radio" name="kind" value="email"><span>' + TZ.icon('mail2') + e(t('notify_email')) + '</span></label></div>' +
      '<div class="notify-row"><label class="sr-only" for="notify-input">' + e(t('notify_phone')) + '</label>' +
      '<input id="notify-input" name="contact" type="tel" inputmode="tel" autocomplete="tel" dir="ltr" placeholder="' + e(t('notify_ph_phone')) + '">' +
      '<button class="btn btn-primary" type="submit">' + e(t('notify_btn')) + '</button></div>' +
      '<p class="notify-msg" role="status" aria-live="polite"></p></form>';
  }
  function bindNotify(root, p) {
    var f = root.querySelector('form.notify');
    if (!f) return;
    var input = f.querySelector('#notify-input'), msg = f.querySelector('.notify-msg');
    f.addEventListener('change', function (ev) {
      if (ev.target.name !== 'kind') return;
      var mail = ev.target.value === 'email';
      input.type = mail ? 'email' : 'tel'; input.inputMode = mail ? 'email' : 'tel'; input.autocomplete = mail ? 'email' : 'tel';
      input.placeholder = t(mail ? 'notify_ph_email' : 'notify_ph_phone');
      f.querySelector('label[for="notify-input"]').textContent = t(mail ? 'notify_email' : 'notify_phone');
      input.focus();
    });
    f.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var mail = f.elements.kind.value === 'email', v = input.value.trim();
      var ok = mail ? /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v) : v.replace(/\D/g, '').length >= 8;
      if (!ok) { msg.className = 'notify-msg err'; msg.textContent = t('notify_err'); input.setAttribute('aria-invalid', 'true'); input.focus(); return; }
      input.removeAttribute('aria-invalid');
      var btn = f.querySelector('button[type=submit]'); btn.disabled = true;
      var body = { product: p.id, lang: TZ.lang(), country: TZ.country().code };
      body[mail ? 'email' : 'phone'] = v;
      fetch('/api/stock-alerts', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(body) })
        .then(function (r) { return r.json().then(function (d) { return { status: r.status, d: d }; }); })
        .then(function (res) {
          btn.disabled = false;
          if (res.d && res.d.ok) {
            store('notify_' + p.id, 1);
            f.outerHTML = notifyForm(p, true);
            return;
          }
          msg.className = 'notify-msg err';
          msg.textContent = t(res.status === 409 ? 'notify_instock' : res.status === 429 ? 'notify_wait' : 'notify_err');
        })
        .catch(function () { btn.disabled = false; msg.className = 'notify-msg err'; msg.textContent = t('notify_offline'); });
    });
  }

  /* Numbers count up when they scroll into view */
  function countUp(root) {
    var els = (root || doc).querySelectorAll('[data-count]');
    if (!('IntersectionObserver' in window) || reduced) {
      els.forEach(function (el) { el.textContent = el.getAttribute('data-prefix') + el.getAttribute('data-count'); });
      return;
    }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (!en.isIntersecting) return;
        io.unobserve(en.target);
        var el = en.target, end = +el.getAttribute('data-count'), pre = el.getAttribute('data-prefix') || '', t0 = performance.now();
        (function tick(now) {
          var k = Math.min(1, (now - t0) / 1400), v = Math.round(end * (1 - Math.pow(1 - k, 3)));
          el.textContent = pre + v.toLocaleString('en-US');
          if (k < 1) requestAnimationFrame(tick);
        })(t0);
      });
    }, { threshold: 0.4 });
    els.forEach(function (el) { el.textContent = (el.getAttribute('data-prefix') || '') + '0'; io.observe(el); });
  }

  var catColors = {
    'best-sellers': ['#FFD700', '#FF8A00'],
    'sweet-sour': ['#FF4D9D', '#E30613'],
    'electric-sour': ['#2B2F8F', '#141652'],
    'mixed': ['#E30613', '#991B1B']
  };
  var catArt = { 'best-sellers': 'tartebz-fruits', 'sweet-sour': 'marshmallow-red-berry', 'electric-sour': 'electric-sour-strips', 'mixed': 'tartebz-fruits-mango' };

  /* The moving strip ("🚚 Delivery to all Gulf countries · ⚡ Electric sour · …"). */
  function marquee() {
    var items = t('marquee').map(function (m) { return '<span>' + e(m) + '</span>' + TZ.icon('sparkle', 'mq-star'); }).join('');
    return '<div class="marquee-track"><div class="mq-group">' + items + '</div><div class="mq-group" aria-hidden="true">' + items + '</div></div>';
  }

  /* Home: slide from sweet to electric – the products and the colours follow. */
  var SP_COLORS = [['#FFF4B8', '#FFE066'], ['#FFE680', '#FFB347'], ['#FFC266', '#FF6B4A'], ['#FF7A59', '#E30613'], ['#2B2F8F', '#00C2FF']];
  var SP_EMOJI = ['🍬', '🍭', '🍋', '🔥', '⚡'];
  function sourPicker() {
    var box = $('#sp-control'), grid = $('#sp-grid'), sec = $('#sour-pick');
    if (!box || !grid) return;
    var level = sec._level || 3;
    box.innerHTML = '<div class="sp-scale"><span class="sp-end">' + e(t('sweet')) + '</span>' +
      '<input type="range" id="sp-range" min="1" max="5" step="1" value="' + level + '" aria-label="' + e(t('sour_meter')) + '">' +
      '<span class="sp-end">' + e(t('electric')) + '</span></div>' +
      '<div class="sp-level" aria-live="polite"><span class="sp-emoji" aria-hidden="true"></span><b></b><small></small></div>';
    var range = $('#sp-range'), lvl = box.querySelector('.sp-level');
    function show(v, animate) {
      sec._level = v;
      var col = SP_COLORS[v - 1];
      sec.style.setProperty('--sp1', col[0]); sec.style.setProperty('--sp2', col[1]);
      sec.classList.toggle('is-dark', v >= 4);
      sec.classList.toggle('is-electric', v === 5);
      range.style.setProperty('--v', (v - 1) / 4);
      range.setAttribute('aria-valuetext', t('sour_' + v));
      lvl.querySelector('.sp-emoji').textContent = SP_EMOJI[v - 1];
      lvl.querySelector('b').textContent = t('sour_' + v);
      var list = TZ.products.filter(function (p) { return p.sour === v; });
      var near = false;
      if (!list.length) {
        near = true;
        var best = Math.min.apply(null, TZ.products.filter(function (p) { return p.sour; }).map(function (p) { return Math.abs(p.sour - v); }));
        list = TZ.products.filter(function (p) { return p.sour && Math.abs(p.sour - v) === best; });
      }
      list.sort(function (a, b) { return (b.inStock - a.inStock) || byOrder(a, b); });
      lvl.querySelector('small').textContent = near ? t('sour_pick_none') : t('results', { n: list.length });
      grid.innerHTML = list.slice(0, 4).map(TZ.card).join('');
      if (animate && !reduced) grid.querySelectorAll('.pcard').forEach(function (c, i) { c.classList.add('in', 'sp-pop'); c.style.setProperty('--d', i * 60 + 'ms'); });
      TZ.reveal(grid);
      // The slider is on screen: fill the new meters now rather than waiting for a scroll.
      if (animate) requestAnimationFrame(function () { requestAnimationFrame(function () { grid.querySelectorAll('.sour').forEach(function (m) { m.classList.add('in'); }); }); });
      if (animate && v === 5 && !reduced) { sec.classList.remove('zap'); void sec.offsetWidth; sec.classList.add('zap'); }
    }
    range.addEventListener('input', function () { show(+range.value, true); });
    show(level, false);
  }

  /* Home hero: the packs drift and tilt as you scroll, and follow the phone's tilt where the browser allows it. */
  var tiltBound = false;
  function heroTilt() {
    if (reduced || tiltBound) return;
    tiltBound = true;
    var tx = 0, ty = 0, raf = 0;
    function paint() {
      raf = 0;
      var hero = $('.hero-visual'); if (!hero) return;
      var y = Math.min(window.scrollY, 900);
      hero.style.setProperty('--sy', (y * 0.12).toFixed(1) + 'px');
      hero.style.setProperty('--sr', (y * 0.02).toFixed(2) + 'deg');
      hero.style.setProperty('--tx', tx.toFixed(1) + 'px');
      hero.style.setProperty('--ty', ty.toFixed(1) + 'px');
    }
    function ask() { if (!raf) raf = requestAnimationFrame(paint); }
    window.addEventListener('scroll', ask, { passive: true });
    window.addEventListener('pointermove', function (ev) {
      if (ev.pointerType !== 'mouse') return;
      tx = (ev.clientX / window.innerWidth - 0.5) * 24; ty = (ev.clientY / window.innerHeight - 0.5) * 18; ask();
    }, { passive: true });
    // Android browsers give the phone's tilt without asking; iPhones need a permission prompt, so they only get the scroll effect.
    if ('DeviceOrientationEvent' in window && typeof DeviceOrientationEvent.requestPermission !== 'function') {
      window.addEventListener('deviceorientation', function (ev) {
        if (ev.gamma == null) return;
        tx = Math.max(-1, Math.min(1, ev.gamma / 30)) * 14; ty = Math.max(-1, Math.min(1, (ev.beta - 45) / 30)) * 10; ask();
      }, { passive: true });
    }
    paint();
  }


  /* ---------- checkout helpers ---------- */
  function api(url, body) {
    return fetch(url, { method: body ? 'POST' : 'GET', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: body ? JSON.stringify(body) : undefined })
      .then(function (r) { return r.json().then(function (d) { return { status: r.status, d: d }; }, function () { return { status: r.status, d: {} }; }); })
      .catch(function () { return { status: 0, d: { ok: false, error: { code: 'offline' } } }; });
  }
  function cartItems() { return TZ.cart.lines().map(function (l) { return { id: l.p.id, qty: l.qty }; }); }
  /* The server's view of the cart wins: sold-out lines leave, quantities are capped at the stock left. */
  function syncCart(q) {
    var keep = {};
    q.lines.forEach(function (l) { keep[l.id] = l.qty; });
    TZ.cart.lines().forEach(function (l) {
      if (keep[l.p.id] == null) TZ.cart.remove(l.p.id); else if (keep[l.p.id] !== l.qty) TZ.cart.set(l.p.id, keep[l.p.id]);
    });
  }
  function chargeText(o) {
    var dec = ['BHD', 'KWD', 'OMR'].indexOf(o.chargeCurrency) > -1 ? 3 : 2;
    return o.chargeCurrency + ' ' + o.chargeAmount.toLocaleString('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec });
  }
  function resultCard(icon, cls, title, text, extra) {
    return '<div class="co-result ' + cls + '"><div class="co-result-ico">' + icon + '</div><h2>' + e(title) + '</h2><p>' + e(text) + '</p>' + (extra || '') + '</div>';
  }
  function orderLines(o) {
    return '<ul class="co-lines co-result-lines">' + o.items.map(function (i) {
      return '<li><span class="co-name">' + e(TZ.lang() === 'ar' && i.nameAr ? i.nameAr : i.name) + ' × ' + i.qty + '</span></li>';
    }).join('') + '</ul><dl class="totals"><div class="grand"><dt>' + e(t('co_total_paid')) + '</dt><dd dir="ltr">' + e(chargeText(o)) + '</dd></div></dl>';
  }
  function showWhatsapp(root, o, link) {
    root.innerHTML = resultCard(TZ.brandIcon('whatsapp'), 'is-wa', t('co_wa_title', { number: o.number }), t('co_wa_text'),
      orderLines(o) + '<a class="btn btn-whatsapp btn-lg btn-block" href="' + e(link) + '" target="_blank" rel="noopener">' + TZ.brandIcon('whatsapp') + e(t('co_go_wa')) + '</a>' +
      '<a class="btn-link" href="' + TZ.url('shop.html') + '">' + e(t('co_continue')) + '</a>');
  }
  /* "Pay now" / "Complete payment": opens Tap's page for the order (the same one if it is still open). */
  function bindResume(root, token) {
    var rs = $('#co-resume');
    rs.addEventListener('click', function () {
      rs.disabled = true;
      api('/api/orders/' + token + '/pay', {}).then(function (r2) {
        if (r2.d && r2.d.redirect) { location.href = r2.d.redirect; return; }
        if (r2.d && r2.d.paid) { orderResult(root, token); return; }
        rs.disabled = false; var m = $('#co-msg'); m.hidden = false;
        m.textContent = r2.d && r2.d.error && r2.d.error.code === 'changed' ? t('co_changed') : r2.status === 502 ? t('co_unavailable') : t('co_err');
      });
    });
  }
  /* Back from Tap (?order=…&tap_id=…): the server checks with Tap and says what happened. */
  function orderResult(root, token) {
    var tries = 0;
    root.innerHTML = resultCard('<span class="spinner"></span>', 'is-wait', t('co_pending_title'), t('co_pending_text'));
    (function poll() {
      api('/api/orders/' + token).then(function (res) {
        var o = res.d && res.d.order;
        if (!o) { root.innerHTML = resultCard(TZ.icon('search'), 'is-fail', t('co_not_found'), '', '<a class="btn btn-primary" href="' + TZ.url('shop.html') + '">' + e(t('back_to_shop')) + '</a>'); return; }
        var ribbon = o.testMode ? '<div class="test-ribbon" role="note">' + TZ.icon('bolt') + e(t('test_mode')) + '</div>' : '';
        if (o.paymentStatus !== 'unpaid' && o.paymentStatus !== 'failed' || o.status === 'cancelled') TZ.pending.clear(o.token);
        if (o.paymentStatus === 'paid') {
          TZ.cart.clear();
          var ship = o.awb ? '<p class="co-ship">' + TZ.icon('truck') + e(t('co_shipped', { awb: o.awb })) + ' <a class="btn-link" href="' + e(o.trackingUrl) + '" target="_blank" rel="noopener">' + e(t('co_track')) + '</a></p>' : '';
          var done = o.status === 'delivered' ? '<p>' + e(t('co_delivered')) + '</p>' : '';
          root.innerHTML = ribbon + resultCard(TZ.icon('check'), 'is-ok', t('co_paid_title', { name: o.firstName }), t('co_paid_text', { number: o.number }),
            ship + done + orderLines(o) + '<a class="btn btn-primary" href="' + TZ.url('shop.html') + '">' + e(t('co_continue')) + '</a>') +
            (reduced ? '' : '<div class="confetti" aria-hidden="true">' + new Array(18).join('<i></i>') + '</div>');
          return;
        }
        if (o.status === 'awaiting_whatsapp') { showWhatsapp(root, o, 'https://wa.me/' + ((TZ.shop && TZ.shop.whatsapp) || TZ.store.phoneRaw) + '?text=' + encodeURIComponent(t('wa_order_intro') + ' #' + o.number)); return; }
        if (o.status === 'cancelled' || o.status === 'refunded') { root.innerHTML = resultCard(TZ.icon('close'), 'is-fail', t('co_cancelled'), ''); return; }
        if (o.status === 'payment_failed') {
          root.innerHTML = ribbon + resultCard(TZ.icon('close'), 'is-fail', t('co_failed_title'), t('co_failed_text', { number: o.number }),
            orderLines(o) + (o.canRetry ? '<button class="btn btn-primary btn-lg btn-block" type="button" id="co-retry">' + TZ.icon('shield') + e(t('co_retry')) + '</button>' : '') +
            '<a class="btn btn-whatsapp btn-block" target="_blank" rel="noopener" href="https://wa.me/' + ((TZ.shop && TZ.shop.whatsapp) || TZ.store.phoneRaw) + '?text=' + encodeURIComponent(t('wa_order_intro') + ' #' + o.number) + '">' + TZ.brandIcon('whatsapp') + e(t('co_go_wa')) + '</a>' +
            '<p class="co-msg" id="co-msg" role="alert" hidden></p>');
          var rb = $('#co-retry');
          if (rb) rb.addEventListener('click', function () {
            rb.disabled = true;
            api('/api/orders/' + token + '/pay', {}).then(function (r2) {
              if (r2.d && r2.d.redirect) { location.href = r2.d.redirect; return; }
              rb.disabled = false; var m = $('#co-msg'); m.hidden = false; m.textContent = r2.status === 502 ? t('co_unavailable') : t('co_err');
            });
          });
          return;
        }
        // An order the team created with a payment link: nothing paid yet, so offer the payment straight away.
        if (o.status === 'pending_payment' && !o.hasCharge && o.canRetry) {
          root.innerHTML = ribbon + resultCard(TZ.icon('shield'), 'is-wait', t('co_pay_title', { number: o.number }), t('co_pay_text', { name: o.firstName }),
            orderLines(o) + '<button class="btn btn-primary btn-lg btn-block" type="button" id="co-resume">' + TZ.icon('shield') + e(t('co_go_pay')) + '</button>' +
            '<p class="co-msg" id="co-msg" role="alert" hidden></p>');
          bindResume(root, token);
          return;
        }
        // Still waiting for Tap: ask again for about a minute. Someone who left Tap's page can go back to it.
        if (!tries && o.canRetry) {
          root.innerHTML = ribbon + resultCard('<span class="spinner"></span>', 'is-wait', t('co_pending_title'), t('co_pending_text'),
            orderLines(o) + '<button class="btn btn-primary btn-lg btn-block" type="button" id="co-resume">' + TZ.icon('shield') + e(t('pending_pay')) + '</button>' +
            '<p class="co-msg" id="co-msg" role="alert" hidden></p>');
          bindResume(root, token);
        }
        tries++;
        if (tries < 20) { setTimeout(poll, 3000); return; }
        root.innerHTML = ribbon + resultCard('<span class="spinner"></span>', 'is-wait', t('co_pending_title'), t('co_pending_slow'), orderLines(o));
      });
    })();
  }

  TZ.pages = {
    /* ================= HOME ================= */
    home: function () {
      var sold = TZ.products.reduce(function (s, p) { return s + p.sold; }, 0);
      set('#hero-stats',
        '<li><span class="val" data-count="' + Math.floor(sold / 1000) * 1000 + '" data-prefix="+">+' + Math.floor(sold / 1000) * 1000 + '</span><span class="lbl">' + e(t('stat_sold')) + '</span></li>' +
        '<li><span class="val" data-count="' + TZ.countries.length + '" data-prefix="">' + TZ.countries.length + '</span><span class="lbl">' + e(t('stat_countries')) + '</span></li>' +
        '<li><span class="val">' + TZ.fruitIcon() + '</span><span class="lbl">' + e(t('stat_flavors')) + '</span></li>');
      countUp($('#hero-stats'));

      set('#hero-logo', TZ.logoSVG());
      var packs = [TZ.find('tartebz-fruits'), TZ.find('electric-sour-strips')].concat(TZ.suggestions(2)).filter(Boolean);
      set('#hero-pack-1', packs[0] ? TZ.productArt(packs[0]) : '');
      set('#hero-pack-2', packs[1] && packs[1] !== packs[0] ? TZ.productArt(packs[1]) : (packs[2] ? TZ.productArt(packs[2]) : ''));

      set('#marquee', marquee());
      sourPicker();
      heroTilt();

      set('#cat-grid', TZ.categories.map(function (c, i) {
        var n = TZ.products.filter(function (p) { return inCat(p, c.id); }).length;
        var col = catColors[c.id] || ['#E30613', '#B30000'];
        return '<a class="cat-card reveal" style="--c1:' + col[0] + ';--c2:' + col[1] + ';--d:' + i * 90 + 'ms" href="' + TZ.url('shop.html?cat=' + c.id) + '">' +
          '<span class="cat-ico">' + TZ.icon(c.icon) + '</span>' +
          '<span class="cat-text"><b>' + e(TZ.L(c.name)) + '</b><small>' + e(TZ.L(c.desc)) + '</small><em>' + e(t('cats_count', { n: n })) + '</em></span>' +
          '<span class="cat-art">' + (function () {
            var cp = TZ.find(catArt[c.id]) || TZ.products.filter(function (p) { return inCat(p, c.id); })[0];
            return cp ? TZ.productArt(cp) : '';
          })() + '</span>' +
          '<span class="cat-go">' + TZ.icon('arrow', 'flip-rtl') + '</span></a>';
      }).join(''));

      var bs = TZ.products.filter(function (p) { return p.bestSeller; }).sort(byOrder);
      set('#bs-grid', bs.map(TZ.card).join(''));

      set('#ship-list', TZ.countries.map(function (c, i) {
        var flat = c.ship === 'flat';
        return '<li class="ship-item reveal" style="--d:' + i * 60 + 'ms"><span class="ship-flag">' + c.flag + '</span><span class="ship-name">' + e(TZ.L(c.name)) + '</span>' +
          '<span class="ship-cost"><small>' + e(flat ? t('ship_flat') : t('ship_from')) + '</small>' + e(TZ.money(flat ? TZ.shipping.flatBH : TZ.shipping.ubex[0][1], c)) + '</span></li>';
      }).join(''));
    },

    /* ================= SHOP ================= */
    shop: function () {
      var root = $('#shop-root'); if (!root) return;
      var q = new URLSearchParams(location.search);
      var st = root._state || (root._state = { cat: q.get('cat') || 'all', q: q.get('q') || '', sort: q.get('sort') || 'default', stock: q.get('stock') === '1' });
      if (st.cat !== 'all' && !TZ.cat(st.cat)) st.cat = 'all';

      var chips = [{ id: 'all', name: { ar: t('filter_all'), en: t('filter_all') } }].concat(TZ.categories).map(function (c) {
        var n = TZ.products.filter(function (p) { return inCat(p, c.id); }).length;
        return '<button type="button" class="chip' + (st.cat === c.id ? ' is-active' : '') + '" data-cat="' + c.id + '" aria-pressed="' + (st.cat === c.id) + '">' +
          (c.icon ? TZ.icon(c.icon) : '') + e(TZ.L(c.name)) + '<span class="chip-n">' + n + '</span></button>';
      }).join('');
      var sorts = ['default', 'best', 'price_asc', 'price_desc', 'sour'].map(function (s) {
        return '<option value="' + s + '"' + (st.sort === s ? ' selected' : '') + '>' + e(t('sort_' + s)) + '</option>';
      }).join('');
      var cat = TZ.cat(st.cat);

      root.innerHTML =
        '<section class="page-hero"><div class="hero-decor"><span class="deco deco-1"></span><span class="deco deco-2"></span></div>' +
        '<div class="container page-hero-inner"><nav class="crumbs" aria-label="breadcrumb"><a href="' + TZ.url('index.html') + '">' + e(t('home')) + '</a>' + TZ.icon('chevron', 'flip-rtl') + '<span>' + e(t('shop_title')) + '</span></nav>' +
        '<h1 id="shop-h1">' + e(cat ? TZ.L(cat.name) : t('shop_title')) + '</h1><p id="shop-sub">' + e(cat ? TZ.L(cat.desc) : t('shop_text')) + '</p></div>' +
        '<svg class="hero-wave" viewBox="0 0 1440 60" preserveAspectRatio="none" aria-hidden="true"><path d="M0 30 Q 360 0 720 30 T 1440 30 V60 H0Z"/></svg></section>' +
        '<div class="marquee marquee-slim" aria-hidden="true">' + marquee() + '</div>' +
        '<div class="shop-bar"><div class="container">' +
        '<div class="chips" role="group" aria-label="' + e(t('footer_cats')) + '">' + chips + '</div>' +
        '<div class="shop-tools">' +
        '<label class="search"><span class="sr-only">' + e(t('search_label')) + '</span>' + TZ.icon('search') + '<input type="search" id="shop-q" value="' + e(st.q) + '" placeholder="' + e(t('search_ph')) + '" autocomplete="off"></label>' +
        '<label class="switch"><input type="checkbox" id="shop-stock"' + (st.stock ? ' checked' : '') + '><span class="switch-ui" aria-hidden="true"></span><span>' + e(t('instock_only')) + '</span></label>' +
        '<label class="sort"><span>' + e(t('sort_label')) + '</span><span class="select-wrap"><select id="shop-sort">' + sorts + '</select>' + TZ.icon('chevron', 'chev') + '</span></label>' +
        '</div></div></div>' +
        '<section class="section shop-section"><div class="container">' +
        '<div class="shop-meta"><span id="shop-count" aria-live="polite"></span>' + TZ.curPicker('cur-pick-sm') + '</div>' +
        '<div class="grid products-grid" id="shop-grid"></div></div></section>' +
        (function () {
          var rv = TZ.recent.list().slice(0, 4);
          return rv.length ? '<section class="section recent-sec section-cream"><div class="container"><div class="section-head reveal"><span class="kicker">' + TZ.icon('clock') + e(t('recent_kicker')) + '</span><h2>' + e(t('recent_title')) + '</h2></div>' +
            '<div class="grid products-grid">' + rv.map(TZ.card).join('') + '</div></div></section>' : '';
        })();

      function grid() {
        var term = st.q.trim().toLowerCase();
        var list = TZ.products.filter(function (p) {
          if (!inCat(p, st.cat)) return false;
          if (st.stock && !p.inStock) return false;
          if (term && (p.name.ar + ' ' + p.name.en + ' ' + p.flavor.ar + ' ' + p.flavor.en).toLowerCase().indexOf(term) === -1) return false;
          return true;
        });
        var sorters = {
          'default': function (a, b) { return (b.inStock - a.inStock) || byOrder(a, b); },
          best: function (a, b) { return b.sold - a.sold; },
          price_asc: function (a, b) { return a.price - b.price || byOrder(a, b); },
          price_desc: function (a, b) { return b.price - a.price || byOrder(a, b); },
          sour: function (a, b) { return b.sour - a.sour || byOrder(a, b); }
        };
        list.sort(sorters[st.sort] || sorters['default']);
        $('#shop-count').textContent = t('results', { n: list.length });
        var g = $('#shop-grid');
        g.innerHTML = list.length ? list.map(TZ.card).join('') :
          '<div class="empty-state"><div class="empty-ico">' + TZ.icon('search') + '</div><h2>' + e(t('no_results')) + '</h2><p>' + e(t('no_results_text')) + '</p>' +
          '<button class="btn btn-primary" type="button" data-reset>' + e(t('reset_filters')) + '</button></div>';
        TZ.reveal(g);
        var u = new URL(location.href);
        [['cat', st.cat !== 'all' ? st.cat : ''], ['q', st.q], ['sort', st.sort !== 'default' ? st.sort : ''], ['stock', st.stock ? '1' : '']].forEach(function (kv) {
          if (kv[1]) u.searchParams.set(kv[0], kv[1]); else u.searchParams.delete(kv[0]);
        });
        history.replaceState(null, '', u.toString());
      }
      root._grid = grid;
      grid();

      if (!root._bound) {
        root._bound = true;
        root.addEventListener('click', function (ev) {
          var chip = ev.target.closest('[data-cat]');
          if (chip) { root._state.cat = chip.getAttribute('data-cat'); TZ.pages.shop(); TZ.applyI18n(root); return; }
          if (ev.target.closest('[data-reset]')) { root._state = { cat: 'all', q: '', sort: 'default', stock: false }; TZ.pages.shop(); }
        });
        root.addEventListener('input', function (ev) {
          if (ev.target.id === 'shop-q') { root._state.q = ev.target.value; root._grid(); }
        });
        root.addEventListener('change', function (ev) {
          if (ev.target.id === 'shop-sort') { root._state.sort = ev.target.value; root._grid(); }
          if (ev.target.id === 'shop-stock') { root._state.stock = ev.target.checked; root._grid(); }
        });
      }
    },

    /* ================= PRODUCT ================= */
    product: function () {
      var root = $('#product-root'); if (!root) return;
      // /product/<slug> (shared links, Google) or the old ?p=<slug>
      var m = location.pathname.match(/^\/product\/(.+?)\/?$/);
      var slug = m ? decodeURIComponent(m[1]) : new URLSearchParams(location.search).get('p');
      var p = TZ.find(slug);
      if (!p) {
        root.innerHTML = '<section class="section"><div class="container empty-state"><div class="empty-ico">' + TZ.icon('search') + '</div><h1>' + e(t('not_found')) + '</h1>' +
          '<a class="btn btn-primary" href="' + TZ.url('shop.html') + '">' + e(t('back_to_shop')) + '</a></div></section>';
        return;
      }
      if (!m) history.replaceState(null, '', TZ.productUrl(p) + location.hash);
      var name = TZ.L(p.name), cat = TZ.mainCat(p), c = TZ.country();
      var qty = root._qty && root._pid === p.id ? root._qty : 1;
      if (root._pid !== p.id) TZ.recent.add(p.id);
      root._pid = p.id; root._qty = qty;
      doc.title = name + ' | ' + TZ.L(TZ.store.name);

      var shipRows = TZ.countries.map(function (cc) {
        return '<tr' + (cc.code === c.code ? ' class="is-current"' : '') + '><td>' + cc.flag + ' ' + e(TZ.L(cc.name)) + '</td><td>' + e(TZ.money(TZ.shipCost(p.weight, cc), cc)) + '</td>' +
          '<td class="muted">' + e(TZ.delivery(cc)) + '</td></tr>';
      }).join('');
      var related = TZ.products.filter(function (x) { return x.id !== p.id && x.cats.some(function (k) { return p.cats.indexOf(k) > -1 && k !== 'best-sellers'; }); })
        .sort(function (a, b) { return (b.inStock - a.inStock) || byOrder(a, b); }).slice(0, 4);
      if (related.length < 4) related = related.concat(TZ.products.filter(function (x) { return x.id !== p.id && related.indexOf(x) === -1; }).sort(byOrder).slice(0, 4 - related.length));
      var recent = TZ.recent.list(p.id).filter(function (x) { return related.indexOf(x) === -1; }).slice(0, 4);

      var asked = store('notify_' + p.id);
      var buy = p.inStock ?
        '<div class="buy-row"><div class="qty qty-lg" role="group" aria-label="' + e(t('qty')) + '">' +
        '<button type="button" data-pqty="-1" aria-label="' + e(t('decrease')) + '">' + TZ.icon('minus') + '</button>' +
        '<output id="pd-qty" aria-live="polite">' + qty + '</output>' +
        '<button type="button" data-pqty="1" aria-label="' + e(t('increase')) + '">' + TZ.icon('plus') + '</button></div>' +
        '<button class="btn btn-primary btn-lg btn-add-lg" id="pd-add" type="button" data-add="' + p.id + '" data-qty-val="' + qty + '">' + TZ.icon('bag') + e(t('add_to_cart')) + '</button></div>' :
        p.inCarts ? '<p class="pd-held">' + TZ.icon('clock') + ' ' + e(t('in_carts_long')) + '</p>' :
        (TZ.shop.backInStock ? notifyForm(p, asked) : '<div class="buy-row"><button class="btn btn-disabled btn-lg btn-block" type="button" disabled>' + e(t('sold_out')) + '</button></div>');
      var tags = p.cats.filter(function (k) { return k !== 'best-sellers'; }).map(function (k) { var cc = TZ.cat(k); return cc ? '<a class="tag" href="' + TZ.url('shop.html?cat=' + k) + '">' + e(TZ.L(cc.name)) + '</a>' : ''; }).join('');

      root.innerHTML =
        '<section class="pd" style="--c1:' + p.art.colors[0] + ';--c2:' + p.art.colors[1] + '"><div class="container">' +
        '<nav class="crumbs crumbs-dark" aria-label="breadcrumb"><a href="' + TZ.url('index.html') + '">' + e(t('home')) + '</a>' + TZ.icon('chevron', 'flip-rtl') +
        '<a href="' + TZ.url('shop.html') + '">' + e(t('nav_shop')) + '</a>' + TZ.icon('chevron', 'flip-rtl') +
        (cat ? '<a href="' + TZ.url('shop.html?cat=' + cat.id) + '">' + e(TZ.L(cat.name)) + '</a>' + TZ.icon('chevron', 'flip-rtl') : '') + '<span>' + e(name) + '</span></nav>' +
        '<div class="pd-grid">' +
        '<div class="pd-gallery" data-id="' + p.id + '"><div class="pd-stage">' + TZ.badges(p, true) +
        '<div class="pd-blob"></div><div class="pd-ring"></div>' + TZ.productArt(p, 'pd-art') +
        '<span class="pd-spark s1">' + TZ.icon('sparkle') + '</span><span class="pd-spark s2">' + TZ.icon('sparkle') + '</span><span class="pd-spark s3">' + TZ.icon('star') + '</span></div>' +
        (p.images && p.images.length > 1 ? '<div class="pd-thumbs" role="group" aria-label="' + e(name) + '">' + p.images.map(function (im, i) {
          return '<button type="button" class="pd-thumb" data-photo="' + i + '" aria-pressed="' + (i === 0) + '"><img src="' + e(im.small) + '" alt="" loading="lazy"></button>';
        }).join('') + '</div>' : '') + '</div>' +
        '<div class="pd-info">' +
        '<h1 class="pd-title">' + e(name) + '</h1>' +
        (p.flavor && TZ.L(p.flavor) ? '<p class="pd-flavor">' + e(t('flavor')) + ': <b>' + e(TZ.L(p.flavor)) + '</b></p>' : '') +
        '<div class="pd-price-row"><span class="pd-price">' + e(TZ.money(p.price)) + '</span>' +
        (p.regularPrice > p.price ? '<s class="pd-was">' + e(TZ.money(p.regularPrice)) + '</s>' : '') +
        TZ.stockBadge(p) + '</div>' +
        '<div class="pd-cur">' + TZ.curPicker() + (p.inStock ? TZ.deliveryChip() : '') + '</div>' +
        buy +
        (p.inStock ? '<div class="pd-pay"><span>' + e(t('pay_with')) + '</span>' + TZ.payLogos() + '</div>' : '') +
        TZ.sourMeter(p.sour, true) +
        '<p class="pd-desc">' + e(TZ.L(p.desc)) + '</p>' +
        '<div class="pd-ship">' + TZ.icon('truck') + '<div><b>' + e(t('ship_to_label')) + ' ' + c.flag + ' ' + e(TZ.L(c.name)) + ': ' + e(TZ.money(TZ.shipCost(p.weight))) + '</b>' +
        '<small>' + e(c.ship === 'flat' ? t('cart_ship_note_flat') : t('cart_ship_note_ubex', { w: p.weight })) + '</small></div></div>' +
        '<ul class="pd-perks"><li>' + TZ.icon('shield') + e(t('secure_pay')) + '</li><li>' + TZ.icon('truck') + e(t('gcc_delivery')) + '</li><li>' + TZ.icon('leaf') + e(t('natural')) + '</li></ul>' +
        (tags ? '<div class="pd-cats">' + tags + '</div>' : '') +
        '</div></div>' +
        '<div class="tabs" id="pd-tabs"><div class="tab-list" role="tablist">' +
        '<button role="tab" id="tab-a" aria-controls="panel-a" aria-selected="true" type="button">' + e(t('tab_desc')) + '</button>' +
        '<button role="tab" id="tab-b" aria-controls="panel-b" aria-selected="false" tabindex="-1" type="button">' + e(t('tab_ship')) + '</button></div>' +
        '<div class="tab-panel" role="tabpanel" id="panel-a" aria-labelledby="tab-a"><p>' + e(TZ.L(p.desc)) + '</p>' +
        '<dl class="spec">' + (TZ.L(p.flavor) ? '<div><dt>' + e(t('flavor')) + '</dt><dd>' + e(TZ.L(p.flavor)) + '</dd></div>' : '') +
        (p.sour ? '<div><dt>' + e(t('sour_meter')) + '</dt><dd>' + e(t('sour_' + p.sour)) + ' (' + p.sour + '/5)</dd></div>' : '') +
        '<div><dt>' + e(t('weight')) + '</dt><dd>' + p.weight + ' ' + e(t('kg')) + '</dd></div></dl></div>' +
        '<div class="tab-panel" role="tabpanel" id="panel-b" aria-labelledby="tab-b" hidden><p>' + e(t('ship_tab_text')) + '</p><table class="ship-table"><tbody>' + shipRows + '</tbody></table></div></div>' +
        '</div></section>' +
        '<section class="section section-cream"><div class="container"><div class="section-head reveal"><span class="kicker">' + TZ.icon('sparkle') + e(t('cats_kicker')) + '</span><h2>' + e(t('related')) + '</h2></div>' +
        '<div class="grid products-grid">' + related.map(TZ.card).join('') + '</div></div></section>' +
        (recent.length ? '<section class="section recent-sec"><div class="container"><div class="section-head reveal"><span class="kicker">' + TZ.icon('clock') + e(t('recent_kicker')) + '</span><h2>' + e(t('recent_title')) + '</h2></div>' +
          '<div class="grid products-grid">' + recent.map(TZ.card).join('') + '</div></div></section>' : '') +
        (p.inStock ? '<div class="buybar" id="buybar"><div class="buybar-info">' + TZ.productArt(p, 'buybar-art') + '<span><b>' + e(name) + '</b><em>' + e(TZ.money(p.price)) + '</em></span></div>' +
          '<button class="btn btn-primary" type="button" data-add="' + p.id + '" data-qty-val="' + qty + '">' + TZ.icon('bag') + e(t('add_to_cart')) + '</button></div>' : '');

      /* Sticky buy bar appears once the main button scrolls away */
      var addBtn = $('#pd-add'), bar = $('#buybar');
      doc.body.classList.remove('has-buybar');
      if (addBtn && bar && 'IntersectionObserver' in window) {
        if (root._io) root._io.disconnect();
        root._io = new IntersectionObserver(function (en) {
          var show = !en[0].isIntersecting && en[0].boundingClientRect.top < 0;
          bar.classList.toggle('show', show); doc.body.classList.toggle('has-buybar', show);
        });
        root._io.observe(addBtn);
      }
      bindNotify(root, p);
      if (location.hash === '#notify') { var nf = $('#notify'); if (nf) { nf.scrollIntoView({ block: 'center' }); var ni = $('#notify-input'); if (ni) ni.focus({ preventScroll: true }); } }

      if (!root._bound) {
        root._bound = true;
        root.addEventListener('click', function (ev) {
          var q = ev.target.closest('[data-pqty]');
          if (q) {
            var qp = TZ.find(root.querySelector('.pd-gallery').getAttribute('data-id'));
            var cap = qp && qp.maxQty != null ? Math.max(1, qp.maxQty) : 99;
            var want = root._qty + (+q.getAttribute('data-pqty'));
            if (want > cap) TZ.toast(t('hold_max', { n: cap }), 'cart');
            root._qty = Math.max(1, Math.min(cap, want));
            $('#pd-qty').textContent = root._qty;
            root.querySelectorAll('[data-qty-val]').forEach(function (b) { b.setAttribute('data-qty-val', root._qty); });
          }
          var tab = ev.target.closest('[role="tab"]');
          if (tab) selectTab(tab);
          var ph = ev.target.closest('[data-photo]');
          if (ph) {
            var prod = TZ.find(root.querySelector('.pd-gallery').getAttribute('data-id'));
            var cur = root.querySelector('.pd-art');
            if (prod && cur) {
              cur.outerHTML = TZ.photo(prod, +ph.getAttribute('data-photo'), 'pd-art');
              root.querySelectorAll('[data-photo]').forEach(function (b) { b.setAttribute('aria-pressed', String(b === ph)); });
            }
          }
        });
        root.addEventListener('keydown', function (ev) {
          var tab = ev.target.closest('[role="tab"]');
          if (!tab || (ev.key !== 'ArrowLeft' && ev.key !== 'ArrowRight')) return;
          var tabs = [].slice.call(root.querySelectorAll('[role="tab"]')), i = tabs.indexOf(tab);
          var next = tabs[(i + 1) % tabs.length]; selectTab(next); next.focus();
        });
      }
      /* 3D tilt on the product stage (stage is rebuilt each render, so bind directly) */
      var stage = $('.pd-stage', root);
      if (stage && !reduced) {
        stage.addEventListener('pointermove', function (ev) {
          if (ev.pointerType === 'touch') return;
          var r = stage.getBoundingClientRect(), x = (ev.clientX - r.left) / r.width - 0.5, y = (ev.clientY - r.top) / r.height - 0.5;
          stage.style.setProperty('--rx', (-y * 10).toFixed(2) + 'deg'); stage.style.setProperty('--ry', (x * 14).toFixed(2) + 'deg');
        });
        stage.addEventListener('pointerleave', function () { stage.style.setProperty('--rx', '0deg'); stage.style.setProperty('--ry', '0deg'); });
      }
      function selectTab(tab) {
        root.querySelectorAll('[role="tab"]').forEach(function (b) {
          var on = b === tab; b.setAttribute('aria-selected', on); b.tabIndex = on ? 0 : -1;
          $('#' + b.getAttribute('aria-controls')).hidden = !on;
        });
      }
    },

    /* ================= CHECKOUT ================= */
    checkout: function () {
      var root = $('#checkout-root'); if (!root) return;
      var token = new URLSearchParams(location.search).get('order');
      if (token && /^[a-f0-9]{40}$/.test(token)) { orderResult(root, token); return; }
      var memo = {};
      root.querySelectorAll('input, textarea').forEach(function (f) { if (f.name && f.type !== 'radio') memo[f.name] = f.value; });
      var chosen = (root.querySelector('input[name=pay]:checked') || {}).value;
      var lines = TZ.cart.lines(), c = TZ.country();

      if (!lines.length) {
        var sug = TZ.suggestions(4);
        root.innerHTML = '<div class="empty-state co-empty">' + TZ.icon('bag') + '<h2>' + e(t('cart_empty_title')) + '</h2><p>' + e(t('co_empty')) + '</p>' +
          '<a class="btn btn-primary" href="' + TZ.url('shop.html') + '">' + e(t('order_now')) + '</a></div>' +
          (sug.length ? '<div class="co-sugg"><div class="section-head"><span class="kicker">' + TZ.icon('star') + e(t('bs_kicker')) + '</span><h2>' + e(t('cart_suggest')) + '</h2></div>' +
            '<div class="grid products-grid">' + sug.map(TZ.card).join('') + '</div></div>' : '');
        TZ.reveal(root);
        return;
      }
      function field(name, label, opts) {
        opts = opts || {};
        var req = !opts.optional;
        return '<div class="field' + (opts.half ? ' half' : '') + '"><label for="f-' + name + '">' + e(label) + (req ? ' <abbr title="' + e(t('err_required')) + '">*</abbr>' : '') + '</label>' +
          (opts.textarea ? '<textarea id="f-' + name + '" name="' + name + '" rows="3"' + (opts.ph ? ' placeholder="' + e(opts.ph) + '"' : '') + '></textarea>' :
            '<input id="f-' + name + '" name="' + name + '" type="' + (opts.type || 'text') + '"' + (req ? ' required' : '') + (opts.ac ? ' autocomplete="' + opts.ac + '"' : '') +
            (opts.ph ? ' placeholder="' + e(opts.ph) + '"' : '') + (opts.dir ? ' dir="' + opts.dir + '"' : '') + '>') +
          '<span class="field-err" id="err-' + name + '"></span></div>';
      }
      var P = TZ.payments || {};
      var methods = [];
      if (P.tap) methods.push('tap');
      if (P.whatsapp || !methods.length) methods.push('whatsapp');
      if (methods.indexOf(chosen) === -1) chosen = methods[0];
      // What the card will be charged in: the customer's currency if the shop's Tap account takes it, else BHD.
      var w = Math.round(TZ.cart.weight() * 10) / 10;
      var payOpt = function (m) {
        var tap = m === 'tap';
        return '<label class="pay-option' + (chosen === m ? ' is-selected' : '') + '"><input type="radio" name="pay" value="' + m + '"' + (chosen === m ? ' checked' : '') + '><span class="pay-dot"></span>' +
          '<span><b>' + e(t(tap ? 'pay_tap_title' : 'pay_wa_title')) + '</b><small>' + e(tap ? t('pay_gatee') + ' · ' + t('pay_note') : t('pay_wa_note')) + '</small></span>' +
          (tap ? TZ.payLogos() : '<span class="pay-wa">' + TZ.brandIcon('whatsapp') + '</span>') + '</label>';
      };
      root.innerHTML =
        (P.testMode ? '<div class="test-ribbon" role="note">' + TZ.icon('bolt') + e(t('test_mode')) + '</div>' : '') +
        '<form class="co-form" id="co-form" novalidate><div class="co-card"><h2>' + TZ.icon('pin') + e(t('co_billing')) + '</h2><div class="fields">' +
        field('first', t('f_first'), { half: true, ac: 'given-name' }) + field('last', t('f_last'), { half: true, ac: 'family-name' }) +
        '<div class="field"><label for="f-country">' + e(t('f_country')) + ' <abbr>*</abbr></label><span class="select-wrap"><select id="f-country" data-country>' + TZ.countryOptions() + '</select>' + TZ.icon('chevron', 'chev') + '</span></div>' +
        field('addr1', t('f_addr1'), { ph: t('f_addr1_ph'), ac: 'address-line1' }) +
        field('addr2', t('f_addr2'), { ph: t('f_addr2_ph'), optional: true, ac: 'address-line2' }) +
        field('city', t('f_city'), { half: true, ac: 'address-level2' }) + field('state', t('f_state'), { half: true, ac: 'address-level1', optional: true }) +
        field('phone', t('f_phone'), { half: true, type: 'tel', ac: 'tel', dir: 'ltr' }) + field('email', t('f_email'), { half: true, type: 'email', ac: 'email', dir: 'ltr' }) +
        field('notes', t('f_notes'), { optional: true, textarea: true }) +
        '</div></div>' +
        '<div class="co-card"><h2>' + TZ.icon('card') + e(t('co_payment')) + '</h2>' + methods.map(payOpt).join('') + '</div></form>' +
        '<aside class="co-summary"><div class="co-card sticky"><h2>' + TZ.icon('bag') + e(t('co_summary')) + '</h2>' + TZ.holdTimer() + '<ul class="co-lines">' +
        lines.map(function (l) {
          return '<li><span class="co-thumb">' + TZ.productArt(l.p) + '<i>' + l.qty + '</i></span><span class="co-name">' + e(TZ.L(l.p.name)) + '</span><b>' + e(TZ.money(l.p.price * l.qty)) + '</b></li>';
        }).join('') + '</ul>' +
        '<dl class="totals"><div><dt>' + e(t('cart_subtotal')) + '</dt><dd>' + e(TZ.money(TZ.cart.subtotal())) + '</dd></div>' +
        '<div><dt>' + e(t('cart_shipping')) + '<small>' + c.flag + ' ' + e(c.ship === 'flat' ? t('cart_ship_note_flat') : t('cart_ship_note_ubex', { w: w })) + '</small>' +
        (TZ.delivery(c) ? '<small class="co-eta">' + e(TZ.delivery(c)) + '</small>' : '') + '</dt><dd>' + e(TZ.money(TZ.cart.shipping())) + '</dd></div>' +
        '<div class="grand"><dt>' + e(t('cart_total')) + '</dt><dd>' + e(TZ.money(TZ.cart.total())) + '</dd></div></dl>' +
        '<p class="co-charge" id="co-charge" hidden></p>' +
        '<div class="co-msg" id="co-msg" role="alert" hidden></div>' +
        '<button class="btn btn-primary btn-block btn-lg" type="submit" form="co-form" id="place-order">' + TZ.icon(chosen === 'tap' ? 'shield' : 'arrow') + e(t(chosen === 'tap' ? 'co_go_pay' : 'co_go_wa')) + '</button>' +
        '<p class="co-secure">' + TZ.icon('shield') + e(chosen === 'tap' ? t('pay_note') : t('pay_wa_note')) + '</p></div></aside>';

      Object.keys(memo).forEach(function (k) { var f = root.querySelector('[name="' + k + '"]'); if (f) f.value = memo[k]; });

      var form = $('#co-form');
      form.addEventListener('change', function (ev) { if (ev.target.name === 'pay') TZ.pages.checkout(); });
      form.addEventListener('submit', submit);
      $('#place-order').addEventListener('click', function (ev) { ev.preventDefault(); submit(ev); });
      form.addEventListener('input', function (ev) {
        if (ev.target.getAttribute('aria-invalid') === 'true') { ev.target.setAttribute('aria-invalid', 'false'); var er = $('#err-' + ev.target.name); if (er) er.textContent = ''; }
      });

      // Tell the customer up front when the card is charged in BHD instead of their own currency.
      if (chosen === 'tap' && c.cur && c.cur !== 'BHD') {
        api('/api/checkout/quote', { items: cartItems(), country: c.code, cart: TZ.cart.key() }).then(function (res) {
          var q = res.d && res.d.quote; var box = $('#co-charge');
          if (q && box && q.chargeCurrency !== q.currency) {
            box.hidden = false;
            box.textContent = t('co_charged_in', { cur: q.chargeCurrency, amount: q.chargeCurrency + ' ' + q.chargeAmount.toFixed(3) });
          }
        });
      }

      var sending = false;
      function submit(ev) {
        if (ev && ev.preventDefault) ev.preventDefault();
        if (sending) return;
        var ok = true, first;
        ['first', 'last', 'addr1', 'city', 'phone', 'email'].forEach(function (n) {
          var f = form.elements[n], v = f.value.trim(), msg = '';
          if (!v) msg = t('err_required');
          else if (n === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v)) msg = t('err_email');
          else if (n === 'phone' && v.replace(/\D/g, '').length < 8) msg = t('err_phone');
          showErr(n, msg);
          if (msg && ok) { ok = false; first = f; }
        });
        if (!ok) { first.focus(); first.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'center' }); return; }
        var v = function (n) { return form.elements[n].value.trim(); };
        var btn = $('#place-order'); btn.disabled = true; btn.classList.add('is-busy'); sending = true;
        hideMsg();
        api('/api/checkout', {
          items: cartItems(), cart: TZ.cart.key(), country: TZ.country().code, method: chosen, lang: TZ.lang(), expected_total: Math.round(TZ.cart.total() * 1000) / 1000,
          first_name: v('first'), last_name: v('last'), email: v('email'), phone: v('phone'),
          address1: v('addr1'), address2: v('addr2'), city: v('city'), state: v('state'), notes: v('notes')
        }).then(function (res) {
          sending = false; btn.disabled = false; btn.classList.remove('is-busy');
          var d = res.d || {};
          if (d.ok && d.redirect) {
            btn.disabled = true;
            TZ.pending.set(d.order); // reminds them on any page if they leave the payment page without paying
            location.href = d.redirect; return;
          }
          if (d.ok && d.whatsapp) {
            TZ.cart.clear();
            history.replaceState(null, '', TZ.url('checkout.html?order=' + d.order.token));
            showWhatsapp(root, d.order, d.whatsapp);
            return;
          }
          var err = d.error || {};
          if (err.code === 'changed' && d.quote) { syncCart(d.quote); showMsg(t('co_changed')); TZ.pages.checkout(); showMsg(t('co_changed')); return; }
          if (err.fields) {
            var map = { first_name: 'first', last_name: 'last', address1: 'addr1', address2: 'addr2' };
            Object.keys(err.fields).forEach(function (k) { showErr(map[k] || k, err.fields[k]); });
            return;
          }
          showMsg(res.status === 502 ? t('co_unavailable') : (err.message && TZ.lang() === 'en' ? err.message : t('co_err')));
        });
      }
      function showErr(n, msg) {
        var f = form.elements[n], el = $('#err-' + n);
        if (!f || !el) return;
        el.textContent = msg;
        f.setAttribute('aria-invalid', msg ? 'true' : 'false');
        if (msg) f.setAttribute('aria-describedby', 'err-' + n); else f.removeAttribute('aria-describedby');
      }
      function showMsg(m) { var b = $('#co-msg'); if (b) { b.hidden = false; b.textContent = m; } }
      function hideMsg() { var b = $('#co-msg'); if (b) b.hidden = true; }
    },

    /* ================= CONTACT ================= */
    contact: function () {
      set('#c-email', '<a href="mailto:' + TZ.store.email + '">' + TZ.store.email + '</a>');
      set('#c-phone', '<a href="tel:+' + TZ.store.phoneRaw + '" dir="ltr">' + TZ.store.phone + '</a>');
      set('#c-wa', '<a href="https://wa.me/' + TZ.store.phoneRaw + '" target="_blank" rel="noopener" dir="ltr">' + TZ.store.phone + '</a>');
      set('#c-addr', e(TZ.L(TZ.store.address)) + ' <a class="btn-link" href="' + TZ.store.mapsUrl + '" target="_blank" rel="noopener">' + e(t('open_maps')) + '</a>');
      set('#c-cr', e(TZ.store.cr));
      set('#c-social', TZ.socialLinks());
      var form = $('#c-form');
      if (form && !form._bound) {
        form._bound = true;
        form.addEventListener('submit', function (ev) {
          ev.preventDefault();
          var n = form.elements.name.value.trim(), m = form.elements.message.value.trim();
          if (!m) { form.elements.message.setAttribute('aria-invalid', 'true'); form.elements.message.focus(); return; }
          window.open('https://wa.me/' + TZ.store.phoneRaw + '?text=' + encodeURIComponent((n ? n + ':\n' : '') + m), '_blank', 'noopener');
        });
      }
    },

    about: function () { countUp(doc); }
  };

  doc.addEventListener('tz:cart', function () {
    if (doc.body.getAttribute('data-page') === 'checkout' && !new URLSearchParams(location.search).get('order')) TZ.pages.checkout();
  });
})();
