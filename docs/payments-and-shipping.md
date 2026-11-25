# Tap payments and SMSA shipping – from keys to live

Everything is built. You only paste keys, test, and switch to live – all in the admin, no code.

## Before you start (once)
- The site must run on **https** on its real domain (Hostinger gives a free SSL certificate).
- The **cron job** must run every minute (hPanel → Advanced → Cron Jobs):
  `php /home/<user>/domains/<domain>/tartebz-app/backend/artisan schedule:run`
  Every 5 minutes it re-checks unfinished card payments with Tap (in case a customer closed the page) and retries unsent emails.
- Email (SMTP) should be set so customers get their order confirmation (Settings → Email).

---

## Tap (card, Apple Pay, Benefit, mada, KNET)

**Where:** Admin → Settings → **Payments** (owner only).

### 1. Test
1. In the Tap dashboard → goSell → **API keys**, copy the **test** secret key (`sk_test_…`) and test publishable key (`pk_test_…`).
2. Paste them, keep **Test** mode, tick **Card payments on at checkout**, Save.
3. Press **Test connection (test keys)** → it must say *Connected to Tap*.
4. On the website, add a product, go to checkout: a yellow *Test mode* note shows. Choose **Pay now**, fill the form, continue.
   You land on Tap's page. Pay with a Tap **test card** (Tap's docs → "Testing cards").
5. You come back to the order page: *Paid – thank you*. In Admin → **Orders** the order is **Paid**, stock went down,
   and the customer + owner emails were sent. Also try a failed payment: the customer sees *Try paying again*.

### 2. Live
1. Paste the **live** keys (`sk_live_…`, `pk_live_…`), Save, press **Test connection (live keys)**.
2. Choose **Live**, Save (it asks you to confirm). The test note disappears from checkout. Payments are now real.
3. Place one small real order yourself and refund it in the Tap dashboard, to see the whole thing once.

### Good to know
- Card details never touch this website – customers pay on Tap's page.
- An order is marked paid only after the website asks Tap directly and Tap says **CAPTURED** for the **exact amount and currency**.
  A fake "paid" message from a browser or a forged notification changes nothing.
- Payment methods shown on Tap's page (Apple Pay, Benefit, mada, KNET…) are switched on **in your Tap account**. See *Apple Pay* below.
- **Currencies:** by default everyone is charged in **BHD** (the same price, converted). If your Tap account can settle SAR, AED, etc.,
  tick them under *Charge in these currencies* – Saudi customers are then charged in SAR. Reports always add up in BHD.
- **Refunds** are done from the order page – see *Refunds* below.
- Tap sends updates to `https://<your-domain>/api/payments/tap/webhook`. The website includes this address on every payment,
  so you normally do not need to enter it in Tap.

### Apple Pay
1. Ask Tap support to **switch on Apple Pay** for your account (test first, then live). From then on the Apple Pay button shows on
   Tap's payment page for customers on iPhone, iPad and Mac (Safari). Nothing changes on the website.
2. If Tap asks you to **verify your domain** for Apple Pay, they send a file named
   `apple-developer-merchantid-domain-association`. Upload it in **Payments → Apple Pay**. The website serves it at
   `https://<your-domain>/.well-known/apple-developer-merchantid-domain-association` (the *Open* link checks it). Tell Tap it is in place.
3. Test with an iPhone in Tap's test mode, then live.
4. To show the Apple Pay logo at checkout and in the footer: Settings → **Website** → Payment logos → tick Apple Pay.

---

## Refunds
**Where:** the order page → **Refund** (managers and owners).

- **Card orders (Tap):** enter the amount (all of it, or part) and a reason. The refund is sent to Tap at once. It shows
  *Refund pending* until Tap confirms it (usually seconds; the website also re-checks every 5 minutes), then *Refunded*.
  The customer gets an email; the money reaches their card in 5–10 working days, depending on their bank.
- **WhatsApp / bank transfer / cash orders:** give the money back yourself, then **Record a refund** – it is saved as done at once.
  Use this too if you ever refund a card payment in the Tap dashboard instead.
- A full refund turns the order into **Refunded**. Tick **Put the items back in stock** if the goods came back or never left.
  For part of an order, change the stock in Products.
- You can never refund more than was paid – refunds that are still pending count too, so a double click never refunds twice.
- If Tap cannot be reached while refunding, the refund stays *pending*: look in the Tap dashboard, then press **It went through** or
  **It did not go through** on the order.
- The dashboard's sales figures are **after refunds**.

---

