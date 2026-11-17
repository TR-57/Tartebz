# Emails

Every email the shop sends, what sends it, who gets it, and where to change it in the admin.

All emails come from the address in **Settings › Email sending** (default `sales@tartebz.com`, name “Tartebz | ترتيبز”).
Their words live in **Marketing › Emails**: each email has an Arabic and an English version you can edit, preview and test.
Customers get their own language (the one they used on the site). When it is unknown, **Emails › Settings › “When a
customer’s language is unknown”** picks Arabic, English or both in one email. Store emails use the team language set there.

## 1. Sending: SMTP or the website itself

**Settings › Email sending** (owners only):

| Emails come from | When |
|---|---|
| SMTP (mail server) | The host is filled in. Recommended. |
| SMTP from the server file (`backend/.env`) | The fields are empty but `MAIL_HOST` is set in `.env`. |
| The website itself | No SMTP anywhere. Uses the server’s own mail program (PHP sendmail); works on Hostinger but lands in spam more often. |

**Hostinger setup (5 minutes)**

1. In Hostinger › Emails, make sure the mailbox `sales@tartebz.com` exists, and note its password.
2. In the admin: Settings › Email sending › **Fill in Hostinger’s settings**. That fills `smtp.hostinger.com`, port `465`, SSL and the
   username (the full address).
3. Type the mailbox password and **Save**. It is stored encrypted and never shown again; it is never written to logs.
4. **Send test email** to yourself. If it fails, the message says why (wrong password, port blocked, From address not a Hostinger mailbox…).

Port 587 with TLS also works if 465 is blocked.

**So emails land in the inbox** (Hostinger › Emails › your domain › DNS / “Email deliverability”; Hostinger usually adds these):

- **SPF** – TXT on the domain: `v=spf1 include:_spf.mail.hostinger.com ~all` (one SPF record per domain only).
- **DKIM** – the TXT records Hostinger shows (names like `hostingermail-a._domainkey`).
- **DMARC** – TXT named `_dmarc`: `v=DMARC1; p=none; rua=mailto:sales@tartebz.com` (tighten to `p=quarantine` later).

## 2. How sending works

- Every email first goes into the **outbox** (`mail_outbox`), then is sent right after the page answers – checkout is never slowed.
- If sending fails it is tried again after 1, 5, 30, 120 and 360 minutes (6 tries in all). **Emails › Sent emails** shows every
  email, its status and error, with a **Retry** button.
- Each event can send its email only once (a duplicate Tap notice never sends a second “paid” email).
- Offers (campaigns and abandoned-cart emails) carry an unsubscribe link and one-click unsubscribe headers. Order emails
  are always sent. Unsubscribed people are skipped even if an email was already queued.
- Logs show masked addresses (`sa***@example.com`), never passwords, keys or card data.

**Cron** – the one Hostinger cron job (`docs/hostinger.md`) runs the Laravel scheduler every minute. It already includes:

| Job | Every | Does |
|---|---|---|
| `store:emails` | minute | retries, one campaign batch (40), payment reminders, review requests, the morning reviews digest (after 9 am Bahrain time) |
| `store:emails --carts` | 5 minutes | abandoned-cart and unpaid-order emails |

## 3. Every email

“Optional” emails can be switched off in **Marketing › Emails** (the switch on each row). The others always go.

### Orders – to the customer

| Email | Sent when | Optional | Admin settings |
|---|---|---|---|
| Order received (not paid online) | A customer orders on WhatsApp (they pay outside the website). Includes how to pay. | No | Emails › Settings: “How to pay” text (AR/EN) |
| Order confirmation + invoice | The order is paid: Tap confirms the card payment, or staff mark it paid. Once per order. Links to the invoice page. | No | – |
| Payment reminder | An unpaid card order, N minutes after it was placed, once, while its items are still held. | Yes | Emails › Settings: minutes (default 30) |
| Payment link | Staff press “Payment link…” on the order page (or use the staff board): copy the link, send it on WhatsApp with the message written, or email it. | No | – |
| Status: preparing | Staff move the order to Preparing with “Email the customer” ticked. | Yes | The tick on the order page |
| Status: ready to collect | Staff set “Ready to collect” (pick-up orders). | Yes | The tick |
| Status: shipped | Staff mark shipped, or SMSA picks the parcel up. | Yes | The tick |
| Tracking number | The SMSA waybill is created (once per waybill). | Yes | – |
| Status: delivered | Staff mark delivered, or SMSA tracking says delivered. | Yes | The tick |
| Status: cancelled | Staff cancel with “Email the customer” ticked. Shows the optional message to the customer (the team reason stays inside). | Yes | The cancel window |
| Refund | A refund is confirmed (full or part, Tap or recorded by hand). | Yes | – |
| Review request | N days after the order was shipped, delivered or collected; once per paid order (last 30 days only). Five star buttons, each opens the review form. | Yes | Emails › Settings: days (default 3), “Ask for a review by email” |
| Back in stock | A product someone asked about is available again. | Yes | Settings › Website: back-in-stock alerts |

