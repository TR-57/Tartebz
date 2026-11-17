<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Product reviews from people who bought. The review email shows each product with five stars; each star opens the
 * product page with the review form and that rating chosen. The link is signed for that order and product, so only
 * buyers review, once per product and order. Reviews wait for approval in Admin › Reviews.
 */
class Reviews
{
    public static function token(int $orderId, int $productId): string
    {
        $base = $orderId.'.'.$productId;

        return $base.'.'.substr(hash_hmac('sha256', 'review|'.$base, (string) config('app.key')), 0, 20);
    }

    /** [orderId, productId] or null. */
    public static function readToken(string $token): ?array
    {
        if (! preg_match('/^(\d+)\.(\d+)\.([a-f0-9]{20})$/', $token, $m)) {
            return null;
        }
        if (! hash_equals(substr(hash_hmac('sha256', 'review|'.$m[1].'.'.$m[2], (string) config('app.key')), 0, 20), $m[3])) {
            return null;
        }

        return [(int) $m[1], (int) $m[2]];
    }

    public static function link(object $o, object $product, int $rate): string
    {
        return config('store.store_url').'/product/'.rawurlencode($product->slug).'?review='.self::token($o->id, $product->id).'&rate='.$rate
            .($o->lang === 'en' ? '&lang=en' : '').'#reviews';
    }

    /** Each product bought, its picture and five tappable stars. */
    public static function starsBlock(object $o, string $lang): array
    {
        $ar = $lang === 'ar';
        $t = EmailLayout::theme();
        $html = '';
        $text = [];
        $seen = [];
        foreach (DB::table('order_items')->where('order_id', $o->id)->whereNotNull('product_id')->orderBy('id')->get() as $i) {
            if (isset($seen[$i->product_id])) {
                continue;
            }
            $seen[$i->product_id] = true;
            $p = DB::table('products')->find($i->product_id);
            if (! $p) {
                continue;
            }
            $name = $ar && $i->name_ar ? $i->name_ar : $i->name;
            $img = $i->image ? EmailLayout::abs(Thumbs::url($i->image, 200)) : '';
            $stars = '';
            foreach (range(1, 5) as $n) {
                $stars .= '<a href="'.e(self::link($o, $p, $n)).'" title="'.$n.'" style="text-decoration:none;font-size:30px;line-height:1;color:'.$t['accent'].';padding:0 2px;">&#9733;</a>';
            }
            $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 12px;"><tr>'
                .($img ? '<td width="68" style="vertical-align:middle;"><img src="'.e($img).'" alt="'.e($name).'" width="60" height="60" style="display:block;width:60px;height:60px;object-fit:cover;border-radius:10px;"></td>' : '')
                .'<td class="em-text" style="vertical-align:middle;padding:0 10px;"><b>'.e($name).'</b><br><span dir="ltr">'.$stars.'</span></td></tr></table>';
            $text[] = $name.': '.self::link($o, $p, 5);
        }

        return ['html' => $html, 'text' => implode("\n", $text)];
    }

    /** {token, rating 1–5, body?, name?} → the review (pending). */
    public static function submit(string $token, int $rating, string $body, string $name, string $lang): object
    {
        $ids = self::readToken($token) ?? throw new ApiError('bad_link', 'This review link is not valid.', 404);
        [$orderId, $productId] = $ids;
        $o = DB::table('orders')->find($orderId);
        if (! $o || ! $o->paid_at || ! DB::table('order_items')->where('order_id', $orderId)->where('product_id', $productId)->exists()) {
            throw new ApiError('bad_link', 'This review link is not valid.', 404);
        }
        if ($rating < 1 || $rating > 5) {
            throw ApiError::validation(['rating' => 'Choose 1 to 5 stars.']);
        }
        $vals = ['rating' => $rating, 'body' => mb_substr(trim($body), 0, 2000) ?: null, 'name' => mb_substr(trim($name) ?: $o->first_name, 0, 80),
            'lang' => $lang === 'en' ? 'en' : 'ar', 'status' => 'pending', 'updated_at' => now()];
        $was = DB::table('reviews')->where('product_id', $productId)->where('order_id', $orderId)->first();
        if ($was) {
            DB::table('reviews')->where('id', $was->id)->update($vals);   // they changed their mind: back to waiting
        } else {
            DB::table('reviews')->insert($vals + ['product_id' => $productId, 'order_id' => $orderId, 'email' => $o->email, 'created_at' => now()]);
        }

        return DB::table('reviews')->where('product_id', $productId)->where('order_id', $orderId)->first();
    }

    /** Approved reviews and the average for the product page. */
    public static function forProduct(int $productId): array
    {
        $q = DB::table('reviews')->where('product_id', $productId)->where('status', 'approved');
        $count = (clone $q)->count();

        return [
            'count' => $count, 'average' => $count ? round((float) (clone $q)->avg('rating'), 1) : null,
            'items' => (clone $q)->orderByDesc('created_at')->limit(20)->get(['name', 'rating', 'body', 'lang', 'created_at'])
                ->map(fn ($r) => ['name' => $r->name, 'rating' => (int) $r->rating, 'body' => (string) $r->body, 'lang' => $r->lang, 'date' => substr((string) $r->created_at, 0, 10)])->all(),
        ];
    }

    /** Once a day: new reviews waiting, to the store addresses and owners. */
    public static function digest(): int
    {
        $since = now()->subDay();
        $new = DB::table('reviews')->join('products', 'products.id', '=', 'reviews.product_id')
            ->where('reviews.status', 'pending')->where('reviews.updated_at', '>', $since)
            ->orderBy('reviews.id')->get(['reviews.id', 'reviews.rating', 'reviews.name', 'reviews.body', 'products.name as product']);
        if ($new->isEmpty() || ! EmailTemplates::enabled('staff_reviews')) {
            return 0;
        }
        $html = '';
        $text = [];
        foreach ($new as $r) {
            $line = str_repeat('★', (int) $r->rating).' '.$r->product.' – '.$r->name.($r->body ? ': '.mb_substr($r->body, 0, 140) : '');
            $html .= '<p style="margin:0 0 8px;">'.e($line).'</p>';
            $text[] = $line;
        }
        $lang = OrderMail::staffLang();
        $link = rtrim((string) config('store.admin_url'), '/').'/#/reviews';
        $n = 0;
        foreach (OrderMail::staffRecipients(['owner']) as $email) {
            $n += EmailSender::send('staff_reviews', $email, $lang, [
                'vars' => ['count' => (string) $new->count(), 'admin_link' => $link], 'url' => $link,
                'blocks' => ['review_list' => ['html' => $html, 'text' => implode("\n", $text)]],
                'dedupe' => 'staff_reviews:'.now()->format('Y-m-d').':'.strtolower($email),
            ]) ? 1 : 0;
        }

        return $n;
    }
}
