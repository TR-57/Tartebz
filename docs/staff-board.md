# Orders board for the shop team (`/staff`)

A simple page on the website for the people who handle orders. It works on phones, tablets and computers.

## Give someone access
Admin → Settings → **Staff** → Add a person → role **Shop staff**. They sign in at **https://<your-domain>/staff**
(there is also a small "Team sign-in" link at the bottom of every page). Shop staff only see the orders board, never the admin.
Owners, managers and staff can use the board too.

## What they can do
| | |
|---|---|
| **See orders** | Tabs: *To prepare* (paid, not ticked), *Not paid*, *Prepared*, *Sent*, *All*. Filter by date (today, yesterday, 7 / 30 days, or two dates) and by country. Search by name, phone or order number. |
| **Tick** | The box at the start of each order = prepared. A paid order becomes *Preparing*; untick to undo. Who ticked it is saved. |
| **Open an order** | Customer, call / WhatsApp buttons, products, delivery fee, history, notes. **Print invoice** (A4 or receipt printer, Arabic or English). **Book SMSA and print the label**, update tracking, mark shipped / delivered. |
| **Not paid yet** | **Send the payment link** on WhatsApp (opens WhatsApp with a ready message) or by email, or **Mark as paid** when the money came by Benefit transfer / cash. |
| **New order** | For customers who order on WhatsApp: name, phone, country, city, address, products (+ / −). The **delivery fee is worked out by itself** from the weight and the country (the same prices as the website). Then choose: *Send a payment link* · *Paid already* · *Pays later*. Stock is kept for the order for as long as a WhatsApp order (Settings › Website). |

## Order sound and notifications
- **Sound:** every new order (a paid card order, or a WhatsApp order from the website) plays a chime, shows a yellow bar and
  repeats every minute (up to 5 times) until someone presses **Seen**. Orders the team creates do not ring.
- **On by default.** Browsers only allow sound after one tap on the page: signing in counts. After a page reload, a black bar
  *"Tap here to turn on the order sound"* shows until someone taps anywhere. No browser lets a website skip this.
- **Keep the screen on** (Settings on the board, on by default): while the board is open, the phone/tablet screen does not
  go to sleep, so the sound always plays. Best for a tablet at the packing table — keep it on its charger.
- **Phone / computer notifications** (Settings on the board → *Turn on*): a notification for every new order even when the
  board is closed or the screen is off. Works on Android (Chrome, Samsung Internet), Windows and Mac (Chrome, Edge, Firefox, Safari).
  - **iPhone / iPad:** open the board in Safari → Share → **Add to Home Screen** → open *Orders* from the Home Screen →
    Settings → *Turn on*. (Apple only allows notifications for websites added to the Home Screen, iOS 16.4 or newer.)
  - The notification uses the phone's normal notification sound. A website cannot play its own sound while the screen is off.
  - *Send a test* checks it works on that device.

## Server notes
- Nothing to set up: the notification keys are made on first use and kept in the settings (the private key encrypted).
- The PHP extension **bcmath** or **gmp** makes sending notifications faster (store:doctor says if both are missing).
