# Currencies – the rules

**Every price and every total is kept in BHD.** BHD is the base currency (`currencies.base` setting).

## What the customer sees
- Each currency has a **rate**: how many of its units the customer pays for 1 BHD.
  The old shop charged 10 SAR / AED / QAR, 0.82 KWD and 1.1 OMR per 1 BHD; those are the starting rates.
- Each currency lists the **countries** that pay in it. A country in no list pays in BHD.
- Shown price = BHD price × rate, rounded to the currency's decimals (`Currency::fromBase`, `TZ.money` on the website).
- The owner edits rates in **Admin › Settings › Currencies**. The website picks them up at once (`/api/storefront`).

## Orders (Phase 3 onward)
Every order saves:
| Column | Meaning |
|---|---|
| `currency` | what the customer paid in, e.g. `SAR` |
| `fx_rate` | the rate used at checkout (never changes afterwards) |
| `total`, `subtotal`, `shipping`, `discount`, `vat` | **in BHD** – what reports add up |
| `paid_total`, `paid_shipping` … | in the customer's currency – what was charged and printed on their receipt |

The server computes both from the BHD prices; nothing the browser sends is trusted.
A refund is entered in the customer's currency and stored in both, using the order's own `fx_rate`.

## Admin, sales and reports
- Every amount on admin screens, the dashboard, sales and reports is **BHD**, summed from the BHD columns.
- Where it helps (order page, customer receipt) the customer's currency is shown next to it: `BHD 3.000 (SAR 30.00)`.
- Reports can be split "by currency paid" to see how much came in each currency, but the totals are still BHD.
- Changing a rate never rewrites old orders.
