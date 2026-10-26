/* Tartebz — live shop data.
   Reads /api/storefront (shop details, logo, currency rates, website settings, categories, products with photos).
   The last answer is kept in the browser, so the next page opens instantly. Only when the server cannot be reached
   and nothing is kept does the page load the built-in list (fallback.js).
   Prices always arrive in BHD; TZ.money() converts them with the rates set in the admin. */
(function () {
  var TZ = window.TZ;
  var KEY = 'tz_catalog_v1';
  var WAIT_MS = 2500; // first visit: how long the page waits for the server before using the built-in list

  TZ.payments = { tap: false, whatsapp: true, testMode: false };

  /* Website settings (Admin › Settings › Website); these defaults apply until the server answers. */
  TZ.shop = {
    whatsappOrders: true, whatsapp: TZ.store.phoneRaw, backInStock: true,
    paymentLogos: ['benefit', 'visa', 'mastercard'],
    delivery: [{ country: 'BH', min: 1, max: 1 }]
  };

  var DEFAULT_PACK = ['box', ['#E30613', '#9E0010'], ['#FFD700', '#FF8A00', '#FFFFFF'], 0];
  function dress(p) {
    var k = TZ.packs[p.id] || DEFAULT_PACK;
    p.art = p.art || { type: k[0], colors: k[1], pieces: k[2] };
    p.sold = p.sold || k[3] || 0; // sales on the old shop, until orders are counted here
    p.image = p.images && p.images[0] ? p.images[0].src : (p.image || '');
    p.images = p.images || [];
    return p;
  }

  function apply(d) {
    if (!d || !d.ok || !d.products) return false;
    var s = d.site || {};
    TZ.store.name = s.name || TZ.store.name;
    if (s.email) TZ.store.email = s.email;
    if (s.phone) { TZ.store.phone = s.phone; }
    if (s.whatsapp) TZ.store.phoneRaw = String(s.whatsapp).replace(/\D/g, '');
    if (s.cr) TZ.store.cr = s.cr;
    if (s.address && s.address.en) TZ.store.address = s.address;
    if (s.mapsUrl) TZ.store.mapsUrl = s.mapsUrl;
    if (s.social) {
      if (s.social.instagram) TZ.store.social.instagram = s.social.instagram;
      if (s.social.tiktok) TZ.store.social.tiktok = s.social.tiktok;
    }
    TZ.store.logo = s.logo ? s.logo.small : '';
    TZ.store.logoLarge = s.logo ? s.logo.medium : '';
    if (d.shipping) {
      // The server's delivery prices: what the cart shows is what checkout charges.
      TZ.shipping.flat = d.shipping.flat || {};
      TZ.shipping.ubex = d.shipping.table || TZ.shipping.ubex;
      TZ.shipping.extra = d.shipping.extraPerHalfKg;
      TZ.shipping.countries = d.shipping.countries || [];
      TZ.countries.forEach(function (c) { c.ship = TZ.shipping.flat[c.code] != null ? 'flat' : 'ubex'; });
    }
    if (d.payments) TZ.payments = d.payments;
    if (d.shop) {
      TZ.shop = d.shop;
      TZ.shop.whatsapp = String(d.shop.whatsapp || TZ.store.phoneRaw).replace(/\D/g, '');
    }

    /* Each country pays in the currency the admin linked it to; a country with none pays in BHD. */
    var cur = d.currencies || [];
    var base = cur.filter(function (c) { return c.code === d.baseCurrency; })[0];
    TZ.countries.forEach(function (c) {
      var m = cur.filter(function (x) { return (x.countries || []).indexOf(c.code) !== -1; })[0] || base;
      if (m) { c.cur = m.code; c.rate = m.rate; c.dec = m.dec; c.sym = m.sym; }
    });

    var products = d.products.map(dress);
    var cats = d.categories.slice();
    if (products.some(function (p) { return p.bestSeller; })) cats.unshift(TZ.bestCat);
    TZ.products = products;
    TZ.categories = cats;
    TZ.isLive = true;
    return true;
  }

  /* The built-in list, loaded only when needed. */
  function useFallback() {
    return new Promise(function (resolve) {
      if (TZ.products.length) { resolve(); return; }
      var done = function () {
        if (TZ.fallback && !TZ.products.length) {
          TZ.products = TZ.fallback.products.map(dress);
          TZ.categories = TZ.fallback.categories.slice();
        }
        resolve();
      };
      var sc = document.createElement('script');
      sc.src = '/assets/js/fallback.js';
      sc.onload = done; sc.onerror = done;
      document.head.appendChild(sc);
    });
  }

  function read() { try { return JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) { return null; } }
  function keep(d) { try { localStorage.setItem(KEY, JSON.stringify(d)); } catch (e) { /* full or private mode */ } }

  var cached = read();
  var hadCache = apply(cached);
  var stamp = cached ? JSON.stringify(cached) : '';

  var fetched = fetch('/api/storefront', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
    .then(function (r) { return r.ok ? r.json() : null; })
    .catch(function () { return null; });

  TZ.live = {
    /* Resolves when the page may draw: at once with a kept copy, else when the server answers (or gives up). */
    ready: hadCache ? Promise.resolve() : Promise.race([
      fetched.then(function (d) { if (apply(d)) { keep(d); stamp = JSON.stringify(d); return; } return useFallback(); }),
      new Promise(function (res) { setTimeout(res, WAIT_MS); }).then(useFallback)
    ])
  };

  /* With a kept copy, the fresh answer replaces it and the page redraws only if something changed. */
  fetched.then(function (d) {
    if (!d || !d.ok) return;
    var next = JSON.stringify(d);
    if (next === stamp) return;
    stamp = next;
    keep(d);
    apply(d);
    if (TZ.booted && TZ.rerender) TZ.rerender();
  });
})();