## Stock reservation (no two customers pay for the last piece)
- When a customer adds a product to the cart, those pieces are **kept for them for 15 minutes**. The time starts again whenever they
  use the cart or the checkout. The cart shows *Kept for you mm:ss*.
- Other customers see only the stock nobody is keeping. If someone keeps the last piece, the product shows *In other carts – check back soon*.
- Placing a card order keeps the pieces **60 minutes** for the payment; a WhatsApp order keeps them **24 hours** until you mark it paid.
  Paying takes them from stock for good. Cancelling gives them back.
- All three times are in Settings → **Website → Stock reservation**. One visitor can keep at most 60 pieces at once, so nobody can empty the shop.
- Products → the stock column shows *n in carts* while pieces are kept.

---

## Delivery prices – Admin → Settings → **Shipping rates** (owner)

The same model and rates as Sniper Shopping. Prices are calculated by the website from these rates – never fetched from SMSA,
never taken from the browser (checkout prices the order again on the server).

| Destination | Method | Price (BHD) | Time | Free over | Max weight | Delivered by |
|---|---|---|---|---|---|---|
| Bahrain | Standard delivery | 2.000 up to 5 kg, +0.300 per extra kg, max 6.000 | 1–2 working days | 25.000 | – | our team |
| Bahrain | Same-day express | 4.000 flat | same day | – | 20 kg | our team |
| Bahrain | Collect from branch | free | 0–1 working day | – | – | – |
| Saudi Arabia | GCC courier | 3.500 up to 0.5 kg, +0.800 per extra 0.5 kg | 3–5 working days | – | 30 kg | SMSA |
| UAE, Kuwait, Qatar, Oman | GCC courier | 4.000 up to 0.5 kg, +1.000 per extra 0.5 kg | 4–7 working days | – | 30 kg | SMSA |

Examples: 0.5 kg → Saudi 3.500, UAE/Kuwait 4.000; 1.5 kg → 5.100 / 6.000; 2 kg → 5.900 / 7.000.

- **Formula:** the base covers the first X kg; every started step above it adds the step price; then the maximum; free once the
  order subtotal reaches "free over"; above the max weight the method is not offered.
- **Weight:** the sum of the products' weights; a product with no weight counts as **0.5 kg** (General › weight of a product with none).
- **Delivery time:** working days – Friday and Saturday are not working days; orders after **2 pm Bahrain time** count from the next
  day (General › cut-off and weekend). The shop shows the time and the arrival dates ("Arrives Sun 15 Nov – Tue 17 Nov").
- **Quoted** methods have no price at checkout ("Quoted after order"); the team adds the delivery cost after the order.
- The product page shows "Delivery to [country]" with every method; checkout shows only the methods for the customer's country.
- The **Delivery and Pricing** page (/delivery) shows this table from the live rates.

---

## SMSA Express – Admin → Settings → **SMSA shipping** (owner)

The same integration as Sniper Safety: waybills are created **by themselves** for paid orders, labels print from the order,
and the order status follows SMSA's tracking.

### 1. Get the keys
Ask your SMSA account manager for the **SMSA eCommerce API** ("ecomapis") keys: one for the **sandbox**, one for **live**.
They are different – a live key does not work on the sandbox and the other way round.

### 2. Set it up (sandbox first)
1. Paste the **sandbox** key, press **Check connection**.
2. Fill in the **sender** (printed on every waybill): name (5+ letters), phone, country, city, address (10+ characters).
3. **Delivery methods sent with SMSA**: tick the GCC courier methods (Saudi Arabia, rest of the GCC – ticked by default; Bahrain is
   delivered by our own team). For each method going **abroad**
   choose **By air (EIDL)** or **By road (EIED)** – the service printed on the label. Inside Bahrain is always **EDDL**.
4. **Import VAT and duty paid by** (shipments outside Bahrain only): *the customer pays on delivery* (Consignee) or *we pay*
   (billed to our SMSA account, Shipper). Each waybill remembers what it was created with, and SMSA's own record is compared –
   a difference shows as a warning on the order.
5. **What the parcels contain** (customs) – or leave empty to list each order's items.
6. Keep **Sandbox**, switch **Send paid orders to SMSA automatically** on, Save.
7. Place a test order (a Tap test card or a WhatsApp order marked paid). Within a minute it gets a sandbox waybill.

### 3. Live
Paste the **live** key, check it, choose **Live**, Save (it asks you to confirm). From then on every waybill is a **real,
billable parcel**. Only orders placed after SMSA was switched on are sent by themselves; older ones can be sent by hand.

### What happens to an order
- **Paid** + an SMSA delivery method → waybill within a minute (the `store:smsa` job runs every minute from cron). The customer
  gets an email with the tracking number.
