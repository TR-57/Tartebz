<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Currency;
use App\Support\Settings;
use Illuminate\Http\Request;

/** Currencies the customer can see and pay in (owner). Prices themselves stay in BHD. */
class CurrenciesController extends Controller
{
    public function index()
    {
        return $this->ok(['base' => Currency::base(), 'list' => array_values(Currency::all())]);
    }

    public function save(Request $r)
    {
        [$value, $f] = Currency::clean($r->input('list', []));
        if ($f) {
            throw ApiError::validation($f);
        }
        $before = Currency::all();
        Settings::set('currencies', $value);
        $changes = [];
        foreach ($value['list'] as $c) {
            $old = $before[$c['code']] ?? null;
            if (! $old || (float) $old['rate'] !== (float) $c['rate'] || (bool) $old['enabled'] !== $c['enabled']) {
                $changes[$c['code']] = ['rate' => [$old['rate'] ?? null, $c['rate']], 'enabled' => $c['enabled']];
            }
        }
        Audit::log($r, 'currencies_saved', 'settings', 'currencies', $changes);

        return $this->ok(['base' => $value['base'], 'list' => $value['list']]);
    }
}
