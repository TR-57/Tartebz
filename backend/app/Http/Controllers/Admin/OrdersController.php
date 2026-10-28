<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Currency;
use App\Support\Orders;
use App\Support\Refunds;
use App\Support\Smsa;
use App\Support\Tap;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Orders. Staff and shop staff (orders board): view, filter, tick as prepared, status, notes, payment check,
 * SMSA shipment / label / tracking; shop staff may also mark paid (money received on WhatsApp / Benefit / cash).
 * Managers: mark paid by hand (WhatsApp / bank transfer), cancel (stock is given back), cancel a shipment.
 * Every amount is BHD; the customer's own currency is shown next to it.
 */
class OrdersController extends Controller
{
    /** List tabs: status filters. "prepare" and "prepared" follow the tick on the orders board. */
    private const TABS = [
        'all' => null,
        'todo' => ['paid', 'processing'],
        'prepare' => ['paid', 'processing'],
        'prepared' => ['paid', 'processing'],
        'unpaid' => ['pending_payment', 'awaiting_whatsapp', 'payment_failed'],
        'shipped' => ['shipped'],
        'sent' => ['shipped', 'delivered'],
        'done' => ['delivered'],
        'cancelled' => ['cancelled', 'refunded'],
    ];

    /**
     * {tab?, q?, from?, to? (Y-m-d, shop time), country?, page?, per?}
     * Dates and country narrow every tab, so the counts match what the filters show.
     */
    public function index(Request $r)
    {
        $tab = array_key_exists($r->query('tab'), self::TABS) ? $r->query('tab') : 'all';
        $base = DB::table('orders');
        if ($term = $this->str($r, 'q', 100)) {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $base->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('first_name', 'like', $like)->orWhere('last_name', 'like', $like)
                ->orWhere('phone', 'like', $like)->orWhere('email', 'like', $like)->orWhere('awb', 'like', $like));
        }
        foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
            $d = (string) $r->query($k, '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                $base->where('created_at', $op, $d.($k === 'from' ? ' 00:00:00' : ' 23:59:59'));
            }
        }
        $country = strtoupper((string) $r->query('country', ''));
        if (preg_match('/^[A-Z]{2}$/', $country)) {
            $base->where('country', $country);
        }
        $counts = [];
        foreach (array_keys(self::TABS) as $k) {
            $counts[$k] = $this->inTab(clone $base, $k)->count();
        }
        $per = max(10, min(100, (int) $r->query('per', 50)));
        $page = max(1, (int) $r->query('page', 1));
        $q = $this->inTab(clone $base, $tab);
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('id')->offset(($page - 1) * $per)->limit($per)->get();
        $lines = DB::table('order_items')->whereIn('order_id', $rows->pluck('id')->all() ?: [0])->orderBy('id')->get()->groupBy('order_id');

        return $this->ok([
            'orders' => $rows->map(fn ($o) => $this->row($o) + [
                'items' => (int) ($lines[$o->id] ?? collect())->sum('qty'),
                'lines' => ($lines[$o->id] ?? collect())->map(fn ($i) => ['name' => $i->name, 'nameAr' => $i->name_ar, 'qty' => (int) $i->qty])->values(),
            ])->values(),
            'counts' => $counts, 'total' => $total, 'page' => $page, 'per' => $per, 'labels' => Orders::LABELS,
            'countries' => DB::table('orders')->distinct()->orderBy('country')->pluck('country')->filter()->values(),
        ]);
    }

    private function inTab($q, string $tab)
    {
        $st = self::TABS[$tab];
        if ($st) {
            $q->whereIn('status', $st);
        }
        if ($tab === 'prepare') {
            $q->whereNull('prepared_at');
        } elseif ($tab === 'prepared') {
            $q->whereNotNull('prepared_at');
        }

        return $q;
    }

    /** The tick on the orders board: {done: true|false}. A paid order becomes "Preparing"; unticking puts it back. */
    public function prepared(Request $r, int $id)
    {
        $o = $this->find($id);
        $done = $r->boolean('done');
        if (in_array($o->status, ['cancelled', 'refunded'], true)) {
            throw new ApiError('closed', 'This order is cancelled.', 409);
        }
        $admin = $this->admin($r);
        $upd = $done ? ['prepared_at' => $o->prepared_at ?: now(), 'prepared_by' => $admin->name] : ['prepared_at' => null, 'prepared_by' => ''];
        if ($done && $o->status === 'paid') {
            $upd['status'] = 'processing';
        } elseif (! $done && $o->status === 'processing') {
            $upd['status'] = 'paid';
        }
        DB::table('orders')->where('id', $id)->update($upd + ['updated_at' => now()]);
        if ((bool) $o->prepared_at !== $done) {
            Orders::event($id, $done ? 'prepared' : 'unprepared', $done ? 'Ticked as prepared.' : 'Tick removed (not prepared).', [], $admin);
        }

        return $this->ok(['order' => $this->row(DB::table('orders')->find($id)) + ['items' => 0]]);
    }

    public function show(int $id)
    {
        $o = $this->find($id);

        return $this->ok(['order' => $this->full($o), 'labels' => Orders::LABELS, 'smsa' => ['enabled' => Smsa::enabled(), 'mode' => Smsa::mode(), 'problems' => array_merge(Smsa::consignee($o)[1], Smsa::shipperProblems())]]);
    }

    /** {status: processing|shipped|delivered|paid} – the hand-moved steps. */
    public function status(Request $r, int $id)
    {
        $o = $this->find($id);
        $to = $this->str($r, 'status', 30);
        $allowed = ['processing', 'shipped', 'delivered'];
        if (! in_array($to, $allowed, true)) {
            throw ApiError::validation(['status' => 'Choose a status.']);
        }
        if ($o->payment_status !== 'paid') {
            throw new ApiError('unpaid', 'This order is not paid yet. Mark it as paid first (WhatsApp / bank transfer), or wait for the card payment.', 409);
        }
        $upd = ['status' => $to, 'updated_at' => now()];
        if ($to === 'shipped' && ! $o->shipped_at) {
            $upd['shipped_at'] = now();
        }
        if ($to === 'delivered') {
            $upd['delivered_at'] = now();
        }
        DB::table('orders')->where('id', $id)->update($upd);
        Orders::event($id, 'status', 'Status: '.Orders::LABELS[$to].'.', [], $this->admin($r));
        Audit::log($r, 'order_status', 'order', $id, ['from' => $o->status, 'to' => $to]);
        if ($to === 'shipped' && $o->status !== 'shipped') {
            Orders::emailShipped(DB::table('orders')->find($id));
        }

        return $this->ok(['order' => $this->full(DB::table('orders')->find($id))]);
    }

    public function note(Request $r, int $id)
    {
        $this->find($id);
        $text = mb_substr(trim((string) $r->input('note', '')), 0, 500);
        if ($text === '') {
            throw ApiError::validation(['note' => 'Write the note first.']);
        }
        Orders::event($id, 'note', $text, [], $this->admin($r));

        return $this->ok(['order' => $this->full(DB::table('orders')->find($id))]);
    }

    /** Asks Tap again (the customer says they paid, or the page was closed early). */
    public function refreshPayment(Request $r, int $id)
    {
        $o = $this->find($id);
        if (! $o->tap_charge_id) {
            throw new ApiError('no_charge', 'This order has no card payment to check.', 409);
        }
        $charge = Tap::retrieve($o->tap_charge_id, $o->tap_mode ?: null);
        $o = Orders::applyTapCharge($id, $charge);
        Orders::event($id, 'payment_checked', 'Payment checked with Tap: '.($charge['status'] ?? '?').'.', [], $this->admin($r));

        return $this->ok(['order' => $this->full($o), 'tapStatus' => $charge['status'] ?? '']);
    }

    /** Managers: money received outside the website (WhatsApp order paid by Benefit transfer, cash…). */
    public function markPaid(Request $r, int $id)
    {
        $how = mb_substr(trim((string) $r->input('how', '')), 0, 120);
        if ($how === '') {
            throw ApiError::validation(['how' => 'Say how it was paid (e.g. BenefitPay transfer, cash).']);
        }
        DB::transaction(function () use ($r, $id, $how) {
            $o = DB::table('orders')->where('id', $id)->lockForUpdate()->first() ?? throw ApiError::notFound('That order does not exist.');
            if ($o->paid_at) {
                throw new ApiError('already_paid', 'This order is already paid.', 409);
            }
            if (in_array($o->status, ['cancelled', 'refunded'], true)) {
                throw new ApiError('cancelled', 'This order is cancelled.', 409);
            }
            Orders::markPaid($o, 'Marked as paid by hand: '.$how.'.', [], $this->admin($r));
        });
        Audit::log($r, 'order_marked_paid', 'order', $id, ['how' => $how]);

        return $this->ok(['order' => $this->full(DB::table('orders')->find($id))]);
    }

    public function cancel(Request $r, int $id)
    {
        $reason = mb_substr(trim((string) $r->input('reason', '')), 0, 300);
        $o = $this->find($id);
        if (in_array($o->status, ['cancelled', 'refunded'], true)) {
            throw new ApiError('cancelled', 'This order is already cancelled.', 409);
        }
        if (in_array($o->status, ['shipped', 'delivered'], true)) {
            throw new ApiError('shipped', 'This order has shipped. Cancel the SMSA shipment first, or handle it as a return.', 409);
        }
        DB::transaction(function () use ($o, $id, $reason, $r) {
            Orders::giveBackStock($o);
            \App\Support\Holds::releaseOrder($id);
            DB::table('orders')->where('id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);
            Orders::event($id, 'cancelled', 'Cancelled'.($reason ? ': '.$reason : '.').($o->payment_status === 'paid' && $o->tap_charge_id
                ? ' The payment is not given back by cancelling – use Refund on this order.' : ''), [], $this->admin($r));
        });
        Audit::log($r, 'order_cancelled', 'order', $id, ['reason' => $reason]);

        return $this->ok(['order' => $this->full(DB::table('orders')->find($id))]);
    }

    /**
     * Managers: gives money back. {amount (paid currency), reason?, restock?, method: tap|manual}
     * tap = sent to Tap (card orders); manual = money already returned another way, just recorded.
     */
    public function refund(Request $r, int $id)
    {
        $this->find($id);
        $method = $r->input('method') === 'manual' ? 'manual' : 'tap';
        $amount = filter_var($r->input('amount'), FILTER_VALIDATE_FLOAT);
        if ($amount === false) {
            throw ApiError::validation(['amount' => 'Enter the amount to refund.']);
        }
        $reason = $this->str($r, 'reason', 300);
        $rf = Refunds::start($id, (float) $amount, $reason, $r->boolean('restock'), $method, $this->admin($r));
        Audit::log($r, 'order_refund', 'order', $id, ['method' => $method, 'amount' => (float) $rf->amount, 'currency' => $rf->currency, 'status' => $rf->status]);

        return $this->ok(['order' => $this->full(DB::table('orders')->find($id)), 'refund' => ['status' => $rf->status]]);
    }

    /**
     * Managers: a pending refund. Card refunds with a Tap id are checked with Tap; otherwise {result: done|failed}
     * settles it by hand (after looking in the Tap dashboard).
     */
    public function refundCheck(Request $r, int $id, int $refundId)
    {
        $this->find($id);
        $rf = DB::table('order_refunds')->where('id', $refundId)->where('order_id', $id)->first() ?? throw ApiError::notFound('That refund does not exist.');
        if ($rf->status !== 'pending') {
            return $this->ok(['order' => $this->full(DB::table('orders')->find($id))]);
        }
        $result = (string) $r->input('result', '');
        if (in_array($result, ['done', 'failed'], true)) {
            Refunds::settle($refundId, $result, 'marked by hand', $this->admin($r));
            Audit::log($r, 'order_refund_settled', 'order', $id, ['refund' => $refundId, 'result' => $result]);
        } elseif ($rf->tap_refund_id !== '') {
            $o = DB::table('orders')->find($id);
            Refunds::fromTap($refundId, Tap::retrieveRefund($rf->tap_refund_id, $o->tap_mode ?: null));
        } else {
            throw ApiError::validation(['result' => 'Check the Tap dashboard, then say whether the refund went through.']);
        }

        return $this->ok(['order' => $this->full(DB::table('orders')->find($id))]);
    }

    /** Books the SMSA shipment and keeps the label. {parcels?, weightKg?} */
    public function ship(Request $r, int $id)
    {
        $o = $this->find($id);
        if ($o->payment_status !== 'paid') {
            throw new ApiError('unpaid', 'Ship paid orders only.', 409);
        }
        if ($o->awb !== '') {
            throw new ApiError('has_awb', 'This order already has an SMSA shipment ('.$o->awb.').', 409);
        }
        if (! Smsa::enabled()) {
            throw new ApiError('smsa_not_set', 'SMSA is not switched on: Settings › Shipping – save the API key and switch it on.', 409);
        }
        $problems = array_merge(Smsa::consignee($o)[1], Smsa::shipperProblems());
        if ($problems) {
            throw new ApiError('fix_address', implode(' ', $problems), 422);
        }
        $parcels = max(1, min(25, (int) $r->input('parcels', 1)));
        $kg = $r->filled('weightKg') ? max(0.1, (float) $r->input('weightKg')) : null;
        $res = Smsa::createShipment($o, $parcels, $kg);
        $label = '';
        if ($res['pdf']) {
            $dir = storage_path('app/labels');
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $label = 'labels/'.$o->number.'-'.preg_replace('/[^A-Za-z0-9]/', '', $res['awb']).'.pdf';
            file_put_contents(storage_path('app/'.$label), $res['pdf']);
        }
        DB::table('orders')->where('id', $id)->update([
            'carrier' => 'smsa:'.Smsa::mode(), 'awb' => $res['awb'], 'label_path' => $label,
            'status' => $o->status === 'paid' ? 'processing' : $o->status, 'updated_at' => now(),
        ]);
        Orders::event($id, 'shipment', 'SMSA shipment booked ('.(Smsa::mode() === 'live' ? 'live' : 'sandbox').'): '.$res['awb'].'.', ['sawb' => $res['sawb']], $this->admin($r));
        Audit::log($r, 'order_shipment', 'order', $id, ['awb' => $res['awb'], 'mode' => Smsa::mode()]);

        return $this->ok(['order' => $this->full(DB::table('orders')->find($id))]);
    }

    public function label(int $id)
    {
        $o = $this->find($id);
        $file = $o->label_path ? storage_path('app/'.$o->label_path) : '';
        if (! $file || ! is_file($file)) {
            throw ApiError::notFound('No label saved for this order.');
        }

        return response()->file($file, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.basename($file).'"', 'Cache-Control' => 'private, no-store']);
    }

    public function track(Request $r, int $id)
    {
        $o = $this->find($id);
        if ($o->awb === '' || ! str_starts_with($o->carrier, 'smsa')) {
            throw new ApiError('no_awb', 'This order has no SMSA shipment.', 409);
        }
        $t = Smsa::track($o->awb, explode(':', $o->carrier)[1] ?? null);
        $upd = ['tracking_status' => mb_substr($t['status'], 0, 200), 'tracking_at' => now(), 'updated_at' => now()];
        if ($t['delivered'] && $o->status !== 'delivered') {
            $upd += ['status' => 'delivered', 'delivered_at' => now()];
            Orders::event($id, 'status', 'Delivered (SMSA tracking).');
        } elseif ($t['scans'] && in_array($o->status, ['paid', 'processing'], true) && count($t['scans']) > 1) {
            $upd += ['status' => 'shipped', 'shipped_at' => $o->shipped_at ?? now()];
            Orders::event($id, 'status', 'Shipped (SMSA picked it up).');
            Orders::emailShipped((object) ((array) $o));
        }
        DB::table('orders')->where('id', $id)->update($upd);

        return $this->ok(['order' => $this->full(DB::table('orders')->find($id)), 'scans' => $t['scans']]);
    }

    public function cancelShipment(Request $r, int $id)
    {
        $o = $this->find($id);
        if ($o->awb === '') {
            throw new ApiError('no_awb', 'This order has no SMSA shipment.', 409);
        }
        Smsa::cancel($o->awb);
        DB::table('orders')->where('id', $id)->update(['awb' => '', 'carrier' => '', 'label_path' => '', 'tracking_status' => '',
            'status' => in_array($o->status, ['shipped', 'processing'], true) ? 'paid' : $o->status, 'updated_at' => now()]);
        Orders::event($id, 'shipment_cancelled', 'SMSA shipment '.$o->awb.' cancelled.', [], $this->admin($r));
        Audit::log($r, 'order_shipment_cancelled', 'order', $id, ['awb' => $o->awb]);

        return $this->ok(['order' => $this->full(DB::table('orders')->find($id))]);
    }

    /* ---------------------------------------------------------------- */

    private function find(int $id): object
    {
        return DB::table('orders')->find($id) ?? throw ApiError::notFound('That order does not exist.');
    }

    private function row(object $o): array
    {
        return [
            'id' => $o->id, 'number' => $o->number, 'status' => $o->status, 'statusLabel' => Orders::LABELS[$o->status] ?? $o->status,
            'paymentStatus' => $o->payment_status, 'method' => $o->payment_method, 'name' => trim($o->first_name.' '.$o->last_name),
            'phone' => $o->phone, 'country' => $o->country, 'total' => (float) $o->total, 'currency' => $o->currency,
            'customerTotal' => $o->currency !== 'BHD' ? round((float) $o->total * (float) $o->fx_rate, (int) (Currency::all()[$o->currency]['decimals'] ?? 2)) : null,
            'testMode' => $o->tap_mode === 'test' || str_ends_with($o->carrier, ':test'),
            'awb' => $o->awb, 'createdAt' => $o->created_at,
            'prepared' => $o->prepared_at !== null, 'preparedAt' => $o->prepared_at, 'preparedBy' => $o->prepared_by,
            'source' => $o->source, 'createdBy' => $o->created_by_name, 'alertAt' => $o->alert_at, 'email' => $o->email,
            'customerUrl' => Orders::customerUrl($o),
        ];
    }

    private function full(object $o): array
    {
        return $this->row($o) + [
            'firstName' => $o->first_name, 'lastName' => $o->last_name, 'email' => $o->email, 'state' => $o->state, 'city' => $o->city,
            'address1' => $o->address1, 'address2' => $o->address2, 'notes' => (string) $o->notes, 'lang' => $o->lang,
            'subtotal' => (float) $o->subtotal, 'shipping' => (float) $o->shipping, 'discount' => (float) $o->discount,
            'fxRate' => (float) $o->fx_rate, 'chargeCurrency' => $o->charge_currency, 'chargeAmount' => (float) $o->charge_amount,
            'weightGrams' => (int) $o->weight_grams, 'tapChargeId' => $o->tap_charge_id, 'tapStatus' => $o->tap_status, 'tapMode' => $o->tap_mode,
            'paidAt' => $o->paid_at, 'carrier' => $o->carrier, 'hasLabel' => $o->label_path !== '', 'trackingStatus' => $o->tracking_status,
            'trackingAt' => $o->tracking_at, 'shippedAt' => $o->shipped_at, 'deliveredAt' => $o->delivered_at,
            'trackingUrl' => $o->awb ? 'https://www.smsaexpress.com/trackingdetails?tracknumbers='.rawurlencode($o->awb) : null,
            'heldUntil' => $o->paid_at || in_array($o->status, ['cancelled', 'refunded'], true) ? null : \App\Support\Holds::orderHeldUntil($o->id),
            'refundedAmount' => (float) $o->refunded_amount, 'refundedBhd' => (float) $o->refunded_bhd,
            'refundable' => $o->paid_at && $o->payment_status === 'paid' ? Refunds::refundable($o) : 0,
            'refunds' => Refunds::forOrder($o->id),
            'whatsapp' => 'https://wa.me/'.preg_replace('/\D/', '', $o->phone),
            'items' => DB::table('order_items')->where('order_id', $o->id)->get()->map(fn ($i) => [
                'productId' => $i->product_id, 'name' => $i->name, 'nameAr' => $i->name_ar, 'image' => $i->image ? \App\Support\Thumbs::url($i->image, 200) : null,
                'price' => (float) $i->price, 'qty' => (int) $i->qty, 'lineTotal' => (float) $i->line_total,
            ])->values(),
            'events' => DB::table('order_events')->where('order_id', $o->id)->orderByDesc('id')->limit(200)->get()->map(fn ($e) => [
                'type' => $e->type, 'message' => $e->message, 'by' => $e->admin_name, 'at' => $e->created_at,
            ])->values(),
        ];
    }
}
