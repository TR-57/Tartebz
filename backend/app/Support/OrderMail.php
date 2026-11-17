<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Every email about an order: what each one says comes from EmailTemplates; this class fills in the order's details
 * (lines with pictures, totals, payment, delivery, links) and sends each email once per event.
 */
class OrderMail
{
    // ---------- customer ----------

    /** Ordered on WhatsApp: not paid online yet – how to pay. */
    public static function received(object $o): void
    {
        self::toCustomer('order_received', $o, "order_received:{$o->id}", 'Customer emailed: order received (how to pay).');
    }

    /** Paid (card or by hand): the confirmation with the invoice. Once per order, whatever calls it twice. */
    public static function paid(object $o): void
    {
        self::toCustomer('order_paid', $o, "order_paid:{$o->id}", 'Customer emailed the order confirmation and invoice.');
    }

    /** Staff pressed "Re-send confirmation". */
    public static function resend(object $o, ?object $admin = null): int
    {
        $key = $o->paid_at ? 'order_paid' : ($o->payment_method === 'whatsapp' ? 'order_received' : 'payment_link');
        $id = self::toCustomer($key, $o, null, 'Confirmation emailed again'.($admin ? ' by '.$admin->name : '').'.');
        if ($id && $admin) {
            Orders::event($o->id, 'email_resend', 'Confirmation re-sent to '.$o->email.'.', [], $admin);
        }

        return $id;
    }

    public static function reminder(object $o): int
    {
        return self::toCustomer('payment_reminder', $o, "payment_reminder:{$o->id}", 'Customer emailed a payment reminder.');
    }

    public static function paymentLink(object $o, string $payUrl): int
    {
        return self::toCustomer('payment_link', $o, null, 'Customer emailed the payment link.', ['url' => $payUrl]);
    }

    /** processing | shipped | ready | delivered | cancelled. One email per order and status. */
    public static function status(object $o, string $status, string $reason = ''): int
    {
        $key = 'status_'.$status;
        if (! isset(EmailTemplates::all()[$key])) {
            return 0;
        }
        $what = ['processing' => 'preparing', 'shipped' => 'shipped', 'ready' => 'ready to collect', 'delivered' => 'delivered', 'cancelled' => 'cancelled'][$status];

        return self::toCustomer($key, $o, "{$key}:{$o->id}", "Customer emailed: {$what}.", ['reason' => $reason]);
    }

    public static function tracking(?object $o): int
    {
        if (! $o || $o->awb === '') {
            return 0;
        }

        return self::toCustomer('tracking_number', $o, "tracking_number:{$o->id}:{$o->awb}", 'Customer emailed the tracking number.');
    }

    public static function refunded(object $o, object $rf): int
    {
        $full = (float) $o->refunded_amount + 0.0005 >= (float) $o->charge_amount;

        return self::toCustomer('status_refunded', $o, "status_refunded:{$rf->id}", 'Customer emailed: refund of '.$rf->currency.' '.$rf->amount.'.', [
            'refund' => $rf, 'full' => $full,
        ]);
    }

    public static function reviewRequest(object $o): int
    {
        return self::toCustomer('review_request', $o, "review_request:{$o->id}", 'Customer asked for a review.');
    }

    // ---------- the team ----------

    /** A paid order, or a WhatsApp order to act on: to the store addresses and owners/managers who chose order emails. Reply-To = the customer. */
    public static function staffNewOrder(object $o): void
    {
        $lang = self::staffLang();
        $c = self::context($o, $lang, 'staff_new_order');
        foreach (self::staffRecipients(['owner', 'manager']) as $email) {
            EmailSender::send('staff_new_order', $email, $lang, $c + [
                'dedupe' => "staff_new_order:{$o->id}:".strtolower($email), 'replyTo' => $o->email, 'url' => self::adminLink($o),
            ]);
        }
    }

    /** "What changed and who did it", to owners – never to the person who did it. */
    public static function staffUpdate(object $o, string $change, ?object $admin = null, ?string $dedupe = null): void
    {
        if (! EmailTemplates::enabled('staff_order_update')) {
            return;
        }
        $lang = self::staffLang();
        $who = $admin->name ?? ($lang === 'ar' ? 'النظام' : 'the system');
        $to = DB::table('admins')->where('role', 'owner')->where('active', true)->where('order_emails', true)
            ->when($admin, fn ($q) => $q->where('id', '<>', $admin->id))->pluck('email')->all();
        foreach (array_unique($to) as $email) {
            EmailSender::send('staff_order_update', $email, $lang, [
                'vars' => ['order_number' => $o->number, 'change' => $change, 'who' => $who, 'admin_link' => self::adminLink($o)],
                'url' => self::adminLink($o), 'dedupe' => $dedupe ? "{$dedupe}:".strtolower($email) : null,
            ]);
        }
    }

