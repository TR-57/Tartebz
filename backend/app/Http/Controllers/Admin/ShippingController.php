<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Settings;
use App\Support\Shipping;
use Illuminate\Http\Request;

/**
 * Owner: Admin › Shipping rates. Destinations (a country, a group of countries, or "*" for everywhere else), each
 * with its delivery methods – by air, by road, home delivery … – priced flat, by formula or by a weight table.
 * Same model as Sniper Safety; App\Support\Shipping prices them.
 */
class ShippingController extends Controller
{
    public function index()
    {
        return $this->ok(['destinations' => array_map(fn ($d) => ['methods' => array_map([Shipping::class, 'method'], (array) ($d['methods'] ?? []))] + $d, Shipping::destinations()),
            'pricing' => Shipping::PRICING, 'general' => Shipping::cfg()]);
    }

    /** {destinations: [{id?, name{en,ar}, countries[], methods[{id?, name{en,ar}, pricing, base, firstKg, stepKg, perStep, table, min, max, freeOver, maxKg, etaMin, etaMax, active}]}]} */
    public function save(Request $r)
    {
        $in = $r->input('destinations');
        if (! is_array($in) || ! array_is_list($in) || ! $in || count($in) > 50) {
            throw ApiError::validation(['destinations' => 'Keep 1 to 50 destinations.']);
        }
        $f = [];
        $out = [];
        $seenCountry = [];
        $ids = [];
        $str = fn ($v, $max) => is_scalar($v) ? mb_substr(trim((string) $v), 0, $max) : '';
        $num = function ($v, string $key, bool $optional, float $max = 100000) use (&$f): ?float {
            if ($v === null || $v === '') {
                if (! $optional) {
                    $f[$key] = 'Enter a number.';
                }

                return null;
            }
            if (! is_numeric($v) || (float) $v < 0 || (float) $v > $max) {
                $f[$key] = 'A number from 0 to '.$max.'.';

                return null;
            }

            return round((float) $v, 3);
        };
        $slug = fn (string $s) => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
        foreach ($in as $i => $d) {
            $d = (array) $d;
            $k = "destinations.$i";
            $name = ['en' => $str($d['name']['en'] ?? '', 60), 'ar' => $str($d['name']['ar'] ?? '', 60)];
            if ($name['en'] === '') {
                $f["$k.name"] = 'Give the destination a name (e.g. GCC).';
            }
            $countries = [];
            foreach ((array) ($d['countries'] ?? []) as $c) {
                $c = strtoupper($str($c, 3));
                if ($c === '') {
                    continue;
                }
                if ($c !== '*' && ! preg_match('/^[A-Z]{2}$/', $c)) {
                    $f["$k.countries"] = 'Two-letter country codes (BH, SA, AE …) or * for every other country.';

                    continue;
                }
                if (isset($seenCountry[$c])) {
                    $f["$k.countries"] = ($c === '*' ? 'Only one destination can be “everywhere else” (*).' : $c.' is already in another destination.');
                }
                $seenCountry[$c] = true;
                $countries[] = $c;
            }
            if (! $countries) {
                $f["$k.countries"] = 'Add at least one country.';
            }
            $id = $slug($str($d['id'] ?? '', 40)) ?: ($slug($name['en']) ?: 'dest-'.($i + 1));
            while (isset($ids['d:'.$id])) {
                $id .= '-'.($i + 1);
            }
            $ids['d:'.$id] = true;
            $methods = [];
            $list = (array) ($d['methods'] ?? []);
            if (! $list || count($list) > 10) {
                $f["$k.methods"] = 'Keep 1 to 10 delivery methods.';
            }
            foreach (array_values($list) as $j => $m) {
                $m = (array) $m;
                $mk = "$k.methods.$j";
                $mName = ['en' => $str($m['name']['en'] ?? '', 60), 'ar' => $str($m['name']['ar'] ?? '', 60)];
                if ($mName['en'] === '') {
                    $f["$mk.name"] = 'Give the method a name (e.g. By air).';
                }
                $pricing = in_array($m['pricing'] ?? '', Shipping::PRICING, true) ? $m['pricing'] : 'flat';
                $mid = $slug($str($m['id'] ?? '', 60)) ?: $id.'-'.($slug($mName['en']) ?: ($j + 1));
                while (isset($ids['m:'.$mid])) {
                    $mid .= '-'.($j + 1);
                }
                $ids['m:'.$mid] = true;
                $table = [];
                if ($pricing === 'table') {
                    $prev = 0.0;
                    foreach (array_values((array) ($m['table'] ?? [])) as $t => $row) {
                        $row = array_values((array) $row);
                        $kg = $num($row[0] ?? null, "$mk.table.$t", false, 1000);
                        $price = $num($row[1] ?? null, "$mk.table.$t", false);
                        if ($kg !== null && $kg <= $prev) {
                            $f["$mk.table.$t"] = 'Weights must go up row by row.';
                        }
                        $prev = (float) $kg;
                        $table[] = [(float) $kg, (float) $price];
                    }
                    if (! $table) {
                        $f["$mk.table"] = 'Add at least one weight row.';
                    }
                }
                $eMin = $num($m['etaMin'] ?? null, "$mk.etaMin", true, 90);
                $eMax = $num($m['etaMax'] ?? null, "$mk.etaMax", true, 90);
                if ($eMin !== null && $eMax !== null && $eMax < $eMin) {
                    $f["$mk.etaMax"] = 'The longest time must not be shorter than the shortest.';
                }
                $min = $num($m['min'] ?? null, "$mk.min", true);
                $max = $num($m['max'] ?? null, "$mk.max", true);
                if ($min !== null && $max !== null && $max < $min) {
                    $f["$mk.max"] = 'The highest price must not be below the lowest.';
                }
                $methods[] = Shipping::method([
                    'id' => $mid, 'name' => $mName, 'pricing' => $pricing,
                    'base' => $num($m['base'] ?? 0, "$mk.base", true) ?? 0.0,
                    'firstKg' => $num($m['firstKg'] ?? 0, "$mk.firstKg", true, 1000) ?? 0.0,
                    'stepKg' => $num($m['stepKg'] ?? 0.5, "$mk.stepKg", true, 1000) ?? 0.0,
                    'perStep' => $num($m['perStep'] ?? 0, "$mk.perStep", true) ?? 0.0,
                    'table' => $table, 'min' => $min, 'max' => $max,
                    'freeOver' => $num($m['freeOver'] ?? null, "$mk.freeOver", true),
                    'maxKg' => $num($m['maxKg'] ?? null, "$mk.maxKg", true, 1000),
                    'etaMin' => $eMin, 'etaMax' => $eMax, 'etaUnit' => ($m['etaUnit'] ?? 'working') === 'calendar' ? 'calendar' : 'working',
                    'active' => filter_var($m['active'] ?? true, FILTER_VALIDATE_BOOL),
                ]);
            }
            $out[] = ['id' => $id, 'name' => $name, 'countries' => $countries, 'methods' => $methods];
        }
        // General: the weight of a product with none, the daily cut-off (Bahrain time) and the weekend
        $g = (array) $r->input('general', []) + Shipping::cfg();
        $fallback = $num($g['fallbackKg'] ?? 0.5, 'general.fallbackKg', false, 100);
        $cutoff = $g['cutoffHour'] ?? null;
        if ($cutoff !== null && $cutoff !== '' && (! is_numeric($cutoff) || (int) $cutoff < 0 || (int) $cutoff > 24)) {
            $f['general.cutoffHour'] = 'An hour from 0 to 24, or empty for no cut-off.';
        }
        $weekend = array_values(array_unique(array_map('intval', array_filter((array) ($g['weekend'] ?? []), fn ($d) => is_numeric($d) && (int) $d >= 0 && (int) $d <= 6))));
        if (count($weekend) > 5) {
            $f['general.weekend'] = 'Keep at least two working days.';
        }
        if ($f) {
            throw ApiError::validation($f);
        }
        Settings::set('shipping', ['fallbackKg' => (float) $fallback, 'cutoffHour' => $cutoff === null || $cutoff === '' ? null : (int) $cutoff,
            'weekend' => $weekend, 'destinations' => $out]);
        Audit::log($r, 'shipping_saved', 'settings', 'shipping', ['destinations' => count($out), 'methods' => array_sum(array_map(fn ($d) => count($d['methods']), $out))]);

        return $this->index();
    }
}
