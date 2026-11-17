<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** The timed emails: payment reminders and review requests. Run by `php artisan store:emails` every minute. */
class EmailJobs
{
    /** Once per unpaid card order, X minutes after it was placed, while its items are still held. */
    public static function paymentReminders(): int
    {
        if (! EmailTemplates::enabled('payment_reminder')) {
            return 0;
        }
        $mins = max(5, (int) (Settings::get('emails')['reminderMinutes'] ?? 30));
        $orders = DB::table('orders')->where('payment_method', 'tap')->whereNull('paid_at')->where('status', 'pending_payment')
            ->whereNull('reminder_sent_at')->where('email', '<>', '')->where('created_at', '<=', now()->subMinutes($mins))
            ->where('created_at', '>', now()->subDay())->orderBy('id')->limit(50)->get();
        $n = 0;
        foreach ($orders as $o) {
            DB::table('orders')->where('id', $o->id)->update(['reminder_sent_at' => now()]);   // once, whatever happens next
            if (! Holds::orderHeldUntil($o->id)) {
                continue;   // its hold ran out: the abandoned-order emails take over
            }
            $n += OrderMail::reminder($o) ? 1 : 0;
        }

        return $n;
    }

    /** Once per paid order, N days after it shipped / was delivered / was ready to collect. Only orders of the last 30 days. */
    public static function reviewRequests(): int
    {
        if (! (Settings::get('reviews')['ask'] ?? true) || ! (Settings::get('reviews')['enabled'] ?? true) || ! EmailTemplates::enabled('review_request')) {
            return 0;
        }
        $days = max(0, (int) (Settings::get('emails')['reviewDays'] ?? 3));
        $due = now()->subDays($days);
        $orders = DB::table('orders')->whereNotNull('paid_at')->whereNull('review_asked_at')->where('email', '<>', '')
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->where(fn ($q) => $q->where('delivered_at', '<=', $due)->orWhere('shipped_at', '<=', $due)->orWhere('ready_at', '<=', $due))
            ->where('paid_at', '>', now()->subDays(30 + $days))->orderBy('id')->limit(50)->get();
        $n = 0;
        foreach ($orders as $o) {
            DB::table('orders')->where('id', $o->id)->update(['review_asked_at' => now()]);
            $n += OrderMail::reviewRequest($o) ? 1 : 0;
        }

        return $n;
    }

    /** Morning digest (from 9 am shop time), once a day. */
    public static function reviewDigest(): int
    {
        return Fmt::local(now())->hour >= 9 ? Reviews::digest() : 0;
    }
}
