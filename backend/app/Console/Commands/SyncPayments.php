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
 * in case a customer closed the page and Tap's notification did not arrive. Unsent emails are retried too.
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
        $mails = Outbox::retry();
        $this->info("Checked {$n} open payments, retried {$mails} emails.");

        return self::SUCCESS;
    }
}
