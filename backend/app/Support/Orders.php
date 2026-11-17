<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Order life cycle. Statuses:
 *   pending_payment → paid → processing → shipped → delivered
 *   awaiting_whatsapp (ordered on WhatsApp; staff confirm the payment by hand)
 *   payment_failed (the customer may try again) · cancelled · refunded (the whole payment given back – see Refunds)
 * Paying is recorded once (row lock), stock is taken once, and a cancelled paid order gives its stock back.
 */
class Orders
{
    public const STATUSES = ['pending_payment', 'awaiting_whatsapp', 'payment_failed', 'paid', 'processing', 'ready', 'shipped', 'delivered', 'cancelled', 'refunded'];

    public const LABELS = [
        'pending_payment' => 'Awaiting payment', 'awaiting_whatsapp' => 'WhatsApp order', 'payment_failed' => 'Payment failed',
        'paid' => 'Paid', 'processing' => 'Preparing', 'ready' => 'Ready to collect', 'shipped' => 'Shipped', 'delivered' => 'Delivered',
        'cancelled' => 'Cancelled', 'refunded' => 'Refunded',
    ];

    /** Creates the order from a server quote and the checked customer details. */
    public static function create(array $quote, array $c, string $method, array $meta = []): object
    {
        return DB::transaction(function () use ($quote, $c, $method, $meta) {
            $id = DB::table('orders')->insertGetId([
                'token' => bin2hex(random_bytes(20)),
                'status' => $method === 'whatsapp' ? 'awaiting_whatsapp' : 'pending_payment',
                'payment_method' => $method, 'payment_status' => 'unpaid',
                'source' => $meta['source'] ?? 'website',
                'created_by' => $meta['admin']->id ?? null, 'created_by_name' => $meta['admin']->name ?? '',
                'first_name' => $c['first_name'], 'last_name' => $c['last_name'], 'email' => $c['email'], 'phone' => $c['phone'],
                'country' => $quote['country'], 'state' => $c['state'], 'city' => $c['city'], 'address1' => $c['address1'], 'address2' => $c['address2'],
                'notes' => $c['notes'] ?: null, 'lang' => $c['lang'],
                'currency' => $quote['currency'], 'fx_rate' => $quote['rate'],
                'subtotal' => $quote['subtotal'], 'shipping' => $quote['shipping'], 'total' => $quote['total'],
                'discount' => $quote['discount'] ?? 0, 'coupon_id' => ($quote['couponRow'] ?? null)?->id, 'coupon_code' => (string) (($quote['couponRow'] ?? null)?->code ?? ''),
                'charge_currency' => $quote['chargeCurrency'], 'charge_amount' => $quote['chargeAmount'],
                'weight_grams' => $quote['weightGrams'], 'ip' => $meta['ip'] ?? '',
                'shipping_method' => (string) ($quote['method']['id'] ?? ''), 'shipping_method_name' => (string) ($quote['method']['name']['en'] ?? ''),
                'shipping_method_name_ar' => (string) ($quote['method']['name']['ar'] ?? ''), 'user_agent' => mb_substr($meta['ua'] ?? '', 0, 255),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $prefix = (string) (Settings::get('orders')['numberPrefix'] ?? '');
            $first = (int) (Settings::get('orders')['firstNumber'] ?? 20001);
            DB::table('orders')->where('id', $id)->update(['number' => $prefix.($first + $id - 1)]);
            foreach ($quote['lines'] as $l) {
                DB::table('order_items')->insert([
                    'order_id' => $id, 'product_id' => $l['id'], 'name' => $l['name'], 'name_ar' => $l['nameAr'], 'image' => $l['image'],
                    'price' => $l['price'], 'qty' => $l['qty'], 'line_total' => $l['lineTotal'], 'weight_grams' => $l['weightGrams'],
                ]);
            }
            if (($meta['source'] ?? 'website') === 'staff') {
                self::event($id, 'created', 'Order created by '.($meta['admin']->name ?? 'the team').($method === 'tap' ? ' – the customer pays with a payment link.' : '.'), [], $meta['admin'] ?? null);
            } else {
                self::event($id, 'created', $method === 'whatsapp' ? 'Order placed – to be confirmed on WhatsApp.' : 'Order placed – waiting for the card payment.');
                if ($method === 'whatsapp') {
                    self::alert($id); // the team must act on it now; card orders alert once paid
                    DB::afterCommit(function () use ($id) {
                        $o = DB::table('orders')->find($id);
                        OrderMail::received($o);
                        OrderMail::staffNewOrder($o);
                    });
                }
            }

            return DB::table('orders')->find($id);
        });
    }

    /**
     * The order now needs the team: it shows as new on the orders board (sound) and every board user's phone /
     * computer gets a notification – sent after the database transaction is saved.
     */
    public static function alert(int $orderId): void
    {
        DB::table('orders')->where('id', $orderId)->update(['alert_at' => now()]);
        // Sent after the answer has gone to the customer, so checkout never waits for it.
        DB::afterCommit(fn () => \Illuminate\Support\defer(fn () => Push::newOrder($orderId)));
    }

    public static function event(int $orderId, string $type, string $message, array $data = [], ?object $admin = null): void
    {
        DB::table('order_events')->insert([
            'order_id' => $orderId, 'type' => $type, 'message' => mb_substr($message, 0, 500),
            'data' => $data ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'admin_id' => $admin->id ?? null, 'admin_name' => $admin->name ?? '', 'created_at' => now(),
        ]);
    }

    /**
     * Applies a charge fetched from Tap (never a browser or webhook body). Returns the fresh order.
     * CAPTURED with the right amount and currency → paid; a final failure → payment_failed; anything else waits.
     */
    public static function applyTapCharge(int $orderId, array $charge): object
    {
        DB::transaction(function () use ($orderId, $charge) {
            $o = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            $status = strtoupper((string) ($charge['status'] ?? ''));
            if (! $o || ($charge['id'] ?? '') !== $o->tap_charge_id || $o->paid_at) {
                return; // paid once already (later refunds must not turn it back into "paid")
            }
            DB::table('orders')->where('id', $o->id)->update(['tap_status' => $status, 'updated_at' => now()]);
            if ($status === 'CAPTURED') {
                $amountOk = abs((float) ($charge['amount'] ?? 0) - (float) $o->charge_amount) < 0.0005
                    && strtoupper((string) ($charge['currency'] ?? '')) === $o->charge_currency;
                if (! $amountOk) {
                    self::event($o->id, 'payment_mismatch', 'Tap says paid, but the amount or currency is not the order’s. Check it in the Tap dashboard before shipping.',
                        ['charged' => [$charge['amount'] ?? null, $charge['currency'] ?? null], 'expected' => [(float) $o->charge_amount, $o->charge_currency]]);

                    return;
                }
                self::markPaid($o, 'Paid by card through Tap ('.($charge['source']['payment_method'] ?? $charge['source']['id'] ?? 'card').').',
                    ['charge' => $charge['id'], 'reference' => $charge['reference']['payment'] ?? null]);
            } elseif (in_array($status, Tap::FAILED, true)) {
                if ($o->status === 'pending_payment') {
                    DB::table('orders')->where('id', $o->id)->update(['status' => 'payment_failed', 'payment_status' => 'failed', 'updated_at' => now()]);
                    $why = trim((string) ($charge['response']['message'] ?? ''));
                    self::event($o->id, 'payment_failed', 'Card payment not completed ('.$status.($why !== '' ? ': '.$why : '').').');
                }
            }
        });

        return DB::table('orders')->find($orderId);
    }

    /** Records payment once: status, stock, emails. $o must be locked by the caller's transaction. */
    public static function markPaid(object $o, string $message, array $data = [], ?object $admin = null): void
    {
        if ($o->paid_at) {
            return;
        }
        DB::table('orders')->where('id', $o->id)->update([
            'status' => in_array($o->status, ['pending_payment', 'awaiting_whatsapp', 'payment_failed'], true) ? 'paid' : $o->status,
            'payment_status' => 'paid', 'paid_at' => now(), 'updated_at' => now(),
        ]);
        self::takeStock($o);
        Holds::releaseOrder($o->id);
        if ($o->source !== 'staff' && ! $o->alert_at) {
            self::alert($o->id);
        }
        self::event($o->id, 'paid', $message, $data, $admin);
        if ($o->status === 'cancelled') {
            self::event($o->id, 'paid_after_cancel', 'This order was cancelled before the payment arrived. Send it, or refund the payment.');
        }
        $fresh = DB::table('orders')->find($o->id);
        Coupons::countUse($fresh);
        Carts::orderPaid($fresh);
        DB::afterCommit(function () use ($fresh, $o, $admin) {
            OrderMail::paid($fresh);
            if ($o->payment_method === 'whatsapp' || $o->source === 'staff') {
                // the team heard about it when it was placed: now just "it's paid"
                OrderMail::staffUpdate($fresh, 'Paid'.($admin ? ' (marked by hand)' : '').' – '.Fmt::money((float) $fresh->charge_amount, $fresh->charge_currency), $admin, "update:paid:{$fresh->id}");
            } else {
                OrderMail::staffNewOrder($fresh);
            }
        });
    }

    public static function takeStock(object $o): void
    {
        if ($o->stock_taken) {
            return;
        }
        foreach (DB::table('order_items')->where('order_id', $o->id)->get() as $i) {
            if ($i->product_id) {
                DB::table('products')->where('id', $i->product_id)->where('manage_stock', true)->where('type', 'simple')
                    ->update(['stock_qty' => DB::raw('stock_qty - '.(int) $i->qty), 'updated_at' => now()]);
            }
        }
        DB::table('products')->where('manage_stock', true)->where('type', 'simple')->where('stock_qty', '<=', 0)->update(['in_stock' => false]);
        DB::table('orders')->where('id', $o->id)->update(['stock_taken' => true]);
        // Paid after its reservation ran out and someone else bought the piece meanwhile: tell the team.
        $short = DB::table('order_items')->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('order_items.order_id', $o->id)->where('products.manage_stock', true)->where('products.stock_qty', '<', 0)->pluck('products.name')->all();
        if ($short) {
            self::event($o->id, 'oversold', 'Stock went below zero for: '.implode(', ', $short).'. Check you can send everything before shipping.');
        }
    }

    public static function giveBackStock(object $o): void
    {
        if (! $o->stock_taken) {
            return;
        }
        foreach (DB::table('order_items')->where('order_id', $o->id)->get() as $i) {
            if ($i->product_id) {
                $was = DB::table('products')->find($i->product_id);
                DB::table('products')->where('id', $i->product_id)->where('manage_stock', true)->where('type', 'simple')
                    ->update(['stock_qty' => DB::raw('stock_qty + '.(int) $i->qty), 'in_stock' => true, 'updated_at' => now()]);
                if ($was) {
                    StockAlerts::afterChange($i->product_id, Catalog::available($was));
                }
            }
        }
        DB::table('orders')->where('id', $o->id)->update(['stock_taken' => false]);
    }

    /** "BHD 3.000" or, for a customer who paid in another currency, "BHD 3.000 (SAR 30.00)". */
    public static function money(object $o, float $bhd): string
    {
        $s = 'BHD '.number_format($bhd, 3);
        if ($o->currency !== 'BHD') {
            $c = Currency::all()[$o->currency] ?? ['decimals' => 2];
            $s .= ' ('.$o->currency.' '.number_format(round($bhd * (float) $o->fx_rate, (int) $c['decimals']), (int) $c['decimals']).')';
        }

        return $s;
    }

    public static function customerUrl(object $o): string
    {
        return config('store.store_url').'/checkout?order='.$o->token.($o->lang === 'en' ? '&lang=en' : '');
    }

    /** Kept for older callers: the confirmation, the team, tracking, delivered, shipped. */
    public static function emailCustomer(object $o): void
    {
        OrderMail::paid($o);
    }

    public static function emailOwners(object $o): void
    {
        OrderMail::staffNewOrder($o);
    }

    public static function emailTracking(?object $o): void
    {
        OrderMail::tracking($o);
    }

    public static function emailDelivered(?object $o): void
    {
        if ($o) {
            OrderMail::status($o, 'delivered');
        }
    }

    public static function emailShipped(object $o): void
    {
        OrderMail::status($o, 'shipped');
    }
}
