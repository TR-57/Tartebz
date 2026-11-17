<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The pop-up: an email address (with consent) for a code. One code per address – asking again re-sends nothing new.
 */
class Popup
{
    public static function cfg(): array
    {
        return Settings::get('popup');
    }

    /** What the storefront shows. */
    public static function forStorefront(): array
    {
        $p = self::cfg();

        return ['enabled' => (bool) $p['enabled'], 'delay' => (int) $p['delaySeconds'],
            'title' => ['en' => $p['title'], 'ar' => $p['title_ar']], 'text' => ['en' => $p['text'], 'ar' => $p['text_ar']]];
    }

    /** {email, consent, lang} → ['sent' => bool, 'already' => bool] */
    public static function submit(string $email, bool $consent, string $lang, string $country = ''): array
    {
        $p = self::cfg();
        if (! $p['enabled']) {
            throw new ApiError('off', 'This offer has ended.', 409);
        }
        $email = strtolower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ApiError::validation(['email' => 'Enter a valid email.']);
        }
        if (! $consent) {
            throw ApiError::validation(['consent' => 'Tick the box to get offers by email.']);
        }
        $lang = $lang === 'en' ? 'en' : 'ar';
        $consentText = $lang === 'ar' ? 'أوافق على استلام العروض بالبريد الإلكتروني' : 'I agree to get offers by email';
        Subscribers::subscribe($email, ['lang' => $lang, 'country' => $country, 'source' => 'popup', 'consent' => $consentText], true);
        $coupon = DB::table('coupons')->where('source', 'popup')->whereJsonContains('emails', $email)->first();
        if ($coupon) {
            return ['sent' => false, 'already' => true];   // one code per address
        }
        $c = $p['coupon'];
        $coupon = Coupons::personal($email, (string) $c['type'], (float) $c['value'], isset($c['min']) && $c['min'] !== null && $c['min'] !== '' ? (float) $c['min'] : null, (int) $c['days'], 'popup');
        $vars = ['name' => '', 'code' => $coupon->code, 'offer' => Coupons::offer($coupon, $lang), 'ends' => Fmt::date($coupon->ends_at, $lang),
            'valid_for' => Fmt::days((int) $c['days'], $lang)];
        $id = EmailSender::send('popup_code', $email, $lang, [
            'vars' => $vars, 'blocks' => ['code_box' => Coupons::box($coupon, $lang)], 'url' => config('store.store_url').'/shop'.($lang === 'en' ? '?lang=en' : ''),
            'dedupe' => 'popup_code:'.$email,
        ]);

        return ['sent' => $id > 0, 'already' => false];
    }
}
