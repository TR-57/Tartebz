# Putting the shop behind Cloudflare

Cloudflare sits in front of Hostinger. Visitors in the Gulf get pictures, fonts and scripts from a Cloudflare
server near them, and Cloudflare blocks most bad traffic before it reaches Hostinger.
The code is ready for it. These steps are done in **your** Cloudflare and Hostinger accounts.

## 1. Add the domain to Cloudflare (once)
1. Create a free account at cloudflare.com → **Add a site** → type your domain → choose the **Free** plan.
2. Cloudflare copies your DNS records. Check that the main record (`@`) and `www` point at your Hostinger
   server's IP address (hPanel → **Websites → Manage → DNS / Nameservers**). Both should show an **orange cloud** (proxied).
   Leave mail records (MX, and any `mail` record) as they are, with a grey cloud.
3. Cloudflare gives you two nameservers. In hPanel → **Domains → your domain → DNS / Nameservers →
   Change nameservers**, enter those two. It can take a few hours to switch over.

> If your plan has Hostinger's own **CDN** switched on (hPanel → Performance → CDN), switch it off when
> you use Cloudflare. Two CDNs in a row only make problems.

## 2. Cloudflare settings
| Where | Setting |
|---|---|
| SSL/TLS → Overview | **Full (strict)**. Hostinger already has a free SSL certificate; keep it on in hPanel. |
| SSL/TLS → Edge Certificates | **Always Use HTTPS**: on. **Minimum TLS**: 1.2. |
| Speed → Optimization | **Rocket Loader: off** (it changes the order scripts run in and breaks the shop). Brotli: on. |
| Caching → Configuration | Caching level: Standard. **Browser Cache TTL: Respect existing headers.** |
| Security → Bots | Bot Fight Mode: on (optional). |

You do **not** need page rules or cache rules for normal use. The site already tells Cloudflare what to keep:

| Address | Kept by Cloudflare / browsers |
|---|---|
| `/assets/…css`, `/assets/…js`, fonts | 1 year. Each deploy changes the `?v=` on the links, so new files show at once. |
| `/img/…` resized photos, `/uploads/…` originals | 30 days. Replacing a photo in the admin changes its `?v=`. |
| Pages (`/`, `/shop`, `/product/…`) | Always checked with Hostinger (`no-cache` / 5 minutes for product pages). |
| `/api/…`, `/admin/…` | Never kept. |

## 3. Tell the app it is behind Cloudflare
In `backend/.env` on the server:

```
TRUST_PROXY=cloudflare
```

Then run `bash deploy/hostinger/deploy.sh` again (or `php artisan config:cache` in `backend/`).

Why: through Cloudflare, every request seems to come from a Cloudflare address. With this setting the app reads the
visitor's real address from Cloudflare's `CF-Connecting-IP` header, but **only** when the request really comes from
Cloudflare's published address ranges, so nobody can fake it. The sign-in limits, the back-in-stock form
limits and the activity log all depend on the real address.

## 4. Optional: clear Cloudflare after each deploy
Pages are always re-checked, so this is not needed. If you ever want Cloudflare's copy cleared on every deploy:
1. Cloudflare → **My Profile → API Tokens → Create Token** → template **"Cache Purge"**, limited to your domain.
2. Find the **Zone ID** on the domain's Overview page (right-hand column).
3. Run deploys with both set (they are never stored in the repository):
   ```
   CF_ZONE_ID=xxxxxxxx CF_API_TOKEN=yyyyyyyy bash deploy/hostinger/deploy.sh
   ```

## 5. Check it works
- Open the site, then in the browser's developer tools → Network, a picture or script should show the
  response header `cf-cache-status: HIT` after the second load.
- Share a product link (`https://<domain>/product/sweet-sour-strips`) in WhatsApp: it should show the photo, name and description.
  Facebook's checker (developers.facebook.com/tools/debug) shows what the preview looks like and refreshes it.
- Admin → Activity log: new entries should show visitors' real addresses, not Cloudflare ones.