    public static function staffRecipients(array $roles): array
    {
        $admins = DB::table('admins')->whereIn('role', $roles)->where('active', true)->where('order_emails', true)->pluck('email')->all();

        return array_values(array_unique(array_map('strtolower', array_merge(Mailer::notifyAddresses(), $admins))));
    }

    public static function staffLang(): string
    {
        return (Settings::get('emails')['staffLang'] ?? 'en') === 'ar' ? 'ar' : 'en';
    }

    // ---------- building blocks ----------

    private static function toCustomer(string $key, object $o, ?string $dedupe, string $event, array $extra = []): int
    {
        if (! filter_var($o->email, FILTER_VALIDATE_EMAIL)) {
            return 0;
        }
        $lang = EmailSender::langFor($o->lang);
        $c = self::context($o, $lang === 'both' ? 'ar' : $lang, $key, $extra);

        return EmailSender::send($key, $o->email, $lang, $c + [
            'dedupe' => $dedupe, 'orderId' => $o->id, 'event' => $event, 'name' => trim($o->first_name.' '.$o->last_name),
        ]);
    }

    /** vars, blocks and the main link for one email about one order. */
    public static function context(object $o, string $lang, string $key, array $extra = []): array
    {
        $ar = $lang === 'ar';
        $biz = Settings::get('business');
        $e = Settings::get('emails');
        $m = fn (float $bhd) => Fmt::orderMoney($o, $bhd, $lang);
        $total = Fmt::money((float) $o->charge_amount, $o->charge_currency, $lang);
        $due = Fmt::money(max(0, (float) $o->charge_amount - ($o->paid_at ? (float) $o->charge_amount : 0)), $o->charge_currency, $lang);
        $held = Holds::orderHeldUntil($o->id);
        $track = $o->awb !== '' ? Smsa::TRACK_URL.rawurlencode($o->awb) : '';
        $method = $ar ? ($o->shipping_method_name_ar ?: $o->shipping_method_name) : $o->shipping_method_name;
        $mm = Shipping::findMethod((string) $o->shipping_method);
        $eta = $mm ? Shipping::etaText($mm, $lang) : '';
        $country = $o->country;
        $address = trim(implode(', ', array_filter([$o->address1, $o->address2, $o->city, $o->state, $country])));
        $delivery = trim(implode(' · ', array_filter([$method, $eta, $mm && ($mm['pricing'] ?? '') === 'quoted' && (float) $o->shipping == 0.0 ? ($ar ? 'يُحدَّد سعر التوصيل بعد الطلب' : 'delivery price to follow') : '', $address])));
        $paidNote = $o->paid_at
            ? ($ar ? $total.' – مدفوع'.($o->payment_method === 'tap' ? ' بالبطاقة' : '') : $total.' paid'.($o->payment_method === 'tap' ? ' by card' : ''))
            : '';
        $rf = $extra['refund'] ?? null;
        $vars = [
            'name' => $o->first_name, 'order_number' => (string) $o->number, 'order_total' => $total, 'invoice_link' => self::invoiceUrl($o, $lang),
            'payment_note' => $key === 'order_received' ? (string) ($ar ? ($e['payNote_ar'] ?? '') : ($e['payNote'] ?? '')) : $paidNote,
            'pay_methods' => (string) ($ar ? ($e['payMethods_ar'] ?? '') : ($e['payMethods'] ?? '')),
            'amount_due' => $due, 'held_until' => $held ? Fmt::dateTime($held, $lang) : '',
            'tracking_number' => (string) $o->awb, 'tracking_link' => $track, 'store_phone' => (string) ($biz['phone'] ?? ''),
            'store_address' => trim(implode(', ', array_filter([$ar ? ($biz['addressLine1_ar'] ?: $biz['addressLine1']) : $biz['addressLine1'], $biz['area'] ?? '']))),
            'delivery' => $delivery, 'reason' => (string) ($extra['reason'] ?? ''),
            'refund_amount' => $rf ? Fmt::money((float) $rf->amount, $rf->currency, $lang) : '',
            'refund_kind' => $rf ? (($extra['full'] ?? false) ? ($ar ? 'استرجاع كامل' : 'full refund') : ($ar ? 'استرجاع جزئي' : 'part refund')) : '',
            'customer' => trim($o->first_name.' '.$o->last_name).' · '.$o->phone.' · '.$o->email, 'admin_link' => self::adminLink($o),
        ];
        $blocks = ['items' => self::items($o, $lang, $m)];
        if ($key === 'review_request') {
            $blocks['review_items'] = Reviews::starsBlock($o, $lang);
        }
        $url = match ($key) {
            'payment_reminder' => self::customerUrl($o),
            'payment_link' => (string) ($extra['url'] ?? self::customerUrl($o)),
            'status_shipped', 'tracking_number' => $track ?: self::customerUrl($o),
            'staff_new_order' => self::adminLink($o),
            default => self::customerUrl($o),
        };

        return ['vars' => $vars, 'blocks' => $blocks, 'url' => $url];
    }

