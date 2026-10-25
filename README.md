# Tartebz (ترتيبز) — storefront

A new front end for **tartebz.shop**, rebuilt from the original WordPress/WooCommerce database. It keeps the original brand colours, motion and content, and adds a styled catalog, product pages and a floating cart.

- Arabic by default (right-to-left), with a full English version (left-to-right).
- Prices switch to the visitor's Gulf currency (BHD, SAR, AED, QAR, KWD, OMR) using the same multipliers the old store charged.
- Shipping estimates match the old store: a flat rate in Bahrain, and the UBEX weight table for the rest of the GCC.
- Plain HTML, CSS and JS. No build step, no dependencies.

## Run it

```bash
python3 -m http.server 8000
# open http://localhost:8000
```

Any static host works (GitHub Pages, Netlify, Hostinger…).

## Pages

| File | What it is |
|---|---|
| `index.html` | Home: hero, flavour categories, best sellers, why us, story, Gulf shipping, press, CTA |
| `shop.html` | Catalog with category chips, search, sort, in-stock toggle (`?cat=electric-sour`) |
| `product.html?p=<slug>` | Product page: 3D tilt gallery, sour meter, quantity, shipping estimate, tabs, related items, sticky buy bar on mobile |
| `checkout.html` | Checkout form (same fields as the old store) with validation and order summary |
| `about.html`, `contact.html` | Brand story, values, media coverage; contact cards and a WhatsApp message form |

The floating cart button and slide-out cart drawer appear on every page.

## Where things live

```
assets/js/data.js   products, prices, categories, countries/currencies, shipping table, all AR/EN text
assets/js/art.js    logo, icons, and the drawn product packs
assets/js/app.js    language + RTL, currency, header/footer, cart drawer, animations
assets/js/pages.js  page renderers (home, shop, product, checkout, contact)
assets/css/style.css  all styles, design tokens at the top
```

### Common edits
- **Prices / stock / best-seller badge:** edit the product in `data.js` (`price` in BHD, `inStock`, `bestSeller`).
- **Text:** every string is in `TZ.i18n.ar` and `TZ.i18n.en` in `data.js`.
- **Real product photos:** add `image: 'assets/img/products/<slug>.webp'` to a product. It replaces the drawn pack everywhere.
- **Real logo:** set `TZ.store.logo = 'assets/img/logo.png'` in `data.js`.
- **Brand font:** put the Thmanyah Sans `.woff2` files in `assets/fonts/` and uncomment the `url()` lines at the top of `style.css`. Until then it falls back to Alexandria.

## Not connected yet
The storefront pages are still front end only: checkout validates the form and hands the order off to WhatsApp. They get connected to the custom backend below (no WordPress or WooCommerce) in Phases 2–4.

`docs/site-reference.md` documents everything recovered from the old site's database.

## Admin panel and backend (custom build, in phases)

```
backend/   Laravel 11 API (/api/*) – see backend/README.md
admin/     Admin panel (vanilla JS, English + Arabic) served at /admin/
i18n/      Generated translation catalogue (node tools/i18n-build.mjs)
tools/     Dev server, translation build
```

Local: follow `backend/README.md`, then open http://localhost:8800/ (store) and http://localhost:8800/admin/.

| Phase | Scope | State |
|---|---|---|
| 1 | Foundation: database, settings, API errors, sessions, admin sign-in with lockout and reset, roles, staff, activity log, translations, admin shell in EN/AR | Done |
| 2 | Catalogue and content (products, variations, stock, media library, categories, import/export, SEO, homepage, menus) | Next |
| 3 | Cart, checkout, delivery rates | |
| 4 | Payments (Tap, Tabby, Flooss, bank transfer, COD, payment links) | |
| 5 | Orders, customers, fulfilment, SMSA | |
| 6 | Promotions, emails, Meta/TikTok, Google | |
| 7 | Reports, analytics, Odoo, maintenance, hardening |
