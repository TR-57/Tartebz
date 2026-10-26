<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Orders;
use App\Support\Tap;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/payments/tap/webhook – Tap tells us a charge changed. The body is only a hint:
 * the charge is fetched again from Tap with our secret key, and that answer is what is recorded.
 */
class TapWebhookController extends Controller
{
    public function handle(Request $r)
    {
        $id = (string) $r->input('id', '');
        if (! preg_match('/^chg_[A-Za-z0-9]+$/', $id)) {
            return response()->json(['ok' => true]); // not a charge: nothing to do
        }
        $o = DB::table('orders')->where('tap_charge_id', $id)->first();
        if (! $o || ! Cache::add('tap-hook:'.$id, 1, 5)) {
            return response()->json(['ok' => true]);
        }
        $secret = Tap::secret($o->tap_mode ?: null);
        $hash = (string) $r->header('hashstring', '');
        if ($hash !== '' && $secret !== '' && ! hash_equals(Tap::signature($r->all(), $secret), $hash)) {
            Orders::event($o->id, 'webhook_signature', 'A Tap notification arrived with a signature that does not match – the charge was checked with Tap directly.');
        }
        try {
            Orders::applyTapCharge($o->id, Tap::retrieve($id, $o->tap_mode ?: null));
        } catch (ApiError $e) {
            return response()->json(['ok' => false], 502); // Tap retries later
        }

        return response()->json(['ok' => true]);
    }
}
