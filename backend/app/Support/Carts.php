<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Abandoned-cart emails (Admin › Abandoned carts). Off until the owner switches it on; only carts left after that.
 *
 * A cart is saved on the server once we know an email for it (typed at checkout). Up to three emails follow, each
 * after its own wait (the first from when the cart was left, the others from the email before):
 *   1 reminder · 2 with a personal code (or without, if codes are off or they had one recently) · 3 "your code ends soon".
 * Unpaid card orders the system gave up on become carts too, with the same series worded for an unfinished order.
 * The series stops when the cart becomes an order (or they place any other order), a newer cart takes over, the cart is
 * emptied, they unsubscribe, or it is more than 30 days old. Unpaid orders cancelled by staff are never emailed.
 */
class Carts
{
    public static function cfg(): array
    {
        return Settings::get('carts');
    }

    public static function enabled(): bool
    {
        return (bool) (self::cfg()['enabled'] ?? false);
    }

    /** The storefront told us the cart (and maybe an email). Keeps the saved cart up to date. */
    public static function touch(?string $cartKey, array $items, string $email, string $lang, string $country, string $name, float $value): void
    {
        if (! $cartKey || ! self::enabled()) {
            return;
        }
        $items = self::cleanItems($items);
        $row = DB::table('carts')->where('cart_key', $cartKey)->first();
        $email = strtolower(trim($email));
        $valid = filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        if (! $row && ! $valid) {
            return;   // nobody to email
        }
        if ($row && ($row->stopped_at || $row->recovered_order_id)) {
            if (! $items) {
                return;
            }
            // they came back and built a new cart under the same id: start afresh
            DB::table('carts')->where('id', $row->id)->update(['cart_key' => null]);
            $row = null;
            if (! $valid) {
                return;
            }
        }
        if (! $items) {
            if ($row) {
                self::stop($row->id, 'emptied');
            }

            return;
        }
        $vals = ['items' => json_encode($items), 'value' => round($value, 3), 'last_activity_at' => now(), 'updated_at' => now(),
            'lang' => $lang === 'en' ? 'en' : 'ar'] + ($country !== '' ? ['country' => strtoupper($country)] : []) + ($name !== '' ? ['name' => mb_substr($name, 0, 120)] : []);
        if ($row) {
            DB::table('carts')->where('id', $row->id)->update($vals + ($valid ? ['email' => $email] : []));

            return;
        }
        // A newer cart carries an older one's series on (step, code) – the older one stops.
        $older = DB::table('carts')->where('email', $email)->whereNull('stopped_at')->whereNull('recovered_order_id')->orderByDesc('id')->first();
        $carry = $older ? ['step' => $older->step, 'step1_at' => $older->step1_at, 'step2_at' => $older->step2_at, 'step3_at' => $older->step3_at, 'coupon_id' => $older->coupon_id] : [];
        DB::table('carts')->insert($vals + $carry + ['cart_key' => $cartKey, 'token' => bin2hex(random_bytes(20)), 'email' => $email, 'created_at' => now()]);
        if ($older) {
            self::stop($older->id, 'newer_cart');
        }
    }

    /** [{id, qty}] with sane numbers. */
    public static function cleanItems(array $items): array
    {
        $out = [];
        foreach (array_slice($items, 0, 50) as $it) {
            $id = (int) (((array) $it)['id'] ?? 0);
            $qty = max(0, min(99, (int) (((array) $it)['qty'] ?? 0)));
            if ($id > 0 && $qty > 0) {
                $out[] = ['id' => $id, 'qty' => $qty];
            }
        }

        return $out;
    }

    public static function stop(int $id, string $reason): void
    {
        DB::table('carts')->where('id', $id)->whereNull('stopped_at')->update(['stopped_at' => now(), 'stop_reason' => $reason, 'updated_at' => now()]);
    }

    /** An order was placed: its cart (and the customer's other carts) stop – the cart became an order. */
    public static function orderPlaced(object $o): void
    {
        $q = DB::table('carts')->whereNull('stopped_at')->whereNull('recovered_order_id')
            ->where(fn ($w) => $w->when($o->cart_key, fn ($x) => $x->where('cart_key', $o->cart_key))
                ->orWhere(fn ($x) => $x->where('email', strtolower((string) $o->email))->where('email', '<>', '')));
        $q->update(['stopped_at' => now(), 'stop_reason' => 'ordered', 'recovered_order_id' => $o->id, 'updated_at' => now()]);
    }

