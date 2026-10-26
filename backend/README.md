# Tartebz backend (Laravel 11 · PHP 8.3 · MariaDB)

The JSON API under `/api/*` that the storefront and the admin panel (`/admin/`) talk to.
Custom-built – no WordPress or WooCommerce.

## Run it locally

```bash
cd backend
cp .env.example .env            # then set DB_* and APP_URL/STORE_URL/ADMIN_URL (http://localhost:8800)
composer install
php artisan key:generate
php artisan migrate
ADMIN_PASSWORD='choose-a-long-one' php artisan store:create-admin you@example.com "Your Name" owner
php artisan store:import-old-site   # the old shop: photos (byte for byte), logo, categories, 12 products, contact details
cd .. && node tools/i18n-build.mjs      # translation catalogue
php -S localhost:8800 tools/dev-server.php   # storefront + /admin/ + /api on one port
```

Tests: `php artisan test` (uses the `tartebz_test` database from `phpunit.xml`).

## Conventions

| Topic | Rule |
|---|---|
| Responses | `{ok:true, …}` or `{ok:false, error:{code, message, fields?}}`. Throw `App\Support\ApiError`; validation errors are 422 with `fields` keyed by input name. |
| Roles | `role:staff` / `role:manager` / `role:owner` (ladder), or `role:order_manager,managers` (any of). `tests/Feature/RolePermissionsTest` walks every admin route – a route without a role check fails the suite. |
| Sessions | Cookie token is random; only its SHA-256 is stored (`sessions`). Admin: 12 h, host-only, HttpOnly, SameSite=Lax. |
| CSRF | `OriginGuard`: every POST/PUT/DELETE must come from our own origin. Webhooks are listed in `config/store.php → origin_exempt`. |
| Settings | `Settings::get('key')` = saved value merged over `config/store_settings.php` (lists replaced whole). Secrets go through `Secret::seal()/open()`. |
| Audit | Every state-changing admin action calls `Audit::log()`. Never log secret values. |
| Email | `Outbox::queue()` writes `mail_outbox`, sends after the response, retried by housekeeping. Layout: `EmailLayout::wrap()`. |
| Time | Asia/Bahrain everywhere (PHP and the database session). |
| Money | Every price and total is stored in BHD (3 decimals). Customers may see and pay in another currency (`Currency::fromBase`); orders save the currency, the rate and the BHD amounts, and every admin screen, sales figure and report adds up BHD. See `docs/currency.md`. |
| Files | Originals in `UPLOAD_DIR` are never re-encoded (only GPS is blanked). Pages use `/img/<width>/uploads/…` copies (`Thumbs::url`). |
| Translations | Built-in text: `i18n/catalog.json` (generated). Admin edits: `translations` table. Arabic storefront reports missing text to `POST /api/i18n/missing`. |

## What exists (shop extras)

- Back-in-stock requests (`POST /api/stock-alerts`): emails go out by themselves when a product is back; WhatsApp numbers are listed on the product with a ready message.
- Admin › Settings › Website: WhatsApp ordering, back-in-stock on/off, payment marks, delivery days per country (shown as dates on the site).
- `/product/<slug>` is built on the server with link-preview tags (WhatsApp, Instagram, Facebook), Product structured data for Google and a 1200×630 JPEG preview picture (`/img/og/…`). Old WordPress product addresses redirect there. `/sitemap.xml` and `/robots.txt` come from the live catalogue.
- `store:build-public <web folder>`: what deploy.sh runs – minified CSS/JS with `?v=<commit>` on every link, so they can be cached for a year (Cloudflare-ready, see `docs/cloudflare.md`, `TRUST_PROXY=cloudflare`).
- Fonts are self-hosted (`assets/fonts/`, Alexandria, SIL Open Font License).

## What exists (Phase 2a)

- Media library: upload (JPG, PNG, WebP, GIF, PDF; no SVG), details and alt texts in both languages, where-used, replace with the same link, delete guards, delete unused. Resized WebP copies at 200–2400 px.
- Products (list with tabs, search, category filter, quick stock, bulk actions; editor with photos, both languages, sourness, best seller, price, stock, weight, versions, SEO) and categories (tree, order, picture, icon, delivery weight).
- Currencies (owner): rate per currency, decimals, symbols, which countries pay in it.
- `GET /api/storefront`: shop details, logo, currencies, live categories and products (ETag, 304). The storefront reads it (`assets/js/live.js`) and keeps the last answer for instant pages.
- `store:import-old-site`: the old WordPress shop, from `database/seed/old-site.json` and `media-seed/`.

## What exists (Phase 1)

- Admin sign-in with rate limits (20/15 min per IP, 6 per email+IP, 40 per email), forgot / reset (1 h, single use), change password, sign-out.
- Staff accounts with five roles; disabling or re-passwording ends sessions; owners cannot demote themselves.
- Activity log, `store:create-admin` command.
- Translations: catalogue, admin edits, missing-text reports, default language, storefront overrides endpoint.
