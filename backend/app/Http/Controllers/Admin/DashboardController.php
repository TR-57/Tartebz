<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dashboard figures. Sales, orders and stock tiles fill in as those areas are built;
 * until then they read 0 and the setup checklist says what is still missing.
 */
class DashboardController extends Controller
{
    public function index()
    {
        $has = fn (string $t) => Schema::hasTable($t);
        $counts = [
            'unhandled' => 0, 'awaitingPayment' => 0, 'toShip' => 0, 'lowStock' => 0, 'customers' => 0,
            'enquiries' => $has('enquiries') ? DB::table('enquiries')->where('status', 'new')->count() : 0,
            'mailFailed' => DB::table('mail_outbox')->where('status', 'failed')->count(),
        ];
        $setup = [
            ['ok' => Outbox::configured(), 'label' => 'Email sending (SMTP)', 'hint' => 'Settings > Email – until then emails are only written to the log.'],
            ['ok' => DB::table('admins')->where('role', 'owner')->where('active', 1)->exists(), 'label' => 'At least one active owner account', 'hint' => 'Staff > Add a person'],
        ];

        return $this->ok([
            'today' => ['revenue' => 0, 'orders' => 0], 'week' => ['revenue' => 0, 'orders' => 0], 'month' => ['revenue' => 0, 'orders' => 0],
            'days' => [], 'counts' => $counts, 'recent' => [],
            'setup' => array_values(array_filter($setup, fn ($s) => ! $s['ok'])),
        ]);
    }
}