    /** Paid: an unpaid order we were winning back is won. */
    public static function orderPaid(object $o): void
    {
        DB::table('carts')->where('order_id', $o->id)->update(['stopped_at' => DB::raw('COALESCE(stopped_at, NOW())'), 'stop_reason' => 'paid', 'recovered_order_id' => $o->id]);
        self::orderPlaced($o);
    }

    public static function orderCancelledByStaff(object $o): void
    {
        DB::table('carts')->where('order_id', $o->id)->whereNull('stopped_at')->update(['stopped_at' => now(), 'stop_reason' => 'cancelled_by_staff']);
    }

    /** Scheduler (every 5 minutes): new unpaid orders to win back, then the next email for carts that are due. */
    public static function run(?int $limit = null): int
    {
        if (! self::enabled()) {
            return 0;
        }
        $cfg = self::cfg();
        $limit ??= max(1, min(200, (int) ($cfg['batch'] ?? 30)));
        if ($cfg['unpaidOrders'] ?? true) {
            self::collectUnpaidOrders();
        }
        $since = $cfg['since'] ?? null;
        $sent = 0;
        $rows = DB::table('carts')->whereNull('stopped_at')->whereNull('recovered_order_id')->where('email', '<>', '')
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))->orderBy('last_activity_at')->limit(500)->get();
        foreach ($rows as $c) {
            if ($sent >= $limit) {
                break;
            }
            $sent += self::advance($c) ? 1 : 0;
        }

        return $sent;
    }

    /** Unpaid card orders whose hold ran out (the system gave up on them) become carts – last 3 days, after the switch-on. */
    public static function collectUnpaidOrders(): void
    {
        $since = max(strtotime((string) (self::cfg()['since'] ?? 'now')) ?: now()->getTimestamp(), now()->getTimestamp() - 3 * 86400);
        $hold = max(10, (int) (Settings::get('orders')['holdMinutes'] ?? 60));
        $orders = DB::table('orders')->where('payment_method', 'tap')->where('source', 'website')->whereNull('paid_at')
            ->whereIn('status', ['pending_payment', 'payment_failed'])->where('email', '<>', '')
            ->where('created_at', '>=', date('Y-m-d H:i:s', $since))->where('created_at', '<', now()->subMinutes($hold))
            ->whereNotExists(fn ($q) => $q->from('carts')->whereColumn('carts.order_id', 'orders.id'))
            ->limit(100)->get();
        foreach ($orders as $o) {
            if (Holds::orderHeldUntil($o->id)) {
                continue;   // still held: the payment reminder covers it
            }
            $items = DB::table('order_items')->where('order_id', $o->id)->whereNotNull('product_id')->get()
                ->map(fn ($i) => ['id' => (int) $i->product_id, 'qty' => (int) $i->qty])->values()->all();
            if (! $items) {
                continue;
            }
            $email = strtolower($o->email);
            DB::table('carts')->where('email', $email)->whereNull('stopped_at')->whereNull('recovered_order_id')->whereNull('order_id')
                ->update(['stopped_at' => now(), 'stop_reason' => 'newer_cart']);
            DB::table('carts')->insert([
                'token' => bin2hex(random_bytes(20)), 'email' => $email, 'name' => $o->first_name, 'lang' => $o->lang, 'country' => $o->country,
                'items' => json_encode($items), 'value' => $o->subtotal, 'order_id' => $o->id, 'last_activity_at' => $o->created_at,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** Checks the stop rules, then sends the next email if it is due. $now: send it now, whatever the wait (admin). */
    public static function advance(object $c, bool $now = false): bool
    {
        // stop rules
        if (Subscribers::unsubscribed($c->email)) {
            self::stop($c->id, 'unsubscribed');

            return false;
        }
        if ($c->last_activity_at && strtotime((string) $c->last_activity_at) < now()->getTimestamp() - 30 * 86400) {
            self::stop($c->id, 'too_old');

            return false;
        }
        if ($c->order_id) {
            $o = DB::table('orders')->find($c->order_id);
            if (! $o || $o->paid_at) {
                self::stop($c->id, 'paid');

                return false;
            }
            if ($o->status === 'cancelled' || ! in_array($o->status, ['pending_payment', 'payment_failed'], true) || Holds::orderHeldUntil($o->id)) {
                self::stop($c->id, $o->status === 'cancelled' ? 'cancelled_by_staff' : 'reopened');

                return false;
            }
        }
        $other = DB::table('orders')->where('email', $c->email)->where('created_at', '>', $c->created_at)->where('status', '<>', 'cancelled')
            ->when($c->order_id, fn ($q) => $q->where('id', '<>', $c->order_id))->first();
        if ($other) {
            DB::table('carts')->where('id', $c->id)->update(['stopped_at' => now(), 'stop_reason' => 'ordered', 'recovered_order_id' => $other->id]);

            return false;
        }
        $items = self::cleanItems((array) json_decode((string) $c->items, true));
        if (! $items) {
            self::stop($c->id, 'emptied');

            return false;
        }
        if ($c->step >= 3) {
            self::stop($c->id, 'finished');

            return false;
        }
        // which email is next, and is it due?
        $steps = self::cfg()['steps'] ?? [];
        $base = strtotime((string) ($c->step > 0 ? $c->{'step'.$c->step.'_at'} : $c->last_activity_at)) ?: now()->getTimestamp();
        for ($i = $c->step + 1; $i <= 3; $i++) {
            $s = $steps[$i - 1] ?? ['on' => false];
            $wait = max(0, (int) ($s['wait'] ?? 0)) * (($s['unit'] ?? 'hours') === 'days' ? 86400 : 3600);
            if (! ($s['on'] ?? false)) {
                DB::table('carts')->where('id', $c->id)->update(['step' => $i, 'step'.$i.'_at' => date('Y-m-d H:i:s', $base)]);   // switched off: skipped
                continue;
            }
            if (! $now && now()->getTimestamp() < $base + $wait) {
                return false;
            }

            return self::sendStep(DB::table('carts')->find($c->id), $i);
        }
        self::stop($c->id, 'finished');

        return false;
    }

    private static function sendStep(object $c, int $step): bool
    {
        $prefix = $c->order_id ? 'unpaid_' : 'cart_';
        $key = $prefix.$step;
        $coupon = $c->coupon_id ? DB::table('coupons')->find($c->coupon_id) : null;
        if ($step === 2) {
            $cc = self::cfg()['coupon'] ?? [];
            if (($cc['on'] ?? false) && ! $coupon && ! Coupons::recentlyGiven($c->email, (int) ($cc['cooldownDays'] ?? 30))) {
                $coupon = Coupons::personal($c->email, (string) ($cc['type'] ?? 'percent'), (float) ($cc['value'] ?? 10),
                    isset($cc['min']) && $cc['min'] !== '' && $cc['min'] !== null ? (float) $cc['min'] : null, (int) ($cc['days'] ?? 7), 'cart');
                DB::table('carts')->where('id', $c->id)->update(['coupon_id' => $coupon->id]);
            }
            $key = $coupon && Coupons::unused($coupon) ? $prefix.'2' : $prefix.'2_nocode';
        }
        if ($step === 3) {
            $key = $coupon && Coupons::unused($coupon) ? $prefix.'3' : $prefix.'3_nocode';
        }
        $id = self::sendEmail($c, $key, $coupon, "cart:{$c->id}:{$step}");
        DB::table('carts')->where('id', $c->id)->update(['step' => $step, 'step'.$step.'_at' => now(), 'updated_at' => now()]);
        if ($step >= 3) {
            self::stop($c->id, 'finished');
        }

        return $id > 0;
    }

    public static function sendEmail(object $c, string $key, ?object $coupon, ?string $dedupe, array $extra = []): int
    {
        $lang = EmailSender::langFor($c->lang);
        $c2 = self::context($c, $lang === 'both' ? 'ar' : $lang, $coupon);
        if ($lang === 'both') {
            $en = self::context($c, 'en', $coupon);
            $c2['vars'] = ['ar' => $c2['vars'], 'en' => $en['vars']];
            $c2['blocks'] = ['ar' => $c2['blocks'], 'en' => $en['blocks']];
        }

        return EmailSender::send($key, $c->email, $lang, $c2 + ['dedupe' => $dedupe, 'name' => $c->name] + $extra);
    }

    /** vars, blocks and the "return to your cart" link. */
    public static function context(object $c, string $lang, ?object $coupon): array
    {
        $ar = $lang === 'ar';
        $items = self::cleanItems((array) json_decode((string) $c->items, true));
        $cur = Currency::get(Checkout::currencyFor($c->country ?: 'BH')) ?? ['code' => 'BHD', 'rate' => 1];
        $t = EmailLayout::theme();
        $rows = '';
        $text = [];
        $products = DB::table('products')->whereIn('id', array_column($items, 'id') ?: [0])->get()->keyBy('id');
        foreach ($items as $it) {
            $p = $products[$it['id']] ?? null;
            if (! $p) {
                continue;
            }
            $name = $ar && $p->name_ar ? $p->name_ar : $p->name;
            $img = (json_decode((string) $p->images, true) ?: [])[0] ?? '';
            $price = Fmt::money((float) $p->price * (float) $cur['rate'], $cur['code'], $lang);
            $pic = $img ? '<img src="'.e(EmailLayout::abs(Thumbs::url($img, 200))).'" alt="'.e($name).'" width="64" height="64" style="display:block;width:64px;height:64px;object-fit:cover;border-radius:10px;">' : '';
            $rows .= '<tr><td width="72" style="padding:8px 0;">'.$pic.'</td><td class="em-text" style="padding:8px 10px;font-size:15px;"><b>'.e($name).'</b><br>'
                .'<span class="em-muted" style="color:'.$t['muted'].';font-size:14px;">'.($ar ? 'الكمية: ' : 'Qty: ').$it['qty'].' · <bdi>'.e($price).'</bdi></span></td></tr>';
            $text[] = $name.' × '.$it['qty'].' – '.$price;
        }
        $vars = ['name' => $c->name, 'order_number' => $c->order_id ? (string) DB::table('orders')->where('id', $c->order_id)->value('number') : ''];
        $blocks = ['items' => ['html' => '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:8px 0 16px;">'.$rows.'</table>', 'text' => implode("\n", $text)]];
        if ($coupon) {
            $daysLeft = $coupon->ends_at ? max(1, (int) ceil((strtotime((string) $coupon->ends_at) - now()->getTimestamp()) / 86400)) : 0;
            $vars += ['code' => $coupon->code, 'offer' => Coupons::offer($coupon, $lang), 'ends' => $coupon->ends_at ? Fmt::date($coupon->ends_at, $lang) : '',
                'days_left' => Fmt::days($daysLeft, $lang),
                'valid_for' => Fmt::days(max(1, (int) round((strtotime((string) $coupon->ends_at) - strtotime((string) $coupon->created_at)) / 86400)), $lang)];
            $blocks['code_box'] = Coupons::box($coupon, $lang);
        }

        return ['vars' => $vars, 'blocks' => $blocks, 'url' => self::restoreUrl($c, $coupon, $lang)];
    }

    public static function restoreUrl(object $c, ?object $coupon, string $lang): string
    {
        return config('store.store_url').'/checkout?restore='.$c->token.($lang === 'en' ? '&lang=en' : '');
    }

    /** GET /api/cart/restore/{token}: the exact cart (what is still on sale) and its code, on any device. */
    public static function restore(string $token): ?array
    {
        $c = DB::table('carts')->where('token', $token)->first();
        if (! $c) {
            return null;
        }
        $items = self::cleanItems((array) json_decode((string) $c->items, true));
        $ok = DB::table('products')->whereIn('id', array_column($items, 'id') ?: [0])->where('status', 'active')->pluck('id')->all();
        $coupon = $c->coupon_id ? DB::table('coupons')->find($c->coupon_id) : null;

        return [
            'items' => array_values(array_filter($items, fn ($i) => in_array($i['id'], $ok))),
            'code' => $coupon && Coupons::unused($coupon) ? $coupon->code : null,
            'email' => $c->email, 'name' => $c->name, 'country' => $c->country, 'lang' => $c->lang,
        ];
    }

    /** Numbers for Admin › Abandoned carts. */
    public static function stats(): array
    {
        $since = self::cfg()['since'] ?? null;
        $sent = DB::table('mail_outbox')->where('status', 'sent')->where(fn ($q) => $q->where('email_key', 'like', 'cart\_%')->orWhere('email_key', 'like', 'unpaid\_%'))
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))->count();
        $rec = DB::table('carts')->join('orders', 'orders.id', '=', 'carts.recovered_order_id')->where('carts.step', '>', 0)->whereNotNull('orders.paid_at')
            ->when($since, fn ($q) => $q->where('carts.created_at', '>=', $since));

        return [
            'emailsSent' => $sent, 'recovered' => (clone $rec)->count(), 'revenue' => round((float) (clone $rec)->sum('orders.total'), 3),
            'codesUsed' => (int) DB::table('coupons')->where('source', 'cart')->where('used_count', '>', 0)->count(),
            'active' => DB::table('carts')->whereNull('stopped_at')->whereNull('recovered_order_id')->count(),
        ];
    }
}