- The customer's address line, city or phone is empty → it is not sent; the order says why.
- SMSA **refused** it (unknown city …) → the order shows SMSA's message and goes back to the team's "to prepare" list; it is tried
  again 10 minutes later, 3 times at most. Fix the address and press **Try again**. The SMSA screen lists refused orders.
- SMSA's record of who pays import VAT and duty differs from what was sent → a warning on the order ("check this waybill in the
  SMSA portal before it ships") and it goes back to "to prepare".
- Tracking is read **every hour**: the scans go on the order's history; the order becomes **Shipped** when SMSA has the parcel
  and **Delivered** when it is delivered – the customer is emailed both times (switch off "Update the order status from SMSA tracking" to do it by hand).
- Any paid order can be **sent to SMSA by hand** from the order (boxes and weight can be changed), and a waybill can be
  **cancelled** by a manager before SMSA picks it up.

### Addresses SMSA accepts, and fixing a customer’s details

SMSA refuses an address line over 100 characters and a city or district over 50. So:

- **Checkout** stops longer entries (street/area and the second line up to 100 characters, city and area up to 50) and tells
  the customer, in their language, to move building, flat and landmark to the second line. The staff board’s “New order” form has the same limits.
- **The waybill** never sends text over SMSA’s limits: a long first line flows into the second (split at a comma or between words),
  and the district is the area the customer typed (else the city) – never the free-text second line.
- **Edit** on the customer card (admin order page and the staff board) fixes the name, phone, email or address – same rules as checkout.
  The country stays (it set the delivery price). The history shows what changed and who changed it. If SMSA refused the order,
  edit, then press **Try again** in the SMSA box. A waybill already made keeps the old address: cancel it and send it again.

### Printing
- **Print SMSA label** on the order: the label as SMSA made it, or with a *part of the value* printed as the declared value
  (on that copy only – SMSA keeps the full value).
- **Print** on an order going **outside Bahrain** prints the **invoice followed by the SMSA waybill** (4 × 6 in label page).
  A Bahrain order prints the invoice only.
- In the order list, tick several orders: **Print invoices (+ SMSA waybills abroad)**, **SMSA labels (one PDF)** or
  **Send to SMSA**.
- The staff board (/staff) has the same print button and SMSA panel on each order.

### Pickups – “Book SMSA pickup” at the top of the orders list

SMSA does not let any website book a courier collection: its eCom API has no function for it (what the API calls
“pickup” is SMSA collecting a return from a customer). Collections are booked in SMSA’s e-commerce dashboard under
**Manage Pickup › Initiate Pickup Request**. The button makes that quick and keeps the result for the whole team:

1. **Book SMSA pickup** (top of Admin › Orders, and of the orders board /staff – for everyone who works on orders).
2. Choose the day (today by default, or one of the next 6 days), the **ready time** and the **closing time** – inside the
   pickup hours (**09:00–17:00** by default), at least 1 hour apart. All waybills waiting for the courier are ticked; untick
   any that are not ready. Parcels and “heavy” fill in by themselves and can be changed.
3. **Continue in SMSA** opens SMSA’s dashboard in a new tab and shows the answers for its form in the same order (contact
   name, phone, pickup address, postal code, origin city, heavy, ready time, closing time, notes with the waybills), each
   with a **Copy** button.
4. Submit SMSA’s form, paste the **booking number** SMSA shows, and save. It shows above the orders list, on each order
   and in its history. “Finish in SMSA” reopens the answers if you closed them before saving the number.

**Cancel pickup** frees the waybills here (cancel it in SMSA’s dashboard too). A booking closes by itself when SMSA’s
tracking shows the parcels were collected. Sandbox waybills can be booked to try it; there is nothing to book in SMSA.
Settings › SMSA shipping › Pickups: the dashboard link (paste the Initiate Pickup Request page’s own address if you have
it), the pickup hours, the shortest window and the weight from which “heavy” is ticked. The answers come from the sender
address in the same screen.

If SMSA ever offers a pickup-booking API, the button can book directly and fill in the booking number by itself.

## Pages Tap asks for

| Requirement | Page |
|---|---|
| Terms and Conditions | /terms |
| Refund Policy | /refund-policy |
| Privacy Policy | /privacy |
| Products or services | /shop (every product with photo, description and price: /product/…) |
| Pricing | product prices on /shop and each product page; delivery prices on /delivery |
| Checkout page | /checkout (links to the Terms and the Refund Policy under the pay button) |

The business name, CR number, address, email and phone on these pages come from Admin › Settings. Read the texts before going
live and change anything that does not match how you work.
