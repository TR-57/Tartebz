<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Back-in-stock requests. When a product becomes available again, everyone who left an email gets one
 * (in their language, after the response has gone out). Phone numbers are listed on the product in the admin
 * with a ready WhatsApp message – staff send it and mark it done.
 */
class StockAlerts
{
    /** Call after any change that may have put a product back on sale. */
    public static function afterChange(int $productId, bool $wasAvailable): int
    {
        $p = DB::table('products')->find($productId);
        if (! $p || $wasAvailable || ! Catalog::available($p) || ! (Settings::get('storefront')['backInStock'] ?? true)) {
            return 0;
        }
        $sent = 0;
        $rows = DB::table('stock_alerts')->where('product_id', $productId)->whereNull('notified_at')->whereNotNull('email')->get();
        foreach ($rows as $a) {
            $ar = $a->lang !== 'en';
            $name = $ar && $p->name_ar ? $p->name_ar : $p->name;
            $url = config('store.store_url').'/product/'.rawurlencode($p->slug).($ar ? '' : '?lang=en');
            $subject = $ar ? "رجع «{$name}» متوفر من جديد!" : "{$name} is back in stock!";
            $body = $ar
                ? '<h1 style="margin:0 0 12px;font-size:22px;">رجع متوفر 🎉</h1><p>طلبت منا نخبرك لما يتوفر <b>'.e($name).'</b> – وهو الحين متوفر. الكمية محدودة، فاطلبه قبل لا يخلص.</p>'.EmailLayout::button('اطلب الآن', $url)
                : '<h1 style="margin:0 0 12px;font-size:22px;">It’s back 🎉</h1><p>You asked us to tell you when <b>'.e($name).'</b> was back. It is now – stock is limited, so grab it before it’s gone.</p>'.EmailLayout::button('Order now', $url);
            Outbox::queue($a->email, $subject, EmailLayout::wrap($ar ? 'ar' : 'en', $subject, $body), ['kind' => 'transactional']);
            DB::table('stock_alerts')->where('id', $a->id)->update(['notified_at' => now(), 'notified_by' => 'email']);
            $sent++;
        }

        return $sent;
    }

    /** The WhatsApp message staff send to a phone subscriber. */
    public static function whatsappText(object $p, string $lang): string
    {
        $ar = $lang !== 'en';
        $name = $ar && $p->name_ar ? $p->name_ar : $p->name;
        $url = config('store.store_url').'/product/'.rawurlencode($p->slug).($ar ? '' : '?lang=en');

        return $ar
            ? "مرحبا 👋 هذا ترتيبز. طلبت منا نخبرك لما يتوفر «{$name}» – وهو الحين متوفر من جديد 🎉\n{$url}"
            : "Hi 👋 it’s Tartebz. You asked us to tell you when “{$name}” was back – it’s in stock again 🎉\n{$url}";
    }

    /** "97333334444" from "+973 3333 4444" / "0097333334444"; Bahrain local 8-digit numbers get 973. */
    public static function normalisePhone(string $raw): string
    {
        $d = preg_replace('/\D/', '', $raw);
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        }
        if (strlen($d) === 8) {
            $d = '973'.$d;
        }

        return $d;
    }
}
