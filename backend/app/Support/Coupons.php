<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Discount codes. Checked on the server at every quote and when the order is placed, with a clear reason when one
 * cannot be used. A use counts only when the order is paid, and is given back if that order is cancelled.
 * Personal codes (abandoned cart, pop-up) are locked to one email address, one use, and run out after N days.
 */
class Coupons
{
    /** Reasons a code cannot be used – the storefront shows these (it has the Arabic). */
    public const REASONS = [
        'not_found' => 'This code does not exist.',
        'off' => 'This code is not active.',
        'not_started' => 'This code is not active yet.',
        'expired' => 'This code has expired.',
        'min' => 'Add more to your cart to use this code.',
        'email' => 'This code is for another email address. Enter the email it was sent to.',
        'used_up' => 'This code has been fully used.',
        'already_used' => 'You have already used this code.',
        'items' => 'This code is not for the products in your cart.',
        'no_delivery_charge' => 'Delivery is already free for this order.',
    ];

    public static function normalise(string $code): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($code)));
    }

    /**
     * Applies a code to priced lines. Never throws: ['coupon' => row|null, 'discount' => BHD, 'freeShipping' => bool,
     * 'error' => null|[code, message, data]].
     * $lines: [{id, lineTotal}], $shipping: the chosen delivery price (BHD).
     */
    public static function apply(string $code, string $email, array $lines, float $subtotal, float $shipping): array
    {
        $none = ['coupon' => null, 'discount' => 0.0, 'freeShipping' => false, 'error' => null];
        $code = self::normalise($code);
        if ($code === '') {
            return $none;
        }
        $c = DB::table('coupons')->where('code', $code)->first();
        $fail = fn (string $why, array $data = []) => ['error' => [$why, self::REASONS[$why], $data]] + $none;
        if (! $c) {
            return $fail('not_found');
        }
        if (! $c->enabled) {
            return $fail('off');
        }
        if ($c->starts_at && now()->lt($c->starts_at)) {
            return $fail('not_started');
        }
        if ($c->ends_at && now()->gt($c->ends_at)) {
            return $fail('expired');
        }
        $email = strtolower(trim($email));
        $only = array_map('strtolower', (array) json_decode((string) $c->emails, true));
        if ($only && ($email === '' || ! in_array($email, $only, true))) {
            return $fail('email');
        }
        if ($c->usage_limit !== null && (int) $c->used_count >= (int) $c->usage_limit) {
            return $fail('used_up');
        }
        if ($c->per_customer_limit !== null && $email !== ''
            && DB::table('coupon_uses')->where('coupon_id', $c->id)->where('email', $email)->where('status', 'used')->count() >= (int) $c->per_customer_limit) {
            return $fail('already_used');
        }
        // the part of the cart the code applies to
        $pids = array_map('intval', (array) json_decode((string) $c->product_ids, true));
        $cids = array_map('intval', (array) json_decode((string) $c->category_ids, true));
        $eligible = $subtotal;
        if ($pids || $cids) {
            $cats = DB::table('products')->whereIn('id', array_column($lines, 'id') ?: [0])->pluck('category_id', 'id');
            $eligible = 0.0;
            foreach ($lines as $l) {
                if (in_array((int) $l['id'], $pids, true) || in_array((int) ($cats[$l['id']] ?? 0), $cids, true)) {
                    $eligible += (float) $l['lineTotal'];
                }
            }
            if ($eligible <= 0) {
                return $fail('items');
            }
        }
        if ($c->min_subtotal !== null && $subtotal + 0.0005 < (float) $c->min_subtotal) {
            return $fail('min', ['min' => (float) $c->min_subtotal]);
        }
        if ($c->type === 'free_shipping') {
            if ($shipping <= 0) {
                return $fail('no_delivery_charge');
            }

            return ['coupon' => $c, 'discount' => round($shipping, 3), 'freeShipping' => true, 'error' => null];
        }
        $d = $c->type === 'percent' ? $eligible * min(100, (float) $c->value) / 100 : min((float) $c->value, $eligible);
        if ($c->max_discount !== null) {
            $d = min($d, (float) $c->max_discount);
        }

        return ['coupon' => $c, 'discount' => round(max(0, $d), 3), 'freeShipping' => false, 'error' => null];
    }

    /** What the code gives, in words: "10% off", "BHD 2.000 off", "free delivery". */
    public static function offer(object $c, string $lang = 'en'): string
    {
        $ar = $lang === 'ar';

        return match ($c->type) {
            'percent' => $ar ? 'خصم '.rtrim(rtrim(number_format((float) $c->value, 2), '0'), '.').'٪' : rtrim(rtrim(number_format((float) $c->value, 2), '0'), '.').'% off',
            'fixed' => $ar ? 'خصم '.Fmt::money((float) $c->value, 'BHD', 'ar') : Fmt::money((float) $c->value, 'BHD', 'en').' off',
            default => $ar ? 'توصيل مجاني' : 'free delivery',
        }.($c->min_subtotal !== null ? ($ar ? ' للطلبات من '.Fmt::money((float) $c->min_subtotal, 'BHD', 'ar') : ' on orders from '.Fmt::money((float) $c->min_subtotal, 'BHD', 'en')) : '');
    }

    /** The order is paid: count the use (once). */
    public static function countUse(object $o): void
    {
        if (! $o->coupon_id) {
            return;
        }
        $inserted = DB::table('coupon_uses')->insertOrIgnore([
            'coupon_id' => $o->coupon_id, 'order_id' => $o->id, 'email' => strtolower((string) $o->email), 'amount' => $o->discount,
            'status' => 'used', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($inserted) {
            DB::table('coupons')->where('id', $o->coupon_id)->increment('used_count');
        }
    }

    /** The order is cancelled: give the use back. */
    public static function release(object $o): void
    {
        $u = DB::table('coupon_uses')->where('order_id', $o->id)->where('status', 'used')->first();
        if ($u) {
            DB::table('coupon_uses')->where('id', $u->id)->update(['status' => 'released', 'updated_at' => now()]);
            DB::table('coupons')->where('id', $u->coupon_id)->where('used_count', '>', 0)->decrement('used_count');
        }
    }

    /** A personal code: one email, one use, ends after $days. */
    public static function personal(string $email, string $type, float $value, ?float $min, int $days, string $source): object
    {
        do {
            $code = 'TZ'.strtoupper(substr(str_replace(['0', 'O', '1', 'I'], '', bin2hex(random_bytes(6))), 0, 6));
        } while (DB::table('coupons')->where('code', $code)->exists());
        $titles = ['cart' => ['Come-back code', 'كود العودة'], 'popup' => ['Welcome code', 'كود الترحيب']][$source] ?? ['Personal code', 'كود خاص'];
        $id = DB::table('coupons')->insertGetId([
            'code' => $code, 'title' => $titles[0], 'title_ar' => $titles[1],
            'type' => in_array($type, ['percent', 'fixed', 'free_shipping'], true) ? $type : 'percent', 'value' => max(0, $value),
            'min_subtotal' => $min, 'starts_at' => now(), 'ends_at' => now()->addDays(max(1, $days))->endOfDay(), 'enabled' => true,
            'usage_limit' => 1, 'per_customer_limit' => 1, 'emails' => json_encode([strtolower(trim($email))]),
            'source' => $source, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('coupons')->find($id);
    }

    /** Did this address get a personal code within $days (cool-down)? */
    public static function recentlyGiven(string $email, int $days, ?string $source = null): bool
    {
        return DB::table('coupons')->whereIn('source', $source ? [$source] : ['cart', 'popup'])->where('created_at', '>', now()->subDays(max(0, $days)))
            ->whereJsonContains('emails', strtolower(trim($email)))->exists();
    }

    public static function unused(object $c): bool
    {
        return $c->enabled && (int) $c->used_count === 0 && (! $c->ends_at || now()->lt($c->ends_at));
    }

    /** The big code box for emails. */
    public static function box(object $c, string $lang): array
    {
        $t = EmailLayout::theme();
        $ends = $c->ends_at ? Fmt::date($c->ends_at, $lang) : '';
        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:18px 0;"><tr><td align="center" class="em-box" '
            .'style="background:#FFF8DC;border:2px dashed '.$t['brand'].';border-radius:14px;padding:18px;">'
            .'<div style="font-size:30px;font-weight:800;letter-spacing:4px;color:'.$t['brand'].';font-family:Consolas,Menlo,monospace;" dir="ltr">'.e($c->code).'</div>'
            .'<div class="em-text" style="font-size:15px;color:'.$t['text'].';margin-top:6px;">'.e(self::offer($c, $lang)).($ends ? ' · '.($lang === 'ar' ? 'ينتهي ' : 'ends ').e($ends) : '').'</div>'
            .'</td></tr></table>';

        return ['html' => $html, 'text' => $c->code.' – '.self::offer($c, $lang).($ends ? ' ('.$ends.')' : '')];
    }
}
