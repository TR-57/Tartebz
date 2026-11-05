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

Prices are set per **destination**: one country, a group of countries (e.g. GCC: SA, AE, QA, KW, OM), or `*` for
"everywhere else". Each destination has its own **delivery methods** – for example *By air (SMSA)* and *By road (SMSA)* – and
the customer picks one at checkout (the staff board picks it for team-made orders). Each method has:

| Field | Meaning |
|---|---|
| Name (English / Arabic) | What the customer sees |
| How it is priced | **Fixed price**; **Base price + per extra weight** (base covers the first X kg, every further step adds Y); or a **Weight table** (up to 0.5 kg → 3.800 …, above the last row every step adds Y) |
| Lowest / highest price | Optional floor and ceiling |
| Free when the order reaches | Optional free delivery over an order value |
| Not offered above (kg) | Hides the method for heavier parcels |
| Delivery time from / to (days) | Shown next to the price |
| On / Off | Off hides it from customers |

All prices are BHD; customers see them in their own currency. The cart, the checkout and the server use the same formula,
so the customer pays exactly what the cart showed. "Price check" at the bottom shows the price for any country and weight.

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
3. **Delivery methods sent with SMSA**: tick the methods whose paid orders go through SMSA. For each method going **abroad**
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
- SMSA **refused** it (address too short, unknown city …) → the order shows why; it is tried again 10 minutes later, 3 times at
  most. Fix the address and press **Try again**. The SMSA screen lists refused orders.
- Tracking is read **every hour**: the scans go on the order's history; the order becomes **Shipped** when SMSA has the parcel
  and **Delivered** when it is delivered (switch off "Update the order status from SMSA tracking" to do it by hand).
- Any paid order can be **sent to SMSA by hand** from the order (boxes and weight can be changed), and a waybill can be
  **cancelled** by a manager before SMSA picks it up.

### Printing
- **Print SMSA label** on the order: the label as SMSA made it, or with a *part of the value* printed as the declared value
  (on that copy only – SMSA keeps the full value).
- **Print** on an order going **outside Bahrain** prints the **invoice followed by the SMSA waybill** (4 × 6 in label page).
  A Bahrain order prints the invoice only.
- In the order list, tick several orders: **Print invoices (+ SMSA waybills abroad)**, **SMSA labels (one PDF)** or
  **Send to SMSA**.
- The staff board (/staff) has the same print button and SMSA panel on each order.
