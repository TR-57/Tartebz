<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Money given back to a customer.
 *  - Card orders (Tap): the refund is sent to Tap; it is "pending" until Tap confirms it (answer, webhook or the
 *    5-minute check), then "done".
 *  - Anything else (WhatsApp / bank transfer / cash, or a refund made in the Tap dashboard): recorded by hand, "done" at once.
 * Amounts are in the currency the customer paid; amount_bhd is what the reports subtract.
 * Pending refunds already count against what can still be refunded, so a double click never refunds twice.
 * When the whole payment is back, the order becomes "Refunded". Stock goes back only when asked, and only once.
 */
class Refunds
{
    public static function decimals(string $currency): int
    {
        return in_array($currency, ['BHD', 'KWD', 'OMR', 'JOD'], true) ? 3 : 2;
    }

    /** What can still be refunded, in the paid currency. */
    public static function refundable(object $o): float
    {
        $used = (float) DB::table('order_refunds')->where('order_id', $o->id)->whereIn('status', ['pending', 'done'])->sum('amount');

        return max(0, round((float) $o->charge_amount - $used, self::decimals($o->charge_currency)));
    }

    /**
     * Starts a refund. $method: tap | manual. Throws ApiError with a message for the team when it cannot be done.
     * Returns the refund row (already sent to Tap for card refunds).
     */
    public static function start(int $orderId, float $amount, string $reason, bool $restock, string $method, ?object $admin): object
    {
        $id = DB::transaction(function () use ($orderId, $amount, $reason, $restock, $method, $admin) {
            $o = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first() ?? throw ApiError::notFound('That order does not exist.');
            if (! $o->paid_at || $o->payment_status !== 'paid') {
                throw new ApiError('not_refundable', $o->payment_status === 'refunded' ? 'This order is already fully refunded.' : 'Only paid orders can be refunded.', 409);
            }
            if ($method === 'tap' && ($o->payment_method !== 'tap' || ! $o->tap_charge_id)) {
                throw new ApiError('not_card', 'This order was not paid by card through Tap. Give the money back yourself, then record the refund here.', 409);
            }
            $dec = self::decimals($o->charge_currency);
            $amount = round($amount, $dec);
            $left = self::refundable($o);
            if ($amount <= 0) {
                throw ApiError::validation(['amount' => 'Enter the amount to refund.']);
            }
            if ($amount > $left + 0.0005) {
                throw ApiError::validation(['amount' => 'The most that can still be refunded is '.$o->charge_currency.' '.number_format($left, $dec).'.']);
            }
            $last = abs($amount - $left) < 0.0005; // this refund gives back everything that is left
            if ($restock && ! $last) {
                throw ApiError::validation(['restock' => 'Stock goes back only with a full refund. For part of an order, change the stock in Products.']);
            }
            if ($last) {
                // The last refund takes whatever BHD is left, so the order adds up exactly to zero.
                $bhd = round((float) $o->total - (float) DB::table('order_refunds')->where('order_id', $o->id)->whereIn('status', ['pending', 'done'])->sum('amount_bhd'), 3);
            } else {
                $bhd = $o->charge_currency === 'BHD' ? $amount : round($amount / max(0.000001, (float) $o->fx_rate), 3);
            }

            $rid = DB::table('order_refunds')->insertGetId([
                'order_id' => $o->id, 'method' => $method, 'status' => 'pending', 'amount' => $amount, 'currency' => $o->charge_currency,
                'amount_bhd' => $bhd, 'reason' => mb_substr($reason, 0, 300), 'restocked' => $restock,
                'admin_id' => $admin->id ?? null, 'admin_name' => $admin->name ?? '', 'created_at' => now(), 'updated_at' => now(),
            ]);
            Orders::event($o->id, 'refund_started', ($method === 'tap' ? 'Card refund sent to Tap: ' : 'Refund recorded: ')
                .$o->charge_currency.' '.number_format($amount, $dec).($reason !== '' ? ' – '.$reason : '').($restock ? ' (items back in stock)' : '').'.', [], $admin);

            return $rid;
        });

        if ($method === 'manual') {
            self::settle($id, 'done');

            return DB::table('order_refunds')->find($id);
        }

        $o = DB::table('orders')->find($orderId);
        try {
            $re = Tap::refund($o, (float) DB::table('order_refunds')->where('id', $id)->value('amount'), $reason, $id);
        } catch (ApiError $e) {
            if ($e->errCode === 'tap_unreachable') {
                // Tap may or may not have received it: keep it pending (it still blocks a second refund) until someone checks.
                Orders::event($orderId, 'refund_unknown', 'Tap could not be reached while refunding. Check the Tap dashboard, then mark this refund as done or failed.');
                throw new ApiError('tap_unreachable', 'Could not reach Tap. Check the Tap dashboard before trying again – the refund is kept as "pending".', 502);
            }
            self::settle($id, 'failed', $e->getMessage());
            throw $e;
        }
        DB::table('order_refunds')->where('id', $id)->update(['tap_refund_id' => (string) ($re['id'] ?? ''), 'updated_at' => now()]);
        self::fromTap($id, $re);

        return DB::table('order_refunds')->find($id);
    }