**Invoice** – a web page at `/api/invoice/{order token}?s={signature}` (the link in the emails; it does not work without the
signature). Before payment it is a *proforma invoice*, after payment an *invoice*; it shows the coupon, refunds and balance due,
switches language, and **Download PDF** prints it to PDF from the browser. No VAT lines: the shop does not charge VAT (add them
in `partials/invoice-sheet.blade.php` if that changes).

### Abandoned carts and unpaid orders – to the customer

Off until switched on in **Marketing › Abandoned carts**; only carts left after switching on are emailed. A cart is saved once
the customer types an email at checkout.

| Email | Sent when |
|---|---|
| Cart email 1 – reminder | Wait 1 after the cart was left (default 1 hour) |
| Cart email 2 – second reminder | Wait 2 after email 1 (default 24 hours). A plain reminder by default. |
| Cart email 2 – with a code | Only if you switch on “Give a personal code in email 2” (off by default): a code locked to their email, one use, ends after N days (default 7), not given again within the cooldown (default 30 days). |
| Cart email 3 – last reminder | Wait 3 after email 2 (default 3 days). |
| Cart email 3 – your code ends soon | Instead of the last reminder, when they got a code that is still unused. |
| Unpaid order emails 1–3 (with / without code) | The same series for card orders whose payment was not finished and whose hold ran out (last 3 days), worded for an unfinished order. Orders staff cancel are never emailed. |

Each email links to `/checkout?restore={token}`, which rebuilds the same cart (what is still on sale) and fills in the code on any
device.

**The series stops** when: the cart becomes an order (or the customer places any other order), the unpaid order is paid or
cancelled, a newer cart takes over (it carries the series and code on), the cart is emptied, they unsubscribe, or it is more than
30 days old. Each step can be switched off; the screen shows emails sent, carts and sales recovered, and codes used.

### Offers – to subscribers and customers

| Email | Sent when | Admin |
|---|---|---|
| Campaign | The owner sends a campaign: all customers who paid + consenting subscribers, or chosen countries, or a pasted list – always minus unsubscribed. Sent in batches of 40 a minute, each in the person’s language with their name. | Marketing › Campaigns (write, preview, test to yourself, send) |

### Store alerts – to the team

| Email | Sent when | To |
|---|---|---|
| New order | An order is paid, or a WhatsApp order arrives. Reply-To is the customer. | The store notification addresses + owners and managers with “Email me about orders” on |
| Order update | An order is paid (WhatsApp/staff orders), changes status, is cancelled or refunded – not to the person who did it. | Owners with “Email me about orders” on |
| Reviews waiting | Once a day (morning) when new reviews wait for approval. | Store notification addresses + owners |

Store notification addresses: **Settings › Email sending**. “Email me about orders”: **My account** (avatar at the top).

## 4. Business details

**Settings › Business details** (owners): shop name (EN/AR), logo, legal name, CR number, VAT number, address (EN/AR), area,
phone, WhatsApp, email, Instagram, TikTok, Google Maps link and time zone. They are used on the website (header, footer, contact
page), in every email footer and on invoices – there is nothing to change in the code.

## 5. Discount codes

**Marketing › Discounts & coupons**: percentage, fixed amount (BHD) or free delivery; minimum order, largest discount, start and
end, total uses, uses per customer, locked to emails, limited to categories or products. The server checks the code on every
checkout price and again when the order is placed (the browser’s total is never trusted). A use counts when the order is paid
and comes back if the order is cancelled. Customers see why a code does not work, in their language (not found, expired, minimum
not reached, for another email, used up, already used, not for these products, delivery already free).

## 6. Reviews

Only from buyers, through the link in the review-request email (signed per order and product). Reviews wait for approval in
**Marketing › Reviews**; approved ones show on the product page with the average. Switch reviews off in Emails › Settings.

## 7. Not built (and why)

- **Account emails** (welcome, password reset, account changes): the shop has no customer accounts – customers check out as guests.
- **“Your order was changed” email**: staff cannot edit an order’s items or prices after it is placed, so nothing triggers it.
- **Enquiry, contact-form, quote or B2B emails**: left out on purpose.
- **Pop-up discount for a first order**: removed at the owner’s request.
- **Tax breakdown on the invoice**: VAT is not charged.

## 8. Tests

`backend/tests/Feature/EmailSystemTest.php` covers each trigger above: WhatsApp and paid orders (a duplicate Tap notice sends one
email), each status with and without the tick, the payment reminder, the three cart emails with and without a code, every stop
rule, unpaid-order recovery, unsubscribe (link and one-click), campaign batches, coupon checks
and use counting, the outbox (dedupe and back-off), tracking, refunds, payment links, back-in-stock, reviews, the signed invoice
page, the mail settings (owner only, password never shown), business details, plain cart reminders by default and the payment-link options.
