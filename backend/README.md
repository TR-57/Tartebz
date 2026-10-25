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
| Time | Asia/Bahrain everywhere (PHP and the database session). Money BHD, 3 decimals. |
| Translations | Built-in text: `i18n/catalog.json` (generated). Admin edits: `translations` table. Arabic storefront reports missing text to `POST /api/i18n/missing`. |

## What exists (Phase 1)

- Admin sign-in with rate limits (20/15 min per IP, 6 per email+IP, 40 per email), forgot / reset (1 h, single use), change password, sign-out.
- Staff accounts with five roles; disabling or re-passwording ends sessions; owners cannot demote themselves.
- Activity log, `store:create-admin` command.
- Translations: catalogue, admin edits, missing-text reports, default language, storefront overrides endpoint.
