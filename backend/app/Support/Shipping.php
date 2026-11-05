<?php

namespace App\Support;

/**
 * Delivery prices (Admin › Settings › Shipping rates), the same model as Sniper Safety.
 *
 * A destination is one country or a group of countries ("*" = everywhere not listed elsewhere). It offers its own
 * delivery methods – for example "By air" and "By road" – each with its own price and delivery time:
 *
 *   flat     base, whatever the weight
 *   formula  base covers the first `firstKg`; every further `stepKg` (or part of one) adds `perStep`
 *   table    weight bands [[up to kg, price], …]; above the last band every further `stepKg` adds `perStep`
 *
 * Then, in order: clamp to `min` / `max` (empty = no limit); free once the subtotal reaches `freeOver`
 * (empty = never); `maxKg` hides the method for heavier parcels. All money is BHD.
 *
 * The storefront has a twin of price() in assets/js/app.js (TZ.methodPrice): keep them in step, so the cart shows
 * exactly what checkout charges.
 */
class Shipping
{
    public const PRICING = ['flat', 'formula', 'table'];

    /** The destinations, as saved – or built from the older single price list when nothing new was saved yet. */
    public static function destinations(): array
    {
        $raw = Settings::raw('shipping');
        if (is_array($raw) && isset($raw['destinations']) && is_array($raw['destinations'])) {
            return array_values($raw['destinations']);
        }
        if (is_array($raw) && isset($raw['table'])) {
            return self::fromLegacy($raw);
        }

        return array_values((array) (Settings::get('shipping')['destinations'] ?? []));
    }

    /** Older settings (countries + flat prices + one weight table) as destinations, so nothing changes for customers. */
    public static function fromLegacy(array $old): array
    {
        $flat = [];
        foreach ((array) ($old['flat'] ?? []) as $f) {
            $flat[strtoupper((string) ($f['country'] ?? ''))] = (float) ($f['price'] ?? 0);
        }
        $out = [];
        foreach ($flat as $cc => $price) {
            $out[] = ['id' => strtolower($cc), 'name' => ['en' => $cc, 'ar' => $cc], 'countries' => [$cc], 'methods' => [
                self::method(['id' => strtolower($cc).'-home', 'name' => ['en' => 'Home delivery', 'ar' => 'توصيل للمنزل'], 'pricing' => 'flat', 'base' => $price, 'etaMin' => 1, 'etaMax' => 1]),
            ]];
        }
        $rest = array_values(array_diff(array_map('strtoupper', (array) ($old['countries'] ?? [])), array_keys($flat)));
        if ($rest) {
            $table = array_map(fn ($r) => [(float) $r[0], (float) $r[1]], (array) ($old['table'] ?? []));
            $out[] = ['id' => 'gcc', 'name' => ['en' => 'GCC', 'ar' => 'دول الخليج'], 'countries' => $rest, 'methods' => [
                self::method(['id' => 'gcc-air', 'name' => ['en' => 'By air (SMSA)', 'ar' => 'شحن جوي (سمسا)'], 'pricing' => 'table', 'table' => $table,
                    'stepKg' => 0.5, 'perStep' => (float) ($old['extraPerHalfKg'] ?? 0), 'etaMin' => 2, 'etaMax' => 4]),
                self::method(['id' => 'gcc-road', 'name' => ['en' => 'By road (SMSA)', 'ar' => 'شحن بري (سمسا)'], 'pricing' => 'table', 'table' => $table,
                    'stepKg' => 0.5, 'perStep' => (float) ($old['extraPerHalfKg'] ?? 0), 'etaMin' => 4, 'etaMax' => 7, 'active' => false]),
            ]];
        }

        return $out;
    }

    /** A method with every field present (missing ones take their defaults). */
    public static function method(array $m): array
    {
        return $m + [
            'id' => '', 'name' => ['en' => '', 'ar' => ''], 'pricing' => 'flat', 'base' => 0.0, 'firstKg' => 0.0, 'stepKg' => 0.5, 'perStep' => 0.0,
            'table' => [], 'min' => null, 'max' => null, 'freeOver' => null, 'maxKg' => null, 'etaMin' => null, 'etaMax' => null, 'active' => true,
        ];
    }

    /** Every country delivered to (the "*" catch-all aside). */
    public static function countries(): array
    {
        $out = [];
        foreach (self::destinations() as $d) {
            foreach ((array) ($d['countries'] ?? []) as $c) {
                if ($c !== '*') {
                    $out[] = strtoupper($c);
                }
            }
        }

        return array_values(array_unique($out));
    }

    public static function destination(string $country): ?array
    {
        $country = strtoupper($country);
        $all = self::destinations();
        foreach ($all as $d) {
            if (in_array($country, array_map('strtoupper', (array) ($d['countries'] ?? [])), true)) {
                return $d;
            }
        }
        foreach ($all as $d) {
            if (in_array('*', (array) ($d['countries'] ?? []), true)) {
                return $d;
            }
        }

        return null;
    }

