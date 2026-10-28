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

## SMSA Express (waybills, labels, tracking)

**Where:** Admin → Settings → **Shipping** (owner only).

### 1. Get the keys
Ask your SMSA account manager for access to the **SMSA eCommerce API** ("ecomapis"): a **sandbox** key, a **live** key, and your
**service code** (for example `EDDL`). The sandbox and live keys are different – a live key does not work on the sandbox.

### 2. Test (sandbox)
1. Paste the **sandbox** key. Fill in the **sender** (shop name, phone, city, full address – SMSA needs at least 10 characters).
2. Keep **Sandbox**, tick **Book shipments with SMSA from the order page**, Save, press **Test connection (sandbox)**.
   It lists your SMSA services – put the right one in **Service code** and save.
3. Open a **paid** order (a Tap test order is fine) → **Book SMSA shipment and get the label**.
   You get a waybill number and **Print label (PDF)**. **Update tracking** reads SMSA's scans.

### 3. Live
Paste the **live** key, test it, choose **Live**, Save (it asks you to confirm).
From now on every booked shipment is a **real, billable parcel** – SMSA collects it.

### Good to know
- Book the shipment from the order page once the order is paid. The label PDF is kept with the order.
- **Update tracking** moves the order to *Shipped* when SMSA picks it up (the customer gets a "your order is on its way" email
  with the tracking link) and to *Delivered* when SMSA delivers it.
- A shipment booked by mistake can be cancelled from the order page (managers).
- Customer addresses must give SMSA a name of 5+ letters, an address of 10+ characters and a city. The order page says what is missing.
- If SMSA gives you different server addresses, change them under *Server addresses* (normally not needed).

## Delivery prices
Also in Settings → **Shipping**: the fixed price per country (Bahrain 1.500 BHD) and the weight table for the other Gulf countries.
The website and the checkout use exactly these numbers, so the customer pays what the cart showed.
