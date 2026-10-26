<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


// Hostinger cron (every minute): php artisan schedule:run
Schedule::command('store:sync-payments')->everyFiveMinutes()->withoutOverlapping();
