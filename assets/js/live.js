/* Tartebz — live shop data.
   Reads /api/storefront (shop details, logo, currency rates, categories, products with photos) and puts it in
   place of the built-in data in data.js. The last answer is kept in the browser, so the next page opens
   instantly; the built-in data is only used when the server cannot be reached.
   Prices always arrive in BHD; TZ.money() converts them with the rates set in the admin. */
(function () {
  var TZ = window.TZ;
  var KEY = 'tz_catalog_v1';
  var WAIT_MS = 2500; // first visit: how long the page waits for the server before showing the built-in data

  var builtinArt = {}, builtinSold = {};
  TZ.products.forEach(function (p) { builtinArt[p.id] = p.art; builtinSold[p.id] = p.sold || 0; });
  var bestCat = TZ.categories.filter(function (c) { return c.id === 'best-sellers'; })[0];
  var DEFAULT_ART = { type: 'box', colors: ['#E30613', '#9E0010'], pieces: ['#FFD700', '#FF8A00', '#FFFFFF'] };

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

    /* Each country pays in the currency the admin linked it to; a country with none pays in BHD. */
    var cur = d.currencies || [];
    var base = cur.filter(function (c) { return c.code === d.baseCurrency; })[0];
    TZ.countries.forEach(function (c) {
      var m = cur.filter(function (x) { return (x.countries || []).indexOf(c.code) !== -1; })[0] || base;
      if (m) { c.cur = m.code; c.rate = m.rate; c.dec = m.dec; c.sym = m.sym; }
    });

    var products = d.products.map(function (p) {
      p.art = builtinArt[p.id] || DEFAULT_ART;
      p.image = p.images && p.images[0] ? p.images[0].src : '';
      p.sold = builtinSold[p.id] || 0; // sales on the old shop, until orders are counted here
      return p;
    });
    var cats = d.categories.slice();
    if (bestCat && products.some(function (p) { return p.bestSeller; })) cats.unshift(bestCat);
    TZ.products = products;
    TZ.categories = cats;
    TZ.isLive = true;
    return true;
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
      fetched.then(function (d) { if (apply(d)) { keep(d); stamp = JSON.stringify(d); } }),
      new Promise(function (res) { setTimeout(res, WAIT_MS); })
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
