<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Realistic sample data for Admin › Emails: Preview, "Send test to me" and "Send me one of every email".
 * Uses the latest real order and products when there are any (nothing is changed), else made-up ones.
 */
class EmailPreview
{
    /** vars, blocks, url for $key in $lang. */
    public static function context(string $key, string $lang): array
    {
        $meta = EmailTemplates::meta($key);
        if ($meta['group'] === 'carts') {
            return self::cartContext($key, $lang);
        }
        if ($key === 'campaign') {
            return ['vars' => ['name' => $lang === 'ar' ? 'سارة' : 'Sara'], 'url' => config('store.store_url')];
        }
        if ($key === 'back_in_stock') {
            $p = DB::table('products')->where('status', 'active')->orderBy('sort_rank')->first();
            $name = $p ? ($lang === 'ar' && $p->name_ar ? $p->name_ar : $p->name) : 'Tartebz Fruits';
            $url = config('store.store_url').($p ? '/product/'.rawurlencode($p->slug) : '/shop');

            return ['vars' => ['name' => '', 'product' => $name, 'product_link' => $url], 'url' => $url];
        }
        if ($key === 'staff_reviews') {
            $line = $lang === 'ar' ? '★★★★★ ترتيبز فروتس – سارة: لذيذ جدًا!' : '★★★★★ Tartebz Fruits – Sara: So good!';

            return ['vars' => ['count' => '2', 'admin_link' => config('store.admin_url')], 'url' => rtrim((string) config('store.admin_url'), '/').'/#/reviews',
                'blocks' => ['review_list' => ['html' => '<p>'.e($line).'</p><p>'.e(str_replace('5', '4', $line)).'</p>', 'text' => $line]]];
        }
        $o = self::order($lang);
        $c = OrderMail::context($o, $lang, $key, [
            'reason' => $key === 'status_cancelled' ? ($lang === 'ar' ? 'السبب: نفدت الكمية.' : 'Reason: the item sold out.') : '',
            'refund' => $key === 'status_refunded' ? (object) ['amount' => 3.5, 'currency' => $o->charge_currency, 'id' => 0] : null,
            'full' => false,
        ]);
        if ($o->id === 0) {
            $c['blocks']['items'] = self::sampleItems($lang);
            if ($key === 'review_request') {
                $c['blocks']['review_items'] = self::sampleStars($lang);
            }
        }
        if ($key === 'staff_order_update') {
            $c['vars'] += ['change' => $lang === 'ar' ? 'الحالة: تم الشحن' : 'Status: Shipped', 'who' => $lang === 'ar' ? 'أحمد' : 'Ahmed'];
        }
        $c['vars']['held_until'] = $c['vars']['held_until'] ?: Fmt::dateTime(now()->addMinutes(40), $lang);
        $c['vars']['tracking_number'] = $c['vars']['tracking_number'] ?: '231234567890';
        $c['vars']['tracking_link'] = $c['vars']['tracking_link'] ?: Smsa::TRACK_URL.'231234567890';

        return $c;
    }

    /** The latest real order (read only), or a made-up one. */
    private static function order(string $lang): object
    {
        $o = DB::table('orders')->whereNotNull('paid_at')->where('email', '<>', '')->orderByDesc('id')->first();
        if ($o) {
            return $o;
        }

        return (object) [
            'id' => 0, 'number' => '20458', 'token' => str_repeat('a', 40), 'lang' => $lang, 'first_name' => $lang === 'ar' ? 'سارة' : 'Sara', 'last_name' => '',
            'email' => 'customer@example.com', 'phone' => '+973 3300 0000', 'country' => 'BH', 'city' => 'Manama', 'state' => '', 'address1' => 'Road 1234, Block 345',
            'address2' => '', 'payment_method' => 'tap', 'paid_at' => now(), 'charge_currency' => 'BHD', 'charge_amount' => 9.5, 'fx_rate' => 1,
            'subtotal' => 9.5, 'shipping' => 2, 'discount' => 2, 'coupon_code' => 'TZ4K7Q2M', 'awb' => '231234567890', 'shipping_method' => 'bh-std',
            'shipping_method_name' => 'Standard delivery', 'shipping_method_name_ar' => 'توصيل عادي', 'status' => 'paid', 'refunded_amount' => 0,
        ];
    }

