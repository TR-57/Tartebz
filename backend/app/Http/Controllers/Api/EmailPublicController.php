<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Admin\BoardController;
use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Carts;
use App\Support\ClientIp;
use App\Support\OrderMail;
use App\Support\Reviews;
use App\Support\Settings;
use App\Support\Subscribers;
use App\Support\Throttle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The public side of the email system: unsubscribe, cart email, cart restore, reviews, the invoice page. */
class EmailPublicController extends Controller
{
    /** GET (the link) and POST (the mail app's one-click button): unsubscribe, no login. */
    public function unsubscribe(Request $r)
    {
        $email = strtolower(trim((string) $r->query('e', $r->input('e', ''))));
        $sig = (string) $r->query('s', $r->input('s', ''));
        $ar = $r->query('lang') !== 'en';
        if (! Subscribers::check($email, $sig)) {
            return response($this->page($ar, $ar ? 'الرابط غير صالح' : 'This link is not valid', $ar ? 'افتح الرابط من آخر رسالة وصلتك منا.' : 'Open the link from the latest email we sent you.'), 404);
        }
        if ($r->isMethod('post') && $r->input('again') === '1') {
            Subscribers::subscribe($email, ['source' => 'resubscribe'], true);

            return response($this->page($ar, $ar ? 'رجّعناك 🎉' : 'You’re back 🎉', $ar ? 'بتوصلك عروضنا من جديد.' : 'You’ll get our offers again.'));
        }
        Subscribers::unsubscribe($email);
        if ($r->isMethod('post')) {
            return response('', 200);   // RFC 8058 one-click
        }
        $again = '<form method="post"><input type="hidden" name="e" value="'.e($email).'"><input type="hidden" name="s" value="'.e($sig).'"><input type="hidden" name="again" value="1">'
            .'<button type="submit">'.($ar ? 'اشتركت بالغلط؟ رجّعني' : 'Unsubscribed by mistake? Subscribe again').'</button></form>';

        return response($this->page($ar, $ar ? 'تم إلغاء الاشتراك' : 'You’re unsubscribed',
            ($ar ? 'ما بنرسل لك عروض أو تذكيرات بالسلة بعد الحين. رسائل طلباتك (التأكيد، الشحن) بتوصلك عادي.' : 'We won’t send you offers or cart reminders any more. Emails about your orders (confirmation, shipping) still arrive.'), $again));
    }

    /** POST {cart, items, email, lang, country, name, value} – checkout: the email field was filled in. */
    public function cartEmail(Request $r)
    {
        if (! Throttle::attempt('cart-email:'.ClientIp::of($r), 120, 3600)) {
            throw new ApiError('too_many', 'Too many requests.', 429);
        }
        Carts::touch(CheckoutController::cartKey($r), (array) $r->input('items', []), $this->str($r, 'email', 190), $r->input('lang') === 'en' ? 'en' : 'ar',
            strtoupper($this->str($r, 'country', 2)), $this->str($r, 'name', 80), (float) $r->input('value', 0));

        return $this->ok();
    }

    /** GET /api/cart/restore/{token} – the exact cart and its code, on any device. */
    public function restore(string $token)
    {
        $c = Carts::restore($token) ?? throw ApiError::notFound('This link has expired.');

        return $this->ok(['cart' => $c]);
    }

    /** GET /api/products/{id}/reviews */
    public function reviews(int $id)
    {
        if (! (Settings::get('reviews')['enabled'] ?? true)) {
            return $this->ok(['reviews' => ['count' => 0, 'average' => null, 'items' => []]]);
        }

        return $this->ok(['reviews' => Reviews::forProduct($id)]);
    }

    /** GET /api/reviews/check?token= – who is reviewing (name, product) before the form is shown. */
    public function reviewCheck(Request $r)
    {
        $ids = Reviews::readToken((string) $r->query('token', '')) ?? throw new ApiError('bad_link', 'This review link is not valid.', 404);
        $o = DB::table('orders')->find($ids[0]);
        if (! $o || ! $o->paid_at || ! DB::table('order_items')->where('order_id', $o->id)->where('product_id', $ids[1])->exists()) {
            throw new ApiError('bad_link', 'This review link is not valid.', 404);
        }
        $mine = DB::table('reviews')->where('order_id', $ids[0])->where('product_id', $ids[1])->first(['rating', 'body', 'status']);

        return $this->ok(['productId' => $ids[1], 'name' => $o->first_name ?? '', 'review' => $mine]);
    }

    /** POST {token, rating, body?, name?, lang} */
    public function submitReview(Request $r)
    {
        if (! Throttle::attempt('review:'.ClientIp::of($r), 30, 3600)) {
            throw new ApiError('too_many', 'Too many reviews from this connection.', 429);
        }
        $rv = Reviews::submit($this->str($r, 'token', 80), (int) $r->input('rating', 0), (string) $r->input('body', ''), $this->str($r, 'name', 80), $this->str($r, 'lang', 2));

        return $this->ok(['status' => $rv->status]);
    }

    /** GET /api/invoice/{token}?s=… – the invoice page from an email: signed, no login. */
    public function invoice(Request $r, string $token)
    {
        $o = DB::table('orders')->where('token', $token)->first();
        if (! $o || ! hash_equals(OrderMail::invoiceSig($token), (string) $r->query('s', ''))) {
            abort(404);
        }
        $lang = in_array($r->query('lang'), ['ar', 'en', 'both'], true) ? $r->query('lang') : ($o->lang === 'en' ? 'en' : 'ar');

        return response()->view('invoice', BoardController::invoiceData($o, $lang) + ['publicBase' => 's='.$r->query('s').'&'], 200,
            ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex']);
    }

    private function page(bool $ar, string $title, string $text, string $extra = ''): string
    {
        $dir = $ar ? 'rtl' : 'ltr';
        $site = e(config('store.store_url'));
        $logo = e(\App\Support\EmailLayout::logo());
        $t = \App\Support\EmailLayout::theme();

        return '<!doctype html><html lang="'.($ar ? 'ar' : 'en').'" dir="'.$dir.'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
            .'<title>'.e($title).'</title><style>@font-face{font-family:Alexandria;src:url(/assets/fonts/alexandria-arabic.woff2) format("woff2");unicode-range:U+0600-06FF,U+FE70-FEFF}@font-face{font-family:Alexandria;src:url(/assets/fonts/alexandria-latin.woff2) format("woff2")}'
            .'body{margin:0;background:'.$t['bg'].';font:16px/1.6 Alexandria,system-ui,sans-serif;color:'.$t['text'].';display:grid;place-items:center;min-height:100vh;padding:16px;box-sizing:border-box}'
            .'main{background:#fff;max-width:460px;width:100%;border-radius:'.$t['radius'].'px;padding:32px 26px;text-align:center;box-shadow:0 16px 40px -20px rgba(0,0,0,.3)}img{width:72px;height:72px;border-radius:50%}'
            .'h1{font-size:24px;margin:14px 0 8px}a,button{display:inline-block;margin-top:14px;background:'.$t['brand'].';color:#fff;border:0;border-radius:999px;padding:12px 26px;font:inherit;font-weight:700;text-decoration:none;cursor:pointer}'
            .'button{background:#fff;color:'.$t['brand'].';border:2px solid '.$t['brand'].'}</style></head>'
            .'<body><main><img src="'.$logo.'" alt=""><h1>'.e($title).'</h1><p>'.e($text).'</p>'.$extra.'<a href="'.$site.'">'.($ar ? 'الذهاب للمتجر' : 'Go to the shop').'</a></main></body></html>';
    }
}
