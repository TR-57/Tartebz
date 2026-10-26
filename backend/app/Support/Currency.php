<?php

namespace App\Support;

/**
 * Currencies. Every price and every total is kept in the base currency (BHD).
 * A customer may see and pay in another currency: amount = BHD × rate, rounded to that currency's decimals.
 * Whatever the customer paid, the BHD figure is what admin screens, sales and reports add up
 * (orders save the currency, the rate they used and the BHD amounts – a later rate change never rewrites old orders).
 */
class Currency
{
    public static function base(): string
    {
        return (string) (Settings::get('currencies')['base'] ?? 'BHD');
    }

    /** code => currency row, enabled or not. */
    public static function all(): array
    {
        $out = [];
        foreach ((array) (Settings::get('currencies')['list'] ?? []) as $c) {
            $out[$c['code']] = $c;
        }

        return $out;
    }

    public static function enabled(): array
    {
        return array_filter(self::all(), fn ($c) => ! empty($c['enabled']) || $c['code'] === self::base());
    }

    public static function get(string $code): ?array
    {
        return self::enabled()[strtoupper($code)] ?? null;
    }

    /** Price in a customer currency, from a BHD amount. */
    public static function fromBase(float $bhd, string $code, ?float $rate = null): float
    {
        $c = self::get($code) ?? self::get(self::base());

        return round($bhd * ($rate ?? (float) $c['rate']), (int) $c['decimals']);
    }

    /** BHD value of an amount paid in another currency, using the rate saved with it. Always 3 decimals. */
    public static function toBase(float $amount, float $rate): float
    {
        return $rate > 0 ? round($amount / $rate, 3) : 0.0;
    }

    /** Checks and cleans a currencies setting sent from the admin. Returns [value, fieldErrors]. */
    public static function clean(mixed $list): array
    {
        $f = [];
        $out = [];
        $seen = [];
        $base = self::base();
        foreach (array_values((array) $list) as $i => $c) {
            $c = (array) $c;
            $code = strtoupper(trim((string) ($c['code'] ?? '')));
            if (! preg_match('/^[A-Z]{3}$/', $code)) {
                $f["list.$i.code"] = 'A currency code is 3 letters, like SAR.';
            } elseif (isset($seen[$code])) {
                $f["list.$i.code"] = "{$code} is in the list twice.";
            }
            $seen[$code] = true;
            $rate = (float) ($c['rate'] ?? 0);
            if ($code === $base) {
                $rate = 1;
            } elseif ($rate <= 0 || $rate > 100000) {
                $f["list.$i.rate"] = 'Enter how much of this currency the customer pays for 1 '.$base.'.';
            }
            $dec = (int) ($c['decimals'] ?? 2);
            $countries = array_values(array_unique(array_filter(array_map(fn ($x) => strtoupper(trim((string) $x)), (array) ($c['countries'] ?? [])),
                fn ($x) => preg_match('/^[A-Z]{2}$/', $x))));
            $out[] = [
                'code' => $code, 'rate' => round($rate, 6), 'decimals' => max(0, min(3, $dec)),
                'symbol' => mb_substr(trim((string) ($c['symbol'] ?? $code)), 0, 10) ?: $code,
                'symbol_ar' => mb_substr(trim((string) ($c['symbol_ar'] ?? '')), 0, 10),
                'countries' => $countries,
                'enabled' => $code === $base ? true : (bool) ($c['enabled'] ?? true),
            ];
        }
        if (! isset($seen[$base])) {
            $f['list'] = "{$base} must stay in the list – every price is kept in {$base}.";
        }
        // A country can only pay in one currency.
        $owner = [];
        foreach ($out as $i => $c) {
            foreach ($c['countries'] as $cc) {
                if (isset($owner[$cc]) && $c['enabled']) {
                    $f["list.$i.countries"] = "{$cc} already uses {$owner[$cc]}.";
                }
                if ($c['enabled']) {
                    $owner[$cc] = $c['code'];
                }
            }
        }

        return [['base' => $base, 'list' => $out], $f];
    }

    /** What the storefront needs. */
    public static function forStorefront(): array
    {
        return array_values(array_map(fn ($c) => [
            'code' => $c['code'], 'rate' => (float) $c['rate'], 'dec' => (int) $c['decimals'],
            'sym' => ['en' => $c['symbol'], 'ar' => $c['symbol_ar'] ?: $c['symbol']], 'countries' => $c['countries'],
        ], self::enabled()));
    }
}
