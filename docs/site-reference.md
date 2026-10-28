# Tartebz.shop — Full Site Reference (from the database dump)

Source: `u592914724_9JccW.tartebz-shop.20261023022959.sql.gz` (WordPress + WooCommerce, dumped 23 Oct 2026).
The theme and plugin code files are NOT in the repo, only the database. The live domain no longer resolves.
Everything below comes from the DB (options, posts, CSS, code snippets, orders).

---

## 1. The business
- **Brand:** ترتيبز / Tartebz ("Tartebz: Qamar Al Deen"). Sour and sweet candy built on **qamar al-din** (apricot paste) with fruit flavours.
- **Location:** Bahrain, West Riffa (الرفاع الغربي), Al-Ajeera street (شارع العجيرة). Store address in WooCommerce: "west rifa, Road 2, block 912, building A57".
- **Contact:** tartebzz@gmail.com · +973 37533757 · CR no. 5-58506 · TikTok @tartebz · Instagram (icon shown, but the URL isn't in the DB; it lives in the theme code).
- **Story (About page):** started as a small home business, grew on social media, covered by Al Watan newspaper and Bahrain TV Channel 1, and visited by Sheikh Khalifa bin Ahmed Al Khalifa.
- **Values:** الأصالة (Authenticity), الابتكار (Innovation), الجودة (Quality), رضا العميل (Customer satisfaction), التميز (Excellence).
- **Sales:** about 950 completed orders, Aug 2025 to Oct 2026. Big jump from Aug 2026 (≈40/mo → 180–225/mo) after the new custom theme went live in June 2026.
  Completed orders by country: BH 344 · QA 289 · AE 159 · SA 90 · KW 48 · OM 14.
  The share of cancelled (1,377) and failed (182) orders is very high. Most are probably abandoned Gate-E/Benefit card payments (see the in-app-browser snippet below).

## 2. Products (12 total, all "simple" products)
| Order | Arabic name | Meaning | Price (BHD) | Stock | Best-seller badge | Units sold |
|---|---|---|---|---|---|---|
| 0 | ترتيبز فرووتس | Tartebz Fruits (mixed pack) | 6.500 | in | ★ | 871 |
| 0 | الغرقانة حامض حلو مانجا | Gharqana sweet-sour mango | 3.000 | out (draft) | | 19 |
| 1 | شرايط حامض كهرباء | Strips, "electric" (extra) sour | 5.000 | out | ★ | 481 |
| 2 | الغرقانة حامض كهرباء توت احمر | Gharqana electric-sour red berry | 3.000 | out | | 77 |
| 3 | الغرقانه حامض حلو توت احمر | Gharqana sweet-sour red berry | 3.000 | out | | 4 |
| 4 | شرايط حامض حلو | Strips sweet-sour | 5.000 | in | ★ | 281 |
| 5 | ترتيبز فرووتس مانجو | Tartebz Fruits mango | 3.000 | in | | 56 |
| 6 | ترتيبز فرووتس اناناس | Tartebz Fruits pineapple | 3.000 | in | | 29 |
| 7 | ترتيبز فرووتس توت | Tartebz Fruits berry | 3.000 | in | | 92 |
| 8 | مارشميلو توت ازرق | Marshmallow blueberry | 3.000 | in | | 53 |
| 9 | مارشميلو الأصفر | Yellow marshmallow | 3.000 | out | | 39 |
| 10 | مارشميلو توت احمر | Marshmallow red berry | 3.000 | in | ★ | 61 |

Weights: 0.2–1 kg (used for shipping). Only the four "Fruits" products have descriptions (Arabic, right-aligned):
- Fruits pack: "a unique blend of original qamar al-din with tropical flavours (pineapple, mango, berry) … ideal as an elegant gift."
- Berry, Pineapple and Mango each have one poetic sentence about their flavour.

**Categories:** الاكثر مبيعاً (Best sellers) · حامض حلو (Sweet & sour) · حامض كهرباء (Electric sour) · مشكل (Mixed) · جميع المنتجات (All products).
Product photos are studio shots (DSC0xxxx.jpg/webp). The image files themselves are not in the dump.

## 3. Pricing and currency (important logic)
Customers pay in **their own country's currency**, using fixed multipliers on the BHD price:
| Country | Currency | Rule | Example 6.500 BHD |
|---|---|---|---|
| Bahrain | BHD | ×1 | 6.500 |
| Qatar | QAR | ×10 | 65 |
| Saudi | SAR | ×10 | 65 |
| UAE | AED | ×10 | 65 |
| Kuwait | KWD | ×0.82 | 5.33 |
| Oman | OMR | ×1.1 | 7.15 |
BHD uses 3 decimals and the symbol goes on the left.

## 4. Shipping
- **Bahrain:** flat rate 1.500 BHD (shown as 15 QAR/AED when the currency is switched).
- **KSA, UAE, Qatar, Oman, Kuwait:** UBEX Express courier. Weight table in BHD:
  0.5 kg 3.8 · 1 kg 4.2 · 1.5 kg 5.1 · 2 kg 6.1 · 2.5 kg 7.3 · 3 kg 8.7 · 3.5 kg 9.6 · 4 kg 12.1 · 4.5 kg 12.2 · 5 kg 13.8 … 10 kg 24.4. ×10 in QAR/SAR/AED.
- Custom order statuses: `wc-shipped`, `wc-ubex-delivered`.
- The home page claimed "نوصل لجميع دول العالم" (we deliver worldwide), but in practice shipping only covers the GCC.

## 5. Payment and checkout
- **Gate-E** (Bahraini gateway: card + BenefitPay) is the only enabled method. COD, bank transfer and cheque are disabled.
- Guest checkout is on. Phone is required, company is hidden, postcode is disabled.
- Checkout fields (Arabic labels): first name, last name, country, street/area (placeholder "house number and street / area"), apartment (optional), city, region, phone, email.
- **In-app-browser banner** (snippet): when the site is opened inside Instagram/TikTok/Snapchat/Facebook, a red bar at the top says "to pay with Benefit, open in Safari/Chrome", with "copy link" and "close" buttons.

## 6. Pages and navigation
**Main menu** (in order): الرئيسية Home · المنتجات Shop · تواصل معنا Contact · من نحن About.
**Header button:** "اطلب الآن" (Order now), red pill.
**Footer / account menu:** حسابي My account · قائمة الرغبات Wishlist · الدفع Checkout · تسجيل الخروج Logout (broken leftover demo URL).
Pages: Home, Shop, Cart, Checkout, My Account, Wishlist, About, Contact, Tracking Order, Terms (**placeholder demo text: PayPal, "Israeli Tax Invoice", "Allegro". Must be rewritten**), Best Sellers, Recently Viewed, and `/staff-orders/` (staff order console).

## 7. Languages
- **Arabic is the default (RTL)**. English is secondary (Polylang, en_AU).
- The custom theme has its own translation system: `tartebz_translations` option and product meta `_tartebz_en_title / _tartebz_en_content / _tartebz_en_excerpt`. These are mostly empty, so English was barely used.

## 8. Design system (custom theme "tartebz", built June 2026 from a Tailwind mockup)
### Colours (`tartebz_style` option + CSS variables)
| Token | Hex | Use |
|---|---|---|
| red | `#E30613` | primary, buttons, links, focus rings |
| dark_red | `#B30000` | button hover, gradient middle |
| red-800 | `#991B1B` | gradient end |
| yellow | `#FFD700` | badge, sparkles, glow, highlights |
| cream | `#FFF8DC` | table headers, payment box, soft backgrounds |
| ink | `#1F2024` | text, dark footer |
| gray | `#5F636B` | secondary text |
| line | `#E7E7E7` | borders |
| in-stock | `#1A7F37` / out-of-stock `#C0392B` | stock badges |
Best-seller badge: gradient `#FFD700 → #FFA500`, text `#7A4A00`, orange glow.

### Typography
- **Thmanyah Sans** for headings and body (Arabic). Base size **18px**. Headings weight 800.
- Older design used "Right Grotesk", Verdana 900 and a custom font "mos" on paragraphs. All replaced.
- **New site:** Alexandria (open licence). Thmanyah Sans's licence allows personal use only and forbids serving the font files
  from a website or CDN, so the new site cannot use it without a commercial licence from Thmanyah (Ask@thmanyah.com).

### Shape and effects
- Radius: cards **18px**, inputs/images **12px**, buttons/badges **999px (pill)**.
- Shadow: `0 10px 40px -12px rgba(227,6,19,.18)` (soft red).
- Inputs: 1.5px border, 50px min height, red focus ring `0 0 0 3px rgba(227,6,19,.12)`.
- Motion: floating logo (translateY −18px, 5s loop), floating sparkle icons (−16px, 6s, staggered), pulsing glow blobs (4s), hover lift (−3px).

### Hero (front page, `.tartebz-hero`)
- Full red gradient `135deg #E30613 → #B30000 → #991B1B`. Can take a background photo with a red overlay (`tartebz_hero_bg`, currently unset).
- 3 blurred glow circles: yellow top-left, yellow bottom-right, orange in the centre.
- Yellow pill badge with a spark icon around the word **"الأصلي" (The Original)**.
- Headline and copy (hardcoded in the theme, not in the DB), "Order now" CTA.
- **Stats row** (`.tartebz-hero-stats`), one stat uses a fruit icon image.
- Large **logo floating** on one side with a yellow-orange radial glow and a deep drop shadow.
- 4 small yellow sparkle/star icons scattered and floating.
- Header is **transparent with white text over the hero**, then turns white with dark text on scroll (`#masthead.scrolled`, `body.tartebz-front`).

### Other sections seen in the CSS
- About/Story with a photo and a floating "100% natural" card (`.tartebz-about-floatcard`) on the image corner.
- Product grid: WooCommerce cards with a ★ best-seller badge (top-left) and an in/out-of-stock badge (top-right).
- **Dark footer** (`.tartebz-footer`, ink colour). Only Instagram and TikTok icons, as round buttons that turn red on hover.

### Mobile (≤820px)
- Header: hamburger on the right, logo centred (46px, brand name hidden), actions on the left. Desktop nav and order button hidden.
- Slide-in panel from the right (78% width, max 320px) with large bold links, a dark overlay, and a red "Order now" pill at the bottom.

### Cart and checkout styling
Red pill buttons, cream table headers, rounded bordered tables, 74px rounded thumbnails, big quantity box (88×50), totals card with red shadow, order total in red 800 weight, full-width "Place order" button.

## 9. Staff / back-office features
- **Shop Order Manager role** (`shop_order_manager`): orders only (view, create, edit status, notes, invoices, UBEX push). No delete, no settings. Lands on `/staff-orders/`.
- **Order console** page `[tartebz_order_console]` (custom plugin `tartebz-order-console`).
- **Products Catalog** admin page: drag-and-drop product order, toggle ★ Best Seller, edit badge text (AR "الأكثر مبيعاً" / EN "Best Seller").
- UBEX fix: picks the correct courier zone per country (AE→9, SA→4, QA→10, OM→11, KW→12).
- PDF invoices, order export, TikTok pixel, Google Site Kit, Mailchimp, Yoast SEO.

## 10. Leftovers to drop in the rebuild
Lots of junk from old demo themes (Sapa, Astra, Besa, Neve…): ~50 unused electronics/fashion menus, demo sliders (Black Friday, Sk8er shop, Ride & connect), testimonials (Molly Kay…), demo "services", 12 unused Google fonts, the Terms demo text, a broken logout URL, and about 34 plugins, many of them redundant (Elementor + Spectra + Smart Slider + Rev Slider + LayerSlider; Yoast + AIOSEO; Jetpack + Wordfence + LiteSpeed…).

## 11. ⚠️ Security / privacy
The old site's database dump was uploaded to the GitHub repo. It has been **removed from the files** (and `.gitignore` now blocks
`*.sql` / `*.sql.gz` dumps), but older commits still contain it until the git history is purged. It contains:
- Personal data of ~472 customers and ~2,500 orders (names, phones, emails, addresses).
- Password hashes for 475 users (2 admins).
- Live API tokens (UBEX Express JWT, and others stored in options).
Still to do: purge it from git history (all branches) and keep the repo private; rotate the UBEX token, Gate-E keys and the old
WordPress admin passwords. Keep any copy of the dump offline only (never in the repo, chat or email).
