<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Secret;
use App\Support\Settings;
use App\Support\Shipping;
use App\Support\Smsa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Owner: Admin › SMSA shipping (as in Sniper Safety). The API keys (write-only), sandbox or live, the sender address,
 * which delivery methods go through SMSA – by air or by road for those going abroad – who pays import VAT and duty,
 * and the on/off switch for automatic waybills.
 */
class SmsaController extends Controller
{
    public function show()
    {
        $s = Smsa::cfg();
        $counts = ['created' => 0, 'moving' => 0, 'delivered' => 0, 'failed' => 0, 'cancelled' => 0];
        foreach (DB::table('shipments')->where('carrier', 'smsa')->groupBy('status')->selectRaw('status, COUNT(*) AS n')->get() as $row) {
            $counts[$row->status] = (int) $row->n;
        }

        return $this->ok([
            'settings' => [
                'enabled' => ! empty($s['enabled']), 'since' => $s['since'] ?? null, 'mode' => Smsa::mode(), 'methods' => array_values((array) ($s['methods'] ?? [])),
                'autoStatus' => ! empty($s['autoStatus']), 'contents' => (string) ($s['contents'] ?? ''), 'shipper' => $s['shipper'], 'dutiesBy' => Smsa::dutiesBy(),
                'retailId' => (string) ($s['retailId'] ?? ''), 'testUrl' => $s['testUrl'], 'liveUrl' => $s['liveUrl'],
            ],
            'keys' => ['test' => Secret::describe($s['testKey'] ?? ''), 'live' => Secret::describe($s['liveKey'] ?? '')],
            'active' => Smsa::enabled(),
            'missing' => Smsa::missing(),
            'problems' => Smsa::shipperProblems(),
            'methods' => self::methodList(),
            'counts' => $counts,
            'failed' => DB::table('shipments as sh')->join('orders as o', 'o.id', '=', 'sh.order_id')->where('sh.status', 'failed')
                ->orderByDesc('sh.updated_at')->limit(20)->get(['o.id', 'o.number', 'o.first_name', 'o.last_name', 'sh.error', 'sh.attempts', 'sh.updated_at'])
                ->map(fn ($x) => ['id' => $x->id, 'number' => $x->number, 'name' => trim($x->first_name.' '.$x->last_name), 'error' => $x->error,
                    'attempts' => (int) $x->attempts, 'at' => $x->updated_at])->values(),
        ]);
    }

    /** Every delivery method (Admin › Shipping rates), with its SMSA service when it goes abroad. */
    private static function methodList(): array
    {
        $home = Smsa::home();
        $saved = (array) (Smsa::cfg()['services'] ?? []);
        $out = [];
        foreach (Shipping::destinations() as $d) {
            $countries = array_values(array_map('strtoupper', (array) ($d['countries'] ?? [])));
            foreach ((array) ($d['methods'] ?? []) as $m) {
                $out[] = ['id' => (string) $m['id'], 'name' => Shipping::names($m), 'destination' => Shipping::names($d), 'countries' => $countries,
                    'active' => ($m['active'] ?? true) !== false,
                    'abroad' => $countries !== [] && array_diff($countries, [$home]) !== [],
                    'service' => Smsa::serviceOf($m), 'serviceSaved' => isset(Smsa::SERVICES[(string) ($saved[(string) $m['id']] ?? '')])];
            }
        }

        return $out;
    }