    /** Delivered there, with at least one method switched on. */
    public static function delivers(string $country): bool
    {
        $d = self::destination($country);

        return $d !== null && collect($d['methods'] ?? [])->contains(fn ($m) => ($m['active'] ?? true) !== false);
    }

    private static function num(mixed $v): ?float
    {
        return $v === '' || $v === null || ! is_numeric($v) ? null : (float) $v;
    }

    /** One method's price for a parcel: ['available' => bool, 'price' => float, 'free' => bool]. */
    public static function price(array $m, float $kg, float $subtotal): array
    {
        $kg = max(0.0, $kg);
        $maxKg = self::num($m['maxKg'] ?? null);
        if ($maxKg !== null && $kg > $maxKg) {
            return ['available' => false, 'price' => null, 'free' => false];
        }
        $pricing = in_array($m['pricing'] ?? '', self::PRICING, true) ? $m['pricing'] : 'flat';
        $step = self::num($m['stepKg'] ?? null) ?? 0.0;
        $perStep = self::num($m['perStep'] ?? null) ?? 0.0;
        // round(..., 6) keeps 1.5 - 1 = 0.5000000001 from counting as a second step
        $steps = fn (float $over) => $step > 0 && $over > 0 ? (int) ceil(round($over / $step, 6)) : 0;
        if ($pricing === 'formula') {
            $p = (self::num($m['base'] ?? null) ?? 0.0) + $steps($kg - (self::num($m['firstKg'] ?? null) ?? 0.0)) * $perStep;
        } elseif ($pricing === 'table') {
            $bands = array_values(array_filter((array) ($m['table'] ?? []), fn ($b) => is_array($b) && count($b) >= 2));
            usort($bands, fn ($a, $b) => (float) $a[0] <=> (float) $b[0]);
            $p = null;
            foreach ($bands as [$upTo, $price]) {
                if ($kg <= (float) $upTo + 1e-9) {
                    $p = (float) $price;
                    break;
                }
            }
            if ($p === null) {
                [$lastKg, $lastPrice] = $bands ? end($bands) : [0, 0];
                $p = (float) $lastPrice + $steps($kg - (float) $lastKg) * $perStep;
            }
        } else {
            $p = self::num($m['base'] ?? null) ?? 0.0;
        }
        $min = self::num($m['min'] ?? null);
        $max = self::num($m['max'] ?? null);
        if ($min !== null && $p < $min) {
            $p = $min;
        }
        if ($max !== null && $p > $max) {
            $p = $max;
        }
        $freeOver = self::num($m['freeOver'] ?? null);
        if ($freeOver !== null && $subtotal >= $freeOver) {
            return ['available' => true, 'price' => 0.0, 'free' => true];
        }
        $p = round(max(0.0, $p), 3);

        return ['available' => true, 'price' => $p, 'free' => $p == 0.0];
    }

    /** The methods on offer for a parcel to a country, in the order set in the admin. */
    public static function methods(string $country, float $kg, float $subtotal): array
    {
        $d = self::destination($country);
        $out = [];
        foreach ((array) ($d['methods'] ?? []) as $m) {
            if (($m['active'] ?? true) === false) {
                continue;
            }
            $r = self::price($m, $kg, $subtotal);
            if (! $r['available']) {
                continue;
            }
            $out[] = ['id' => (string) $m['id'], 'name' => self::names($m), 'price' => $r['price'], 'free' => $r['free'],
                'etaMin' => self::num($m['etaMin'] ?? null), 'etaMax' => self::num($m['etaMax'] ?? null)];
        }

        return $out;
    }

    /** The chosen method if it is on offer, else the first one; null when there is none. */
    public static function pick(string $country, float $kg, float $subtotal, ?string $methodId = null): ?array
    {
        $all = self::methods($country, $kg, $subtotal);
        foreach ($all as $m) {
            if ($m['id'] === (string) $methodId) {
                return $m;
            }
        }

        return $all[0] ?? null;
    }

    /** Cheapest price for a parcel (shown before a method is chosen). */
    public static function cost(float $kg, string $country, ?string $methodId = null, float $subtotal = 0.0): float
    {
        if ($kg <= 0) {
            return 0.0;
        }
        $m = self::pick($country, $kg, $subtotal, $methodId);

        return $m ? (float) $m['price'] : 0.0;
    }

    /** A method of any destination by id (the order keeps the id). */
    public static function findMethod(string $id): ?array
    {
        foreach (self::destinations() as $d) {
            foreach ((array) ($d['methods'] ?? []) as $m) {
                if ((string) ($m['id'] ?? '') === $id) {
                    return $m + ['destination' => $d];
                }
            }
        }

        return null;
    }

    public static function names(array $m): array
    {
        $n = $m['name'] ?? '';

        return is_array($n) ? ['en' => (string) ($n['en'] ?? ''), 'ar' => (string) (($n['ar'] ?? '') ?: ($n['en'] ?? ''))] : ['en' => (string) $n, 'ar' => (string) $n];
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