    /** Applies a refund object fetched from Tap. */
    public static function fromTap(int $refundId, array $re): void
    {
        $state = Tap::refundState((string) ($re['status'] ?? ''));
        if ($state !== 'pending') {
            self::settle($refundId, $state, $state === 'failed' ? 'Tap: '.($re['response']['message'] ?? $re['status'] ?? 'failed') : '');
        }
    }

    /** Moves a pending refund to done or failed – once. Done: order totals, stock (if asked), customer email. */
    public static function settle(int $refundId, string $state, string $why = '', ?object $admin = null): void
    {
        $email = null;
        DB::transaction(function () use ($refundId, $state, $why, $admin, &$email) {
            $rf = DB::table('order_refunds')->where('id', $refundId)->lockForUpdate()->first();
            if (! $rf || $rf->status !== 'pending' || ! in_array($state, ['done', 'failed'], true)) {
                return;
            }
            $o = DB::table('orders')->where('id', $rf->order_id)->lockForUpdate()->first();
            DB::table('order_refunds')->where('id', $refundId)->update(['status' => $state, 'updated_at' => now()]);
            $money = $rf->currency.' '.number_format((float) $rf->amount, self::decimals($rf->currency));
            if ($state === 'failed') {
                Orders::event($o->id, 'refund_failed', 'Refund of '.$money.' did not go through'.($why !== '' ? ' ('.$why.')' : '').'.', [], $admin);

                return;
            }
            $done = DB::table('order_refunds')->where('order_id', $o->id)->where('status', 'done');
            $amount = (float) (clone $done)->sum('amount');
            $bhd = (float) (clone $done)->sum('amount_bhd');
            $full = $amount >= (float) $o->charge_amount - 0.0005;
            DB::table('orders')->where('id', $o->id)->update(['refunded_amount' => $amount, 'refunded_bhd' => $bhd, 'updated_at' => now()]
                + ($full ? ['payment_status' => 'refunded', 'status' => 'refunded'] : []));
            if ($rf->restocked) {
                Orders::giveBackStock($o);
            }
            Orders::event($o->id, 'refunded', 'Refunded '.$money.($full ? ' – the whole order.' : '.'), [], $admin);
            $email = [$o->id, $refundId];
        });
        if ($email) {
            self::emailCustomer(DB::table('orders')->find($email[0]), DB::table('order_refunds')->find($email[1]));
        }
    }

    public static function forOrder(int $orderId): array
    {
        return DB::table('order_refunds')->where('order_id', $orderId)->orderByDesc('id')->get()->map(fn ($r) => [
            'id' => $r->id, 'method' => $r->method, 'status' => $r->status, 'amount' => (float) $r->amount, 'currency' => $r->currency,
            'amountBhd' => (float) $r->amount_bhd, 'reason' => $r->reason, 'restocked' => (bool) $r->restocked, 'tapRefundId' => $r->tap_refund_id,
            'by' => $r->admin_name, 'at' => $r->created_at,
        ])->values()->all();
    }

    public static function emailCustomer(object $o, object $rf): void
    {
        if ($o->email === '') {
            return;
        }
        $ar = $o->lang !== 'en';
        $money = e($rf->currency.' '.number_format((float) $rf->amount, self::decimals($rf->currency)));
        $card = $rf->method === 'tap';
        $subject = $ar ? "استرجاع مبلغ من طلبك {$o->number}" : "Refund for your order {$o->number}";
        $body = $ar
            ? '<h1 style="margin:0 0 10px;font-size:22px;">تم استرجاع المبلغ</h1><p>أرجعنا لك <b><bdi>'.$money.'</bdi></b> من طلبك رقم <b>'.e($o->number).'</b>.</p>'
                .($card ? '<p>يرجع المبلغ لنفس البطاقة، وقد يحتاج من ٥ إلى ١٠ أيام عمل حتى يظهر في كشف حسابك حسب البنك.</p>' : '')
            : '<h1 style="margin:0 0 10px;font-size:22px;">Your refund is on its way</h1><p>We’ve refunded <b>'.$money.'</b> for order <b>'.e($o->number).'</b>.</p>'
                .($card ? '<p>It goes back to the same card and can take 5–10 working days to show on your statement, depending on your bank.</p>' : '');
        $body .= EmailLayout::button($ar ? 'عرض الطلب' : 'View your order', Orders::customerUrl($o));
        Outbox::queue($o->email, $subject, EmailLayout::wrap($ar ? 'ar' : 'en', $subject, $body), ['name' => trim($o->first_name.' '.$o->last_name)]);
    }
}
