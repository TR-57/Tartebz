<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Secret;
use App\Support\Settings;
use App\Support\Smsa;
use Illuminate\Http\Request;

/** Owner: delivery prices and the SMSA Express connection (keys, sandbox/live, sender address, test). */
class ShippingController extends Controller
{
    public function index()
    {
        return $this->ok($this->present());
    }

    public function save(Request $r)
    {
        $f = [];
        // Delivery prices
        $ship = Settings::get('shipping');
        if ($r->has('rates')) {
            $rates = (array) $r->input('rates');
            $countries = array_values(array_unique(array_filter(array_map(fn ($c) => strtoupper(trim((string) $c)), (array) ($rates['countries'] ?? [])), fn ($c) => preg_match('/^[A-Z]{2}$/', $c))));
            $flat = [];
            foreach (array_values((array) ($rates['flat'] ?? [])) as $i => $x) {
                $cc = strtoupper(trim((string) (((array) $x)['country'] ?? '')));
                $price = (float) (((array) $x)['price'] ?? -1);
                if (! preg_match('/^[A-Z]{2}$/', $cc) || $price < 0 || $price > 1000) {
                    $f["rates.flat.$i"] = 'A country code (e.g. BH) and a price of 0 or more.';
                }
                $flat[] = ['country' => $cc, 'price' => round($price, 3)];
            }
            $table = [];
            $prev = 0;
            foreach (array_values((array) ($rates['table'] ?? [])) as $i => $row) {
                [$kg, $price] = array_map('floatval', array_values((array) $row) + [0, 0]);
                if ($kg <= $prev || $price < 0) {
                    $f["rates.table.$i"] = 'Weights must go up row by row, and prices be 0 or more.';
                }
                $prev = $kg;
                $table[] = [round($kg, 2), round($price, 3)];
            }
            if (! $countries) {
                $f['rates.countries'] = 'Deliver to at least one country.';
            }
            if (! $table) {
                $f['rates.table'] = 'Keep at least one weight row.';
            }
            $ship = ['countries' => $countries, 'flat' => $flat, 'table' => $table, 'extraPerHalfKg' => max(0, round((float) ($rates['extraPerHalfKg'] ?? 0), 3))];
        }
        // SMSA
        $s = Settings::get('smsa');
        foreach (['testKey', 'liveKey'] as $k) {
            if ($this->bool($r, 'clear'.ucfirst($k))) {
                $s[$k] = '';
            } elseif (($v = trim((string) $r->input($k, ''))) !== '') {
                $s[$k] = Secret::seal($v);
            }
        }
        $mode = $r->input('mode') === 'live' ? 'live' : 'test';
        $enabled = $this->bool($r, 'enabled', (bool) $s['enabled']);
        if ($enabled && Secret::open($s[$mode.'Key']) === '') {
            $f[$mode.'Key'] = 'Save the '.($mode === 'live' ? 'live' : 'sandbox').' API key before switching SMSA on in that mode.';
        }
        foreach (['testUrl', 'liveUrl'] as $k) {
            if ($r->has($k)) {
                $u = rtrim(trim((string) $r->input($k)), '/');
                if (! preg_match('#^https://[a-z0-9.-]+(:\d+)?$#i', $u)) {
                    $f[$k] = 'An https:// address, like https://ecomapis.smsaexpress.com';
                }
                $s[$k] = $u;
            }
        }
        $s['enabled'] = $enabled;
        $s['mode'] = $mode;
        $s['serviceCode'] = $this->str($r, 'serviceCode', 20);
        $s['retailId'] = $this->str($r, 'retailId', 40);
        $s['contentDescription'] = $this->str($r, 'contentDescription', 100) ?: 'Sweets';
        $s['declaredCurrency'] = preg_match('/^[A-Z]{3}$/', (string) $r->input('declaredCurrency')) ? $r->input('declaredCurrency') : 'BHD';
        $sh = (array) $r->input('shipper', []);
        foreach (['name' => 150, 'phone' => 30, 'country' => 2, 'city' => 50, 'district' => 100, 'address1' => 100, 'address2' => 100, 'postalCode' => 20] as $k => $max) {
            $s['shipper'][$k] = mb_substr(trim((string) ($sh[$k] ?? $s['shipper'][$k] ?? '')), 0, $max);
        }
        $s['shipper']['country'] = strtoupper($s['shipper']['country'] ?: 'BH');
        if ($f) {
            throw ApiError::validation($f);
        }
        Settings::set('shipping', $ship);
        Settings::set('smsa', $s);
        Audit::log($r, 'shipping_saved', 'settings', 'shipping', ['smsa' => ['enabled' => $enabled, 'mode' => $mode]]);

        return $this->ok($this->present());
    }

    public function test(Request $r)
    {
        $mode = $r->input('mode') === 'live' ? 'live' : 'test';
        $res = Smsa::test($mode);
        Audit::log($r, 'shipping_tested', 'settings', 'smsa', ['mode' => $mode, 'connected' => $res['connected']]);

        return $this->ok($res);
    }

    private function present(): array
    {
        $s = Settings::get('smsa');

        return [
            'rates' => Settings::get('shipping'),
            'smsa' => [
                'enabled' => (bool) $s['enabled'], 'mode' => $s['mode'], 'testKey' => Secret::describe($s['testKey']), 'liveKey' => Secret::describe($s['liveKey']),
                'testUrl' => $s['testUrl'], 'liveUrl' => $s['liveUrl'], 'serviceCode' => $s['serviceCode'], 'retailId' => $s['retailId'],
                'contentDescription' => $s['contentDescription'], 'declaredCurrency' => $s['declaredCurrency'], 'shipper' => $s['shipper'],
                'active' => Smsa::enabled(), 'problems' => Smsa::shipperProblems(),
            ],
        ];
    }
}
