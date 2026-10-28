<?php

namespace App\Console\Commands;

use App\Support\Doctor as Checks;
use Illuminate\Console\Command;

/** php artisan store:doctor – checks the server is ready (PHP, folders, database, cron, payments). Exit 1 when something must be fixed. */
class Doctor extends Command
{
    protected $signature = 'store:doctor';

    protected $description = 'Check the server setup (PHP, extensions, folders, database, cron, email, Tap, SMSA)';

    public function handle(): int
    {
        $fail = 0;
        foreach (Checks::run() as [$status, $check, $fix]) {
            $mark = ['ok' => '<info>  OK  </info>', 'warn' => '<comment> WARN </comment>', 'fail' => '<error> FAIL </error>'][$status];
            $this->line($mark.' '.$check.($status !== 'ok' && $fix !== '' ? "\n        → ".$fix : ''));
            $fail += $status === 'fail' ? 1 : 0;
        }
        $this->newLine();
        $fail ? $this->error("{$fail} problem(s) to fix.") : $this->info('Ready.');

        return $fail ? self::FAILURE : self::SUCCESS;
    }
}
