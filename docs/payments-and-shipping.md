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
- Payment methods shown on Tap's page (Apple Pay, Benefit, mada, KNET…) are switched on **in your Tap account**. Apple Pay also needs
  your domain verified with Tap – ask Tap support.
- **Currencies:** by default everyone is charged in **BHD** (the same price, converted). If your Tap account can settle SAR, AED, etc.,
  tick them under *Charge in these currencies* – Saudi customers are then charged in SAR. Reports always add up in BHD.
- **Refunds** are done in the Tap dashboard. Then cancel the order in the admin (gives the stock back).
- Tap sends updates to `https://<your-domain>/api/payments/tap/webhook`. The website includes this address on every payment,
  so you normally do not need to enter it in Tap.

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