    private static function products(int $n = 2): array
    {
        $rows = DB::table('products')->where('status', 'active')->orderBy('sort_rank')->limit($n)->get()->all();

        return $rows ?: [(object) ['id' => 0, 'name' => 'Tartebz Fruits', 'name_ar' => 'ترتيبز فروتس', 'price' => 3.5, 'images' => '[]', 'slug' => 'tartebz-fruits']];
    }

    private static function sampleItems(string $lang): array
    {
        $rows = '';
        $text = [];
        foreach (self::products() as $p) {
            $name = $lang === 'ar' && $p->name_ar ? $p->name_ar : $p->name;
            $img = (json_decode((string) $p->images, true) ?: [])[0] ?? '';
            $pic = $img ? '<img src="'.e(EmailLayout::abs(Thumbs::url($img, 200))).'" alt="'.e($name).'" width="56" height="56" style="display:block;width:56px;height:56px;object-fit:cover;border-radius:10px;">' : '';
            $price = Fmt::money((float) $p->price, 'BHD', $lang);
            $rows .= '<tr><td width="64" style="padding:8px 0;">'.$pic.'</td><td class="em-text" style="padding:8px 10px;"><b>'.e($name).'</b><br>'
                .($lang === 'ar' ? 'الكمية: ' : 'Qty: ').'1</td><td style="text-align:'.($lang === 'ar' ? 'left' : 'right').';"><bdi>'.e($price).'</bdi></td></tr>';
            $text[] = $name.' × 1 – '.$price;
        }

        return ['html' => '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:10px 0 18px;">'.$rows.'</table>', 'text' => implode("\n", $text)];
    }

    private static function sampleStars(string $lang): array
    {
        $t = EmailLayout::theme();
        $html = '';
        foreach (self::products() as $p) {
            $name = $lang === 'ar' && $p->name_ar ? $p->name_ar : $p->name;
            $stars = str_repeat('<a href="'.e(config('store.store_url').'/product/'.$p->slug.'#reviews').'" style="text-decoration:none;font-size:30px;color:'.$t['accent'].';">&#9733;</a>', 5);
            $html .= '<p><b>'.e($name).'</b><br><span dir="ltr">'.$stars.'</span></p>';
        }

        return ['html' => $html, 'text' => ''];
    }

    private static function cartContext(string $key, string $lang): array
    {
        $items = array_map(fn ($p) => ['id' => (int) $p->id, 'qty' => 1], self::products());
        $cart = (object) ['id' => 0, 'items' => json_encode($items), 'country' => 'BH', 'name' => $lang === 'ar' ? 'سارة' : 'Sara', 'token' => str_repeat('b', 40),
            'order_id' => null, 'lang' => $lang, 'email' => 'customer@example.com'];
        $cc = Settings::get('carts')['coupon'] ?? ['type' => 'percent', 'value' => 10, 'days' => 7, 'min' => null];
        $coupon = (object) ['code' => 'TZ4K7Q2M', 'type' => $cc['type'], 'value' => $cc['value'], 'min_subtotal' => $cc['min'] ?? null,
            'ends_at' => now()->addDays((int) ($cc['days'] ?? 7)), 'created_at' => now(), 'enabled' => true, 'used_count' => 0];
        $c = Carts::context($cart, $lang, str_contains($key, 'nocode') || in_array($key, ['cart_1', 'unpaid_1'], true) ? null : $coupon);
        if (str_starts_with($key, 'unpaid_')) {
            $c['vars']['order_number'] = '20458';
        }

        return $c;
    }
}
