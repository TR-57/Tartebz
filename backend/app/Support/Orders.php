<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Order life cycle. Statuses:
 *   pending_payment → paid → processing → shipped → delivered
 *   awaiting_whatsapp (ordered on WhatsApp; staff confirm the payment by hand)
 *   payment_failed (the customer may try again) · cancelled · refunded
 * Paying is recorded once (row lock), stock is taken once, and a cancelled paid order gives its stock back.
 */
class Orders
{
    public const STATUSES = ['pending_payment', 'awaiting_whatsapp', 'payment_failed', 'paid', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded'];

    public const LABELS = [
        'pending_payment' => 'Awaiting payment', 'awaiting_whatsapp' => 'WhatsApp order', 'payment_failed' => 'Payment failed',
        'paid' => 'Paid', 'processing' => 'Preparing', 'shipped' => 'Shipped', 'delivered' => 'Delivered',
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
                'first_name' => $c['first_name'], 'last_name' => $c['last_name'], 'email' => $c['email'], 'phone' => $c['phone'],
                'country' => $quote['country'], 'state' => $c['state'], 'city' => $c['city'], 'address1' => $c['address1'], 'address2' => $c['address2'],
                'notes' => $c['notes'] ?: null, 'lang' => $c['lang'],
                'currency' => $quote['currency'], 'fx_rate' => $quote['rate'],
                'subtotal' => $quote['subtotal'], 'shipping' => $quote['shipping'], 'total' => $quote['total'],
                'charge_currency' => $quote['chargeCurrency'], 'charge_amount' => $quote['chargeAmount'],
                'weight_grams' => $quote['weightGrams'], 'ip' => $meta['ip'] ?? '', 'user_agent' => mb_substr($meta['ua'] ?? '', 0, 255),
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
            self::event($id, 'created', $method === 'whatsapp' ? 'Order placed – to be confirmed on WhatsApp.' : 'Order placed – waiting for the card payment.');

            return DB::table('orders')->find($id);
        });
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
            if (! $o || ($charge['id'] ?? '') !== $o->tap_charge_id || $o->payment_status === 'paid') {
                return;
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
        if ($o->payment_status === 'paid') {
            return;
        }
        DB::table('orders')->where('id', $o->id)->update([
            'status' => in_array($o->status, ['pending_payment', 'awaiting_whatsapp', 'payment_failed'], true) ? 'paid' : $o->status,
            'payment_status' => 'paid', 'paid_at' => now(), 'updated_at' => now(),
        ]);
        self::takeStock($o);
        self::event($o->id, 'paid', $message, $data, $admin);
        $fresh = DB::table('orders')->find($o->id);
        self::emailCustomer($fresh);
        self::emailOwners($fresh);
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

    private static function itemsTable(object $o, bool $ar): string
    {
        $rows = '';
        foreach (DB::table('order_items')->where('order_id', $o->id)->get() as $i) {
            $name = e($ar && $i->name_ar ? $i->name_ar : $i->name);
            $rows .= "<tr><td style=\"padding:6px 0;\">{$name} × {$i->qty}</td><td style=\"padding:6px 0;text-align:".($ar ? 'left' : 'right').";\"><bdi>".e(self::chargeMoney($o, (float) $i->line_total)).'</bdi></td></tr>';
        }
        $al = $ar ? 'left' : 'right';
        $rows .= '<tr><td style="padding:6px 0;border-top:1px solid #eee;">'.($ar ? 'التوصيل' : 'Delivery')."</td><td style=\"padding:6px 0;border-top:1px solid #eee;text-align:{$al};\"><bdi>".e(self::chargeMoney($o, (float) $o->shipping)).'</bdi></td></tr>';
        $rows .= '<tr><td style="padding:6px 0;font-weight:800;">'.($ar ? 'الإجمالي' : 'Total')."</td><td style=\"padding:6px 0;font-weight:800;text-align:{$al};\"><bdi>".e($o->charge_currency.' '.number_format((float) $o->charge_amount, in_array($o->charge_currency, ['BHD', 'KWD', 'OMR'], true) ? 3 : 2)).'</bdi></td></tr>';

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:14px 0;font-size:14px;">'.$rows.'</table>';
    }

    private static function chargeMoney(object $o, float $bhd): string
    {
        $dec = in_array($o->charge_currency, ['BHD', 'KWD', 'OMR'], true) ? 3 : 2;
        $rate = $o->charge_currency === 'BHD' ? 1 : (float) $o->fx_rate;

        return $o->charge_currency.' '.number_format(round($bhd * $rate, $dec), $dec);
    }

    public static function emailCustomer(object $o): void
    {
        if ($o->email === '') {
            return;
        }
        $ar = $o->lang !== 'en';
        $subject = $ar ? "تم استلام طلبك رقم {$o->number} 🎉" : "We’ve got your order {$o->number} 🎉";
        $body = ($ar
            ? '<h1 style="margin:0 0 10px;font-size:22px;">شكرًا '.e($o->first_name).'!</h1><p>استلمنا طلبك رقم <b>'.e($o->number).'</b> وتم الدفع بنجاح. بنجهّزه ونرسل لك رقم الشحنة أول ما يطلع.</p>'
            : '<h1 style="margin:0 0 10px;font-size:22px;">Thank you, '.e($o->first_name).'!</h1><p>We’ve received your order <b>'.e($o->number).'</b> and your payment. We’re preparing it and will send you the tracking number as soon as it ships.</p>')
            .self::itemsTable($o, $ar)
            .EmailLayout::button($ar ? 'عرض الطلب' : 'View your order', self::customerUrl($o));
        Outbox::queue($o->email, $subject, EmailLayout::wrap($ar ? 'ar' : 'en', $subject, $body), ['name' => trim($o->first_name.' '.$o->last_name)]);
    }

    public static function emailOwners(object $o): void
    {
        $to = DB::table('admins')->where('role', 'owner')->where('active', true)->where('order_emails', true)->pluck('email')->all();
        $extra = Settings::get('mail')['notifyEmail'] ?? '';
        if ($extra) {
            $to[] = $extra;
        }
        $subject = "New paid order {$o->number} – ".self::money($o, (float) $o->total);
        $body = '<h1 style="margin:0 0 10px;font-size:20px;">New order '.e($o->number).'</h1><p>'.e(trim($o->first_name.' '.$o->last_name)).' · '.e($o->phone).' · '.e($o->country).'</p>'
            .self::itemsTable($o, false).EmailLayout::button('Open the order', rtrim(config('store.admin_url'), '/').'/#/orders/'.$o->id);
        foreach (array_unique(array_filter($to)) as $email) {
            Outbox::queue($email, $subject, EmailLayout::wrap('en', $subject, $body));
        }
    }

    public static function emailShipped(object $o): void
    {
        if ($o->email === '' || $o->awb === '') {
            return;
        }
        $ar = $o->lang !== 'en';
        $track = 'https://www.smsaexpress.com/trackingdetails?tracknumbers='.rawurlencode($o->awb);
        $subject = $ar ? "طلبك {$o->number} في الطريق 🚚" : "Your order {$o->number} is on its way 🚚";
        $body = ($ar
            ? '<h1 style="margin:0 0 10px;font-size:22px;">طلبك في الطريق!</h1><p>شحنّا طلبك رقم <b>'.e($o->number).'</b> مع سمسا. رقم الشحنة: <b><bdi>'.e($o->awb).'</bdi></b></p>'
            : '<h1 style="margin:0 0 10px;font-size:22px;">Your order is on its way!</h1><p>We’ve shipped order <b>'.e($o->number).'</b> with SMSA. Tracking number: <b>'.e($o->awb).'</b></p>')
            .EmailLayout::button($ar ? 'تتبّع الشحنة' : 'Track the parcel', $track);
        Outbox::queue($o->email, $subject, EmailLayout::wrap($ar ? 'ar' : 'en', $subject, $body));
    }
}
