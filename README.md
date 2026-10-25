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
This is front end only. Checkout validates the form and hands the order off to WhatsApp. Real payments (Gate-E / BenefitPay), stock and order storage need a backend: either WooCommerce (Store API) behind this front end, or turning these templates into a WordPress theme.

`docs/site-reference.md` documents everything recovered from the old site's database.