    /** {enabled, mode, methods[], services{id: EIDL|EIED}, dutiesBy, autoStatus, contents, retailId, shipper{}, testKey?, liveKey?, clearTestKey?, clearLiveKey?, testUrl?, liveUrl?} */
    public function save(Request $r)
    {
        $cur = Smsa::cfg();
        $next = $cur;
        $f = [];
        $changed = [];
        if ($r->has('dutiesBy') && ! in_array($r->input('dutiesBy'), Smsa::DUTIES, true)) {
            $f['dutiesBy'] = 'Choose who pays import VAT and duty: the customer or us.';
        }
        $known = array_flip(array_column(self::methodList(), 'id'));
        if ($r->has('services')) {
            $svc = $r->input('services');
            if (! is_array($svc) || ($svc !== [] && array_is_list($svc))) {
                $f['services'] = 'Choose by air (EIDL) or by road (EIED) for each method.';
            } else {
                foreach ($svc as $id => $code) {
                    if (! isset($known[(string) $id]) || ! is_string($code) || ! isset(Smsa::SERVICES[$code])) {
                        $f['services'] = 'Choose by air (EIDL) or by road (EIED) for each method.';

                        break;
                    }
                    if ((($cur['services'] ?? [])[(string) $id] ?? null) !== $code) {
                        $changed[] = 'service for '.$id.': '.$code;
                    }
                    $next['services'][(string) $id] = $code;
                }
            }
        }
        if ($r->has('methods')) {
            $next['methods'] = array_values(array_unique(array_filter(array_map(fn ($m) => is_scalar($m) ? (string) $m : '', (array) $r->input('methods')), fn ($m) => isset($known[$m]))));
        }
        foreach (['testKey', 'liveKey'] as $k) {
            $v = trim((string) $r->input($k, ''));
            if ($this->bool($r, 'clear'.ucfirst($k))) {
                $next[$k] = '';
                $changed[] = $k.' removed';
            } elseif ($v !== '') {
                if (! preg_match('/^[\x21-\x7E]{8,200}$/', $v)) {
                    $f[$k] = 'That does not look like an SMSA API key.';
                } else {
                    $next[$k] = Secret::seal($v);
                    $changed[] = $k;
                }
            }
        }
        foreach (['testUrl', 'liveUrl'] as $k) {
            if ($r->filled($k)) {
                $u = rtrim(trim((string) $r->input($k)), '/');
                if (! preg_match('#^https://[a-z0-9.-]+(:\d+)?$#i', $u)) {
                    $f[$k] = 'An https:// address, like https://ecomapis.smsaexpress.com';
                }
                $next[$k] = $u;
            }
        }
        if ($r->has('shipper')) {
            $sh = (array) $r->input('shipper');
            foreach (['name' => 150, 'phone' => 30, 'city' => 50, 'district' => 100, 'address1' => 100, 'address2' => 100, 'postalCode' => 20] as $k => $max) {
                $next['shipper'][$k] = is_scalar($sh[$k] ?? '') ? mb_substr(trim((string) ($sh[$k] ?? '')), 0, $max) : '';
            }
            $c = strtoupper(trim((string) ($sh['country'] ?? 'BH')));
            $next['shipper']['country'] = preg_match('/^[A-Z]{2}$/', $c) ? $c : 'BH';
        }
        if ($r->has('contents')) {
            $next['contents'] = $this->str($r, 'contents', 100);
        }
        if ($r->has('retailId')) {
            $next['retailId'] = $this->str($r, 'retailId', 40);
        }
        if ($r->has('dutiesBy') && ! isset($f['dutiesBy'])) {
            if ($r->input('dutiesBy') !== Smsa::dutiesBy()) {
                $changed[] = 'import VAT and duty paid by '.$r->input('dutiesBy');
            }
            $next['dutiesBy'] = $r->input('dutiesBy');
        }
        if ($r->has('autoStatus')) {
            $next['autoStatus'] = $this->bool($r, 'autoStatus');
        }
        if ($r->has('mode')) {
            $next['mode'] = $r->input('mode') === 'live' ? 'live' : 'test';
        }
        if ($r->has('enabled')) {
            $on = $this->bool($r, 'enabled');
            if ($on && empty($cur['enabled'])) {
                $next['since'] = now()->toDateTimeString();   // only orders placed from now on
            }
            $next['enabled'] = $on;
        }
        if ($f) {
            throw ApiError::validation($f);
        }
        if ($next != $cur) {
            Settings::set('smsa', $next);
            if (($next['enabled'] ?? false) !== ($cur['enabled'] ?? false)) {
                $changed[] = ! empty($next['enabled']) ? 'switched on' : 'switched off';
            }
            if (($next['mode'] ?? '') !== ($cur['mode'] ?? '')) {
                $changed[] = 'mode '.$next['mode'];
            }
        }
        if (! empty($next['enabled']) && ($missing = Smsa::missing())) {
            $next['enabled'] = false;
            Settings::set('smsa', $next);
            throw new ApiError('smsa_setup', 'Saved, but SMSA stays off until you enter '.implode(', ', $missing).'.', 422);
        }
        Audit::log($r, 'smsa_saved', 'settings', 'smsa', ['changed' => $changed]);

        return $this->show();
    }

    /** {mode: test|live} – is the key right? */
    public function test(Request $r)
    {
        $mode = $r->input('mode') === 'live' ? 'live' : 'test';
        $res = Smsa::test($mode);
        Audit::log($r, 'smsa_tested', 'settings', 'smsa', ['mode' => $mode, 'connected' => $res['connected']]);

        return $this->ok($res);
    }
}
