<?php

namespace App\Support;

use Carbon\Carbon;

/** Money in the right currency and dates in the shop's time zone, the way customers read them. */
class Fmt
{
    public static function tz(): string
    {
        $tz = (string) (Settings::get('business')['timezone'] ?? 'Asia/Bahrain');

        return in_array($tz, timezone_identifiers_list(), true) ? $tz : 'Asia/Bahrain';
    }

    /** 3.500 د.ب (Arabic) / BHD 3.500 (English), with the currency's own decimals. */
    public static function money(float $amount, string $currency = 'BHD', string $lang = 'en'): string
    {
        $c = Currency::all()[$currency] ?? ['decimals' => in_array($currency, ['BHD', 'KWD', 'OMR', 'JOD'], true) ? 3 : 2, 'symbol' => $currency, 'symbol_ar' => ''];
        $n = number_format($amount, (int) $c['decimals']);

        return $lang === 'ar' ? $n.' '.(($c['symbol_ar'] ?? '') ?: $currency) : (($c['symbol'] ?? '') ?: $currency).' '.$n;
    }

    /** An order amount (BHD) in the currency the customer pays in. */
    public static function orderMoney(object $o, float $bhd, string $lang = 'en'): string
    {
        $rate = $o->charge_currency === 'BHD' ? 1.0 : (float) $o->fx_rate;

        return self::money($bhd * $rate, $o->charge_currency, $lang);
    }

    public static function local(mixed $when): Carbon
    {
        return Carbon::parse($when)->setTimezone(self::tz());
    }

    /** الأربعاء 19 نوفمبر، 3:30 م / Wed 19 Nov, 3:30 pm */
    public static function dateTime(mixed $when, string $lang = 'en'): string
    {
        $d = self::local($when)->locale($lang === 'ar' ? 'ar' : 'en');

        return $lang === 'ar' ? $d->translatedFormat('l j F، g:i a') : $d->translatedFormat('D j M, g:i a');
    }

    public static function date(mixed $when, string $lang = 'en'): string
    {
        $d = self::local($when)->locale($lang === 'ar' ? 'ar' : 'en');

        return $lang === 'ar' ? $d->translatedFormat('j F Y') : $d->translatedFormat('j M Y');
    }

    /** "3 days" / "٣ أيام" style. */
    public static function days(int $n, string $lang = 'en'): string
    {
        if ($lang === 'ar') {
            return match (true) { $n <= 1 => 'يوم واحد', $n === 2 => 'يومين', $n <= 10 => $n.' أيام', default => $n.' يوم' };
        }

        return $n === 1 ? '1 day' : $n.' days';
    }
}
