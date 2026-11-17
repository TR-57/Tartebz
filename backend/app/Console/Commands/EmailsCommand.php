<?php

namespace App\Console\Commands;

use App\Support\Campaigns;
use App\Support\Carts;
use App\Support\EmailJobs;
use App\Support\Outbox;
use Illuminate\Console\Command;

/**
 * Every minute (Hostinger cron runs `php artisan schedule:run`): retries failed email with back-off, sends a batch of
 * campaign email, payment reminders, review requests, the reviews digest – and, with --carts, the abandoned-cart emails.
 */
class EmailsCommand extends Command
{
    protected $signature = 'store:emails {--carts : run the abandoned-cart emails (every 5 minutes)}';

    protected $description = 'Send timed and retried emails (reminders, reviews, campaigns, abandoned carts)';

    public function handle(): int
    {
        if ($this->option('carts')) {
            $this->info('Abandoned-cart emails sent: '.Carts::run());

            return self::SUCCESS;
        }
        $r = Outbox::retry();
        $c = Campaigns::batch();
        $p = EmailJobs::paymentReminders();
        $v = EmailJobs::reviewRequests();
        $d = EmailJobs::reviewDigest();
        $this->info("Retried {$r}, campaign {$c}, payment reminders {$p}, review requests {$v}, digests {$d}.");

        return self::SUCCESS;
    }
}
