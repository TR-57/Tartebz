<?php

namespace App\Console\Commands;

use App\Support\Smsa;
use Illuminate\Console\Command;

/**
 * Every minute (cron), while SMSA is switched on: waybills for paid orders whose delivery method goes through SMSA
 * (a refused one is tried again, 3 times at most, 10 minutes apart), then SMSA's tracking for parcels not checked in the last hour.
 */
class SmsaShipping extends Command
{
    protected $signature = 'store:smsa {--seconds=50 : stop starting new work after this long}';

    protected $description = 'Create SMSA waybills for paid orders and read SMSA tracking';

    public function handle(): int
    {
        $out = Smsa::run(max(5, (int) $this->option('seconds')));
        if ($out['created'] || $out['failed'] || $out['tracked']) {
            $this->info("SMSA: {$out['created']} waybill(s) created, {$out['failed']} refused, {$out['tracked']} tracked.");
        }

        return self::SUCCESS;
    }
}
