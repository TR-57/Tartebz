<?php

namespace App\Console\Commands;

use App\Support\Maintenance;
use Illuminate\Console\Command;

/**
 * php artisan store:maintenance on|off|status|sync – the same switch as Admin › Maintenance, for when the admin cannot be reached.
 * "sync" rewrites (or removes) the page in the web folder from the saved setting; deploy.sh runs it.
 */
class MaintenanceCommand extends Command
{
    protected $signature = 'store:maintenance {action=status : on, off, status or sync} {--message= : English message} {--message-ar= : Arabic message}';

    protected $description = 'Switch maintenance mode (customers see a "back soon" page; the team keeps working)';

    public function handle(): int
    {
        $a = $this->argument('action');
        $m = Maintenance::info();
        if (in_array($a, ['on', 'off'], true)) {
            Maintenance::set($a === 'on', $this->option('message') ?? $m['message'], $this->option('message-ar') ?? $m['messageAr'], (object) ['name' => 'Server (command line)']);
        } elseif ($a === 'sync') {
            Maintenance::sync();
        } elseif ($a !== 'status') {
            $this->error('Use on, off, status or sync.');

            return self::FAILURE;
        }
        $this->info('Maintenance mode is '.(Maintenance::on() ? 'ON – customers see the "back soon" page.' : 'off – the shop is open.'));

        return self::SUCCESS;
    }
}
