/* Tartebz — SVG artwork: logo, icons and product pack illustrations.
   Product photos weren't in the database dump, so each product gets a drawn pack in its flavour colours.
   To use a real photo instead, set `image: 'assets/img/products/<slug>.webp'` on the product in data.js. */
(function () {
  var TZ = window.TZ;
  var uid = 0;
  function id(p) { uid += 1; return p + uid; }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  /* ---------- icons (24×24, currentColor) ---------- */
  var P = {
    cart: '<path d="M3 4h2l2.2 10.2a2 2 0 0 0 2 1.6h7.6a2 2 0 0 0 2-1.5L21 8H6.2"/><circle cx="10" cy="20" r="1.4"/><circle cx="17" cy="20" r="1.4"/>',
    bag: '<path d="M5 8h14l-1 12a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 8z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
    close: '<path d="M6 6l12 12M18 6L6 18"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    minus: '<path d="M5 12h14"/>',
    trash: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
    menu: '<path d="M4 7h16M4 12h16M4 17h16"/>',
    globe: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 3.8 5.6 3.8 9s-1.3 6.4-3.8 9c-2.5-2.6-3.8-5.6-3.8-9S9.5 5.6 12 3z"/>',
    search: '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
    arrow: '<path d="M5 12h14M13 6l6 6-6 6"/>',
    chevron: '<path d="M9 6l6 6-6 6"/>',
    check: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
    truck: '<path d="M3 6h11v10H3zM14 10h4l3 3v3h-7"/><circle cx="7" cy="18" r="1.8"/><circle cx="17.5" cy="18" r="1.8"/>',
    bell: '<path d="M6 16V11a6 6 0 0 1 12 0v5l2 2H4z"/><path d="M10 20a2 2 0 0 0 4 0"/>',
    cash: '<rect x="2.5" y="6" width="19" height="12" rx="2"/><circle cx="12" cy="12" r="2.6"/><path d="M6 9.5v5M18 9.5v5"/>',
    mail2: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    shield: '<path d="M12 3l8 3v6c0 4.5-3.4 8.3-8 9-4.6-.7-8-4.5-8-9V6l8-3z"/><path d="M8.5 12l2.5 2.5 4.5-5"/>',
    leaf: '<path d="M5 19c0-8 5-13 15-14-1 10-6 15-14 15"/><path d="M5 19l7-7"/>',
    phone: '<path d="M5 3h4l2 5-2.5 1.5a11 11 0 0 0 6 6L16 13l5 2v4a2 2 0 0 1-2 2A17 17 0 0 1 3 5a2 2 0 0 1 2-2z"/>',
    mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
    pin: '<path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
    doc: '<path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5M10 13h6M10 17h6"/>',
    store: '<path d="M4 9l1.5-5h13L20 9M4 9v11h16V9M4 9a2.7 2.7 0 0 0 5.3 0 2.7 2.7 0 0 0 5.4 0 2.7 2.7 0 0 0 5.3 0"/><path d="M10 20v-5h4v5"/>',
    cake: '<path d="M4 20h16v-7H4zM4 16c2 1.2 4 1.2 5.3 0 1.4 1.2 4 1.2 5.4 0 1.3 1.2 3.3 1.2 5.3 0M7 13v-3h10v3M12 10V7"/><path d="M12 3.5c.9 1 .9 2 0 2.5-.9-.5-.9-1.5 0-2.5z"/>',
    card: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/>',
    gift: '<rect x="4" y="9" width="16" height="11" rx="1"/><path d="M3 9h18v-3H3zM12 6v14M12 6c-1.5-3-5-3-5-1s3 1 5 1c2 0 5 1 5-1s-3.5-2-5 1"/>',
    package: '<path d="M12 3l8 4.5v9L12 21l-8-4.5v-9z"/><path d="M4 7.5l8 4.5 8-4.5M12 12v9"/>',
    award: '<circle cx="12" cy="9" r="6"/><path d="M8.5 13.5L7 21l5-2.5 5 2.5-1.5-7.5"/>',
    heart: '<path d="M12 20s-7.5-4.6-9-9.5C2 7 4.2 4.5 7 4.5c2 0 3.3 1.1 5 3 1.7-1.9 3-3 5-3 2.8 0 5 2.5 4 6-1.5 4.9-9 9.5-9 9.5z"/>',
    idea: '<path d="M9 18h6M10 21h4M12 3a6 6 0 0 0-3.5 10.9c.6.5 1 1.2 1 2.1h5c0-.9.4-1.6 1-2.1A6 6 0 0 0 12 3z"/>',
    people: '<circle cx="9" cy="8" r="3"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6"/><circle cx="17" cy="9" r="2.4"/><path d="M15.5 14.3c3 .1 5.5 2.5 5.5 5.7"/>',
    flag: '<path d="M5 21V4M5 4h11l-2 4 2 4H5"/>',
    star: '<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>',
    bolt: '<path d="M13 2L4 14h7l-1 8 9-12h-7z"/>',
    drop: '<path d="M12 3s6 6.6 6 11a6 6 0 0 1-12 0c0-4.4 6-11 6-11z"/>',
    mix: '<circle cx="8" cy="9" r="4"/><circle cx="16" cy="9" r="4"/><circle cx="12" cy="16" r="4"/>',
    sparkle: '<path d="M12 2l1.8 6.2L20 10l-6.2 1.8L12 18l-1.8-6.2L4 10l6.2-1.8z"/>',
    filter: '<path d="M4 6h16M7 12h10M10 18h4"/>'
  };
  var FILLED = { star: 1, bolt: 1, drop: 1, sparkle: 1, heart: 0 };
  TZ.icon = function (name, cls) {
    var body = P[name] || '';
    var fill = FILLED[name] ? 'fill="currentColor" stroke="none"' : 'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';
    return '<svg class="ico ' + (cls || '') + '" viewBox="0 0 24 24" ' + fill + ' aria-hidden="true" focusable="false">' + body + '</svg>';
  };

  /* Brand icons (filled) */
  TZ.brandIcon = function (name) {
    var d = {
      instagram: '<path fill-rule="evenodd" d="M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6zm0-2a5 5 0 1 1 0 10 5 5 0 0 1 0-10zM7 2h10a5 5 0 0 1 5 5v10a5 5 0 0 1-5 5H7a5 5 0 0 1-5-5V7a5 5 0 0 1 5-5zm0 2a3 3 0 0 0-3 3v10a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3V7a3 3 0 0 0-3-3H7z"/><circle cx="17.5" cy="6.5" r="1.3"/>',
      tiktok: '<path d="M16.5 3c.32 2.1 1.6 3.7 3.5 4v2.32c-1.2 0-2.4-.4-3.5-1.02v6.2a5.6 5.6 0 1 1-5.6-5.6c.3 0 .6.02.9.07v2.42a3.2 3.2 0 1 0 2.3 3.07V3h2.4z"/>',
      whatsapp: '<path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8s-.4-.1-.6.1-.7.8-.8 1-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.3-.4.3-.4.7-1.3a.4.4 0 0 0 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4c1.7.7 2.3.8 3.2.6a2.7 2.7 0 0 0 1.8-1.2 2.2 2.2 0 0 0 .1-1.3c0-.1-.2-.2-.5-.3z"/>'
    }[name] || '';
    return '<svg class="ico" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">' + d + '</svg>';
  };

  /* ---------- logo ---------- */
  TZ.logoSVG = function (cls) {
    if (TZ.store.logo) {
      return '<img class="logo-img ' + (cls || '') + '" src="' + esc(TZ.store.logo) + '"' +
        (TZ.store.logoLarge ? ' srcset="' + esc(TZ.store.logo) + ' 200w, ' + esc(TZ.store.logoLarge) + ' 400w" sizes="(min-width: 700px) 220px, 120px"' : '') +
        ' alt="' + esc(TZ.L(TZ.store.name)) + '" decoding="async">';
    }
    var g = id('lg');
    return '<svg class="logo-svg ' + (cls || '') + '" viewBox="0 0 200 200" role="img" aria-label="' + esc(TZ.L(TZ.store.name)) + '">' +
      '<defs><radialGradient id="' + g + '" cx="35%" cy="30%" r="80%"><stop offset="0" stop-color="#FF2A35"/><stop offset=".55" stop-color="#E30613"/><stop offset="1" stop-color="#9E0010"/></radialGradient></defs>' +
      '<circle cx="100" cy="100" r="96" fill="url(#' + g + ')"/>' +
      '<circle cx="100" cy="100" r="84" fill="none" stroke="#FFD700" stroke-width="3" stroke-dasharray="1 8" stroke-linecap="round"/>' +
      '<path d="M40 64a70 70 0 0 1 120 0" fill="none" stroke="#fff" stroke-opacity=".18" stroke-width="10" stroke-linecap="round"/>' +
      '<text x="100" y="114" text-anchor="middle" font-size="56" font-weight="900" fill="#fff">ترتيبز</text>' +
      '<text x="100" y="146" text-anchor="middle" font-size="15" font-weight="800" letter-spacing="6" fill="#FFD700">TARTEBZ</text>' +
      '<path transform="translate(148 44) scale(1.5)" fill="#FFD700" d="M8 0l1.4 5.2L14.6 7 9.4 8.6 8 14 6.6 8.6 1.4 7l5.2-1.8z"/>' +
      '</svg>';
  };

  /* Small fruit trio for the hero stats */
  TZ.fruitIcon = function () {
    return '<svg class="fruit-ico" viewBox="0 0 64 64" aria-hidden="true">' +
      '<ellipse cx="22" cy="38" rx="13" ry="16" fill="#FFC21A" transform="rotate(-20 22 38)"/><ellipse cx="19" cy="33" rx="4" ry="6" fill="#fff" opacity=".35" transform="rotate(-20 19 33)"/>' +
      '<path d="M22 22c-2-6 2-10 7-10-1 5-3 8-7 10z" fill="#7BC043"/>' +
      '<circle cx="44" cy="40" r="12" fill="#C2185B"/><circle cx="40" cy="36" r="3" fill="#fff" opacity=".4"/>' +
      '<path d="M44 28c0-4 3-7 6-7" stroke="#2E7D32" stroke-width="3" fill="none" stroke-linecap="round"/>' +
      '<circle cx="34" cy="50" r="9" fill="#FF8A00"/><circle cx="31" cy="47" r="2.4" fill="#fff" opacity=".45"/>' +
      '</svg>';
  };

  /* ---------- product pack illustrations ---------- */
  function sparkle(x, y, s, c, o) {
    return '<path class="art-spark" transform="translate(' + x + ' ' + y + ') scale(' + s + ')" fill="' + (c || '#FFD700') + '" opacity="' + (o || 1) + '" d="M8 0l1.6 6.4L16 8l-6.4 1.6L8 16l-1.6-6.4L0 8l6.4-1.6z"/>';
  }
  /* Long flavour names get squeezed to fit the label width. */
  function fit(str, max) { return String(str).length > max ? ' textLength="' + (max * 6.4) + '" lengthAdjust="spacingAndGlyphs"' : ''; }
  function label(y, flavor, dark) {
    return '<text x="150" y="' + y + '" text-anchor="middle" font-size="30" font-weight="900" fill="' + (dark ? '#E30613' : '#fff') + '">ترتيبز</text>' +
      '<text x="150" y="' + (y + 19) + '" text-anchor="middle" font-size="11.5" font-weight="700" fill="' + (dark ? '#1F2024' : '#fff') + '" opacity=".8"' + fit(flavor, 17) + '>' + esc(flavor) + '</text>';
  }
  function boltBadge(p) {
    if (p.sour < 5) return '';
    return '<g class="art-bolt"><circle cx="226" cy="84" r="22" fill="#FFD700" stroke="#fff" stroke-width="3"/><path transform="translate(214 70) scale(1.05)" fill="#1F2024" d="M13 2L4 14h7l-1 8 9-12h-7z"/></g>';
  }

  function box(p, a, g, sh, flavor) {
    var k = a.pieces;
    return '<rect x="62" y="102" width="176" height="160" rx="22" fill="url(#' + g + ')"/>' +
      '<rect x="62" y="102" width="176" height="160" rx="22" fill="url(#' + sh + ')"/>' +
      '<rect x="140" y="102" width="20" height="160" fill="#FFD700" opacity=".95"/>' +
      '<rect x="52" y="80" width="196" height="40" rx="14" fill="' + a.colors[1] + '"/>' +
      '<rect x="62" y="85" width="176" height="7" rx="3.5" fill="#fff" opacity=".25"/>' +
      '<rect x="140" y="80" width="20" height="40" fill="#FFC400"/>' +
      '<ellipse cx="132" cy="72" rx="20" ry="10" fill="#FFD700" transform="rotate(-24 132 72)"/>' +
      '<ellipse cx="168" cy="72" rx="20" ry="10" fill="#FFD700" transform="rotate(24 168 72)"/>' +
      '<circle cx="150" cy="78" r="8" fill="#FFB300"/>' +
      '<rect x="86" y="140" width="128" height="62" rx="18" fill="#fff"/>' + label(173, flavor, true) +
      [100, 150, 200].map(function (x, i) {
        return '<g><circle cx="' + x + '" cy="234" r="15" fill="' + k[i % k.length] + '"/><circle cx="' + (x - 5) + '" cy="229" r="4" fill="#fff" opacity=".55"/></g>';
      }).join('');
  }

  function strips(p, a, g, sh, flavor, clip) {
    var k = a.pieces;
    var waves = [116, 136, 156, 176, 196].map(function (y, i) {
      return '<path d="M70 ' + y + ' q15 -13 30 0 t30 0 t30 0 t30 0 t30 0 t30 0" fill="none" stroke="' + k[i % k.length] + '" stroke-width="13" stroke-linecap="round"/>';
    }).join('');
    var sugar = '';
    for (var i = 0; i < 26; i++) { sugar += '<circle cx="' + (96 + (i * 37) % 110) + '" cy="' + (106 + (i * 23) % 92) + '" r="1.6" fill="#fff" opacity=".9"/>'; }
    return '<path d="M80 58H220Q232 58 232 70V234Q232 262 204 264H96Q68 262 68 234V70Q68 58 80 58Z" fill="url(#' + g + ')"/>' +
      '<path d="M80 58H220Q232 58 232 70V234Q232 262 204 264H96Q68 262 68 234V70Q68 58 80 58Z" fill="url(#' + sh + ')"/>' +
      '<rect x="68" y="58" width="164" height="22" rx="10" fill="' + a.colors[1] + '"/>' +
      '<path d="M76 70h148" stroke="#fff" stroke-opacity=".35" stroke-width="2" stroke-dasharray="4 5"/>' +
      '<clipPath id="' + clip + '"><rect x="92" y="98" width="116" height="104" rx="22"/></clipPath>' +
      '<rect x="92" y="98" width="116" height="104" rx="22" fill="#FFF8DC"/>' +
      '<g clip-path="url(#' + clip + ')">' + waves + sugar + '</g>' +
      '<rect x="92" y="98" width="116" height="104" rx="22" fill="none" stroke="#fff" stroke-width="4"/>' +
      label(236, flavor, false) + boltBadge(p);
  }

  function cup(p, a, g, sh, flavor, clip) {
    var k = a.pieces;
    var cupD = 'M72 118L228 118L211 256Q209 268 196 268L104 268Q91 268 89 256Z';
    var bits = '';
    var spots = [[108, 172, 0], [150, 168, 30], [192, 176, -20], [120, 214, 15], [180, 220, -35], [150, 246, 10], [104, 246, -10], [196, 248, 25]];
    spots.forEach(function (s, i) {
      bits += '<rect x="' + (s[0] - 13) + '" y="' + (s[1] - 10) + '" width="26" height="20" rx="8" fill="' + k[i % k.length] + '" transform="rotate(' + s[2] + ' ' + s[0] + ' ' + s[1] + ')"/>';
    });
    return '<clipPath id="' + clip + '"><path d="' + cupD + '"/></clipPath>' +
      '<path d="' + cupD + '" fill="' + a.colors[0] + '" opacity=".14"/>' +
      '<g clip-path="url(#' + clip + ')"><path class="art-liquid" d="M40 156Q70 144 100 156T160 156T220 156T280 156V290H40Z" fill="url(#' + g + ')" opacity=".92"/>' + bits +
      '<circle cx="130" cy="190" r="3" fill="#fff" opacity=".6"/><circle cx="170" cy="236" r="2.5" fill="#fff" opacity=".6"/><circle cx="112" cy="232" r="2" fill="#fff" opacity=".6"/></g>' +
      '<path d="' + cupD + '" fill="url(#' + sh + ')" stroke="#fff" stroke-opacity=".8" stroke-width="2.5"/>' +
      '<path d="M84 132L98 250" stroke="#fff" stroke-opacity=".55" stroke-width="5" stroke-linecap="round"/>' +
      '<rect x="98" y="190" width="104" height="48" rx="14" fill="#fff"/>' +
      '<text x="150" y="215" text-anchor="middle" font-size="22" font-weight="900" fill="#E30613">ترتيبز</text>' +
      '<text x="150" y="230" text-anchor="middle" font-size="9.5" font-weight="700" fill="#1F2024" opacity=".75"' + fit(flavor, 14) + '>' + esc(flavor) + '</text>' +
      '<path d="M66 116Q150 34 234 116Z" fill="#fff" opacity=".5"/>' +
      '<path d="M96 96Q120 70 150 66" fill="none" stroke="#fff" stroke-width="4" stroke-linecap="round" opacity=".8"/>' +
      '<ellipse cx="150" cy="117" rx="88" ry="11" fill="' + a.colors[1] + '"/>' + boltBadge(p);
  }

  function bag(p, a, g, sh, flavor, clip) {
    var k = a.pieces;
    var crimp = 'M70 74V58';
    for (var x = 70; x < 230; x += 10) { crimp += 'L' + (x + 5) + ' 66L' + (x + 10) + ' 58'; }
    crimp += 'V74Z';
    var puffs = '';
    var spots = [[124, 128, -15], [168, 124, 12], [146, 152, 0], [116, 170, 20], [178, 168, -18], [146, 188, 8], [196, 146, 30], [102, 146, -30]];
    spots.forEach(function (s, i) {
      puffs += '<g transform="rotate(' + s[2] + ' ' + s[0] + ' ' + s[1] + ')"><rect x="' + (s[0] - 17) + '" y="' + (s[1] - 13) + '" width="34" height="26" rx="11" fill="' + k[i % k.length] + '" stroke="rgba(0,0,0,.06)"/><rect x="' + (s[0] - 11) + '" y="' + (s[1] - 9) + '" width="14" height="5" rx="2.5" fill="#fff" opacity=".7"/></g>';
    });
    return '<path d="M70 72L230 72L237 236Q237 264 209 264L91 264Q63 264 63 236Z" fill="url(#' + g + ')"/>' +
      '<path d="M70 72L230 72L237 236Q237 264 209 264L91 264Q63 264 63 236Z" fill="url(#' + sh + ')"/>' +
      '<path d="' + crimp + '" fill="' + a.colors[1] + '"/>' +
      '<clipPath id="' + clip + '"><circle cx="150" cy="156" r="52"/></clipPath>' +
      '<circle cx="150" cy="156" r="52" fill="#FFF8DC"/><g clip-path="url(#' + clip + ')">' + puffs + '</g>' +
      '<circle cx="150" cy="156" r="52" fill="none" stroke="#fff" stroke-width="5"/>' +
      label(236, flavor, false);
  }

  /* A product photo (resized copies from /img/<width>/…; the browser picks the size it needs). */
  TZ.photo = function (p, i, cls) {
    var im = (p.images && p.images[i || 0]) || { src: p.image };
    var set = im.small ? ' srcset="' + esc(im.small) + ' 400w, ' + esc(im.src) + ' 800w' + (im.large ? ', ' + esc(im.large) + ' 1600w' : '') + '"' +
      ' sizes="(max-width: 600px) 90vw, (max-width: 1000px) 45vw, 520px"' : '';
    return '<img class="art art-photo ' + (cls || '') + '" src="' + esc(im.src) + '"' + set + ' alt="' + esc(TZ.L(p.name)) + '" loading="lazy" decoding="async">';
  };

  /* Returns the product visual: a real photo if one is set, otherwise the drawn pack. */
  TZ.productArt = function (p, cls) {
    var name = TZ.L(p.name);
    if (p.image) return TZ.photo(p, 0, cls);
    var a = p.art, g = id('g'), sh = id('s'), clip = id('c');
    var flavor = TZ.L(p.flavor);
    var draw = { box: box, strips: strips, cup: cup, bag: bag }[a.type] || box;
    return '<svg class="art art-' + a.type + ' ' + (cls || '') + '" viewBox="0 0 300 300" role="img" aria-label="' + esc(name) + '">' +
      '<defs><linearGradient id="' + g + '" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="' + a.colors[0] + '"/><stop offset="1" stop-color="' + a.colors[1] + '"/></linearGradient>' +
      '<linearGradient id="' + sh + '" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#fff" stop-opacity=".32"/><stop offset=".45" stop-color="#fff" stop-opacity="0"/><stop offset="1" stop-color="#000" stop-opacity=".12"/></linearGradient></defs>' +
      '<ellipse class="art-shadow" cx="150" cy="276" rx="92" ry="11" fill="#000" opacity=".16"/>' +
      '<g class="art-body">' + draw(p, a, g, sh, flavor, clip) + '</g>' +
      sparkle(40, 60, 1.2, '#FFD700') + sparkle(250, 210, .8, '#FFD700', .9) + sparkle(36, 200, .6, a.colors[0], .8) +
      '</svg>';
  };
})();
