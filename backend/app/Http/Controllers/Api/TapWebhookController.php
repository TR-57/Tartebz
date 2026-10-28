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
 * POST /api/payments/tap/webhook – Tap tells us a charge (chg_…) or a refund (re_…) changed. The body is only a hint:
 * the charge is fetched again from Tap with our secret key, and that answer is what is recorded.
 */
class TapWebhookController extends Controller
{
    public function handle(Request $r)
    {
        $id = (string) $r->input('id', '');
        if (preg_match('/^re_[A-Za-z0-9]+$/', $id)) {
            return $this->refund($id);
        }
        if (! preg_match('/^chg_[A-Za-z0-9]+$/', $id)) {
            return response()->json(['ok' => true]); // not a charge: nothing to do
        }
        $o = DB::table('orders')->where('tap_charge_id', $id)->first();
        if (! $o) {
            return $this->olderAttempt($id);
        }
        if (! Cache::add('tap-hook:'.$id, 1, 5)) {
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

    /** A refund changed: fetched again from Tap, like charges. */
    private function refund(string $id)
    {
        $rf = DB::table('order_refunds')->where('tap_refund_id', $id)->first();
        if (! $rf || $rf->status !== 'pending' || ! Cache::add('tap-hook:'.$id, 1, 5)) {
            return response()->json(['ok' => true]);
        }
        $o = DB::table('orders')->find($rf->order_id);
        try {
            \App\Support\Refunds::fromTap($rf->id, Tap::retrieveRefund($id, $o->tap_mode ?: null));
        } catch (ApiError $e) {
            return response()->json(['ok' => false], 502);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * A charge that is no longer the order's current one (the customer started a new payment later).
     * If Tap says it was paid anyway, the team is told: one of the two payments must be refunded in Tap.
     */
    private function olderAttempt(string $id)
    {
        $ev = DB::table('order_events')->where('type', 'payment_started')->where('data', 'like', '%"'.$id.'"%')->first();
        if (! $ev || ! Cache::add('tap-hook:'.$id, 1, 5)) {
            return response()->json(['ok' => true]);
        }
        $o = DB::table('orders')->find($ev->order_id);
        try {
            $charge = Tap::retrieve($id, $o->tap_mode ?: null);
        } catch (ApiError) {
            return response()->json(['ok' => false], 502);
        }
        if (strtoupper((string) ($charge['status'] ?? '')) === 'CAPTURED'
            && ! DB::table('order_events')->where('order_id', $o->id)->where('type', 'payment_extra')->where('data', 'like', '%"'.$id.'"%')->exists()) {
            Orders::event($o->id, 'payment_extra', 'An earlier payment attempt ('.$id.') was also paid. Check the Tap dashboard and refund the extra payment there.', ['charge' => $id]);
        }

        return response()->json(['ok' => true]);
    }
}