    /** The order lines with pictures, then subtotal, discount (coupon by name), delivery and total. */
    public static function items(object $o, string $lang, ?callable $m = null): array
    {
        $ar = $lang === 'ar';
        $m ??= fn (float $bhd) => Fmt::orderMoney($o, $bhd, $lang);
        $t = EmailLayout::theme();
        $end = $ar ? 'left' : 'right';
        $start = $ar ? 'right' : 'left';
        $rows = '';
        $text = [];
        foreach (DB::table('order_items')->where('order_id', $o->id)->orderBy('id')->get() as $i) {
            $name = $ar && $i->name_ar ? $i->name_ar : $i->name;
            $img = $i->image ? EmailLayout::abs(Thumbs::url($i->image, 200)) : '';
            $pic = $img !== '' ? '<img src="'.e($img).'" alt="'.e($name).'" width="56" height="56" style="display:block;width:56px;height:56px;object-fit:cover;border-radius:10px;border:1px solid '.$t['line'].';">' : '';
            $rows .= '<tr><td width="64" style="padding:8px 0;vertical-align:middle;">'.$pic.'</td>'
                .'<td class="em-text" style="padding:8px 10px;vertical-align:middle;font-size:15px;text-align:'.$start.';"><b>'.e($name).'</b><br><span class="em-muted" style="color:'.$t['muted'].';font-size:14px;">'
                .($ar ? 'الكمية: ' : 'Qty: ').$i->qty.' × <bdi>'.e($m((float) $i->price)).'</bdi></span></td>'
                .'<td class="em-text" style="padding:8px 0;vertical-align:middle;font-size:15px;text-align:'.$end.';white-space:nowrap;"><bdi>'.e($m((float) $i->line_total)).'</bdi></td></tr>';
            $text[] = "{$name} × {$i->qty} – ".$m((float) $i->line_total);
        }
        $line = function (string $label, string $value, bool $strong = false) use ($t, $start, $end) {
            $w = $strong ? 'font-weight:800;font-size:17px;' : 'font-size:15px;';

            return '<tr><td colspan="2" class="em-text em-line" style="padding:7px 0;border-top:1px solid '.$t['line'].';text-align:'.$start.';'.$w.'">'.e($label).'</td>'
                .'<td class="em-text em-line" style="padding:7px 0;border-top:1px solid '.$t['line'].';text-align:'.$end.';white-space:nowrap;'.$w.'"><bdi>'.e($value).'</bdi></td></tr>';
        };
        $sum = $line($ar ? 'المجموع' : 'Subtotal', $m((float) $o->subtotal));
        $text[] = ($ar ? 'المجموع: ' : 'Subtotal: ').$m((float) $o->subtotal);
        if ((float) $o->discount > 0) {
            $label = ($ar ? 'الخصم' : 'Discount').($o->coupon_code ? ' ('.$o->coupon_code.')' : '');
            $sum .= $line($label, '−'.$m((float) $o->discount));
            $text[] = $label.': −'.$m((float) $o->discount);
        }
        $quoted = (float) $o->shipping == 0.0 && (Shipping::findMethod((string) $o->shipping_method)['pricing'] ?? '') === 'quoted';
        $ship = $quoted ? ($ar ? 'يُحدَّد لاحقًا' : 'to follow') : ((float) $o->shipping == 0.0 ? ($ar ? 'مجاني' : 'Free') : $m((float) $o->shipping));
        $sum .= $line($ar ? 'التوصيل' : 'Delivery', $ship);
        $text[] = ($ar ? 'التوصيل: ' : 'Delivery: ').$ship;
        $total = Fmt::money((float) $o->charge_amount, $o->charge_currency, $lang);
        $sum .= $line($ar ? 'الإجمالي' : 'Total', $total, true);
        $text[] = ($ar ? 'الإجمالي: ' : 'Total: ').$total;

        return [
            'html' => '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:10px 0 18px;border-collapse:collapse;">'.$rows.$sum.'</table>',
            'text' => implode("\n", $text),
        ];
    }

    public static function customerUrl(object $o): string
    {
        return config('store.store_url').'/checkout?order='.$o->token.($o->lang === 'en' ? '&lang=en' : '');
    }

    /** The invoice web page: signed, no login. */
    public static function invoiceUrl(object $o, ?string $lang = null): string
    {
        return config('store.store_url').'/api/invoice/'.$o->token.'?s='.self::invoiceSig($o->token).($lang ? '&lang='.$lang : '');
    }

    public static function invoiceSig(string $token): string
    {
        return substr(hash_hmac('sha256', 'invoice|'.$token, (string) config('app.key')), 0, 24);
    }

    public static function adminLink(object $o): string
    {
        return rtrim((string) config('store.admin_url'), '/').'/#/orders/'.$o->id;
    }
}
