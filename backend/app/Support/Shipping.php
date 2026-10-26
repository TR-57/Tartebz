<?php

namespace App\Support;

/** Delivery price in BHD for a parcel weight and a country (Admin › Settings › Shipping). */
class Shipping
{
    public static function countries(): array
    {
        return array_values((array) (Settings::get('shipping')['countries'] ?? []));
    }

    public static function delivers(string $country): bool
    {
        return in_array(strtoupper($country), self::countries(), true);
    }

    public static function cost(float $kg, string $country): float
    {
        if ($kg <= 0) {
            return 0.0;
        }
        $s = Settings::get('shipping');
        foreach ((array) $s['flat'] as $f) {
            if (($f['country'] ?? '') === strtoupper($country)) {
                return round((float) $f['price'], 3);
            }
        }
        $table = (array) $s['table'];
        foreach ($table as [$upTo, $price]) {
            if ($kg <= $upTo + 1e-9) {
                return round((float) $price, 3);
            }
        }
        [$lastKg, $lastPrice] = end($table) ?: [0, 0];

        return round($lastPrice + ceil(($kg - $lastKg) / 0.5) * (float) $s['extraPerHalfKg'], 3);
    }

    /** International dialling codes of the delivery countries (phone numbers for Tap and SMSA). */
    public const DIAL = ['BH' => '973', 'SA' => '966', 'AE' => '971', 'QA' => '974', 'KW' => '965', 'OM' => '968', 'EG' => '20', 'JO' => '962', 'US' => '1', 'GB' => '44'];

    /** [country code, local number] from what the customer typed. */
    public static function splitPhone(string $phone, string $country): array
    {
        $d = preg_replace('/\D/', '', $phone);
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        }
        foreach (self::DIAL as $code) {
            if (str_starts_with($d, $code) && strlen($d) - strlen($code) >= 7) {
                return [$code, substr($d, strlen($code))];
            }
        }
        $code = self::DIAL[strtoupper($country)] ?? '973';

        return [$code, ltrim($d, '0')];
    }
}
