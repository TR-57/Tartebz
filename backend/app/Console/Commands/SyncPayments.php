<?php

namespace App\Console\Commands;

use App\Support\ApiError;
use App\Support\Orders;
use App\Support\Outbox;
use App\Support\Tap;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Safety net, every 5 minutes (cron): card payments still open after 5 minutes are checked with Tap,
 * in case a customer closed the page and Tap's notification did not arrive. Pending card refunds are checked the same way,
 * unsent emails are retried, expired stock reservations are cleared, and the run is noted (store:doctor checks cron runs).
 */
class SyncPayments extends Command
{
    protected $signature = 'store:sync-payments';

    protected $description = 'Check unfinished Tap payments with Tap and retry unsent emails';

    public function handle(): int
    {
        $n = 0;
        $open = DB::table('orders')->where('payment_method', 'tap')->where('status', 'pending_payment')->where('tap_charge_id', '<>', '')
            ->where('updated_at', '<', now()->subMinutes(5))->where('created_at', '>', now()->subDays(3))->limit(100)->get();
        foreach ($open as $o) {
            try {
                Orders::applyTapCharge($o->id, Tap::retrieve($o->tap_charge_id, $o->tap_mode ?: null));
                $n++;
            } catch (ApiError $e) {
                $this->warn("{$o->number}: {$e->getMessage()}");
            }
        }
        // Older than a day and never paid: the card page has long expired.
        DB::table('orders')->where('payment_method', 'tap')->where('status', 'pending_payment')->where('created_at', '<', now()->subDay())
            ->update(['status' => 'payment_failed', 'payment_status' => 'failed', 'updated_at' => now()]);
        // Card refunds Tap has not confirmed yet.
        $refunds = DB::table('order_refunds')->join('orders', 'orders.id', '=', 'order_refunds.order_id')
            ->where('order_refunds.status', 'pending')->where('order_refunds.tap_refund_id', '<>', '')
            ->where('order_refunds.updated_at', '<', now()->subMinutes(2))->where('order_refunds.created_at', '>', now()->subDays(30))
            ->select('order_refunds.id', 'order_refunds.tap_refund_id', 'orders.tap_mode', 'orders.number')->limit(50)->get();
        foreach ($refunds as $rf) {
            try {
                \App\Support\Refunds::fromTap($rf->id, Tap::retrieveRefund($rf->tap_refund_id, $rf->tap_mode ?: null));
                DB::table('order_refunds')->where('id', $rf->id)->where('status', 'pending')->update(['updated_at' => now()]);
            } catch (ApiError $e) {
                $this->warn("{$rf->number} refund: {$e->getMessage()}");
            }
        }
        $mails = Outbox::retry();
        $freed = \App\Support\Holds::purge();
        \Illuminate\Support\Facades\Cache::forever('cron:last-run', now()->toIso8601String());
        $this->info("Checked {$n} open payments, retried {$mails} emails, freed {$freed} expired reservations.");

        return self::SUCCESS;
    }
}
