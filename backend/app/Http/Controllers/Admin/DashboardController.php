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
            'unhandled' => DB::table('orders')->where('status', 'paid')->count(),
            'awaitingPayment' => DB::table('orders')->whereIn('status', ['pending_payment', 'awaiting_whatsapp'])->where('created_at', '>', now()->subDays(3))->count(),
            'toShip' => DB::table('orders')->whereIn('status', ['paid', 'processing'])->count(),
            'lowStock' => DB::table('products')->where('status', 'active')->where('type', 'simple')->where('manage_stock', true)->whereBetween('stock_qty', [1, \App\Support\Catalog::LOW_STOCK])->count(),
            'customers' => DB::table('orders')->where('payment_status', 'paid')->distinct()->count('email'),
            'enquiries' => $has('enquiries') ? DB::table('enquiries')->where('status', 'new')->count() : 0,
            'mailFailed' => DB::table('mail_outbox')->where('status', 'failed')->count(),
        ];
        $setup = [
            ['ok' => Outbox::configured(), 'label' => 'Email sending (SMTP)', 'hint' => 'Settings > Email – until then emails are only written to the log.'],
            ['ok' => DB::table('admins')->where('role', 'owner')->where('active', 1)->exists(), 'label' => 'At least one active owner account', 'hint' => 'Staff > Add a person'],
            ['ok' => \App\Support\Tap::enabled(), 'label' => 'Card payments (Tap)', 'hint' => 'Settings > Payments – paste the Tap keys, test, then switch to live.'],
            ['ok' => ! \App\Support\Tap::enabled() || \App\Support\Tap::mode() === 'live', 'label' => 'Tap is in TEST mode – no real money is taken', 'hint' => 'Settings > Payments – switch to live when your test order worked.'],
            ['ok' => \App\Support\Smsa::enabled(), 'label' => 'SMSA shipping', 'hint' => 'Settings > Shipping – paste the SMSA key, fill in the sender address, test.'],
        ];
        $paid = fn ($from) => DB::table('orders')->where('payment_status', 'paid')->where('paid_at', '>=', $from);
        $sum = fn ($from) => ['revenue' => round((float) $paid($from)->sum('total'), 3), 'orders' => $paid($from)->count()];
        $days = DB::table('orders')->where('payment_status', 'paid')->where('paid_at', '>=', now()->subDays(13)->startOfDay())
            ->selectRaw('DATE(paid_at) d, SUM(total) revenue')->groupBy('d')->pluck('revenue', 'd');
        $series = [];
        for ($i = 13; $i >= 0; $i--) {
            $d = now()->subDays($i)->toDateString();
            $series[] = ['d' => $d, 'revenue' => round((float) ($days[$d] ?? 0), 3)];
        }
        $recent = DB::table('orders')->orderByDesc('id')->limit(8)->get()->map(fn ($o) => [
            'id' => $o->id, 'number' => $o->number, 'name' => trim($o->first_name.' '.$o->last_name), 'status' => $o->status,
            'statusLabel' => \App\Support\Orders::LABELS[$o->status] ?? $o->status, 'total' => (float) $o->total,
        ])->values();

        return $this->ok([
            'today' => $sum(now()->startOfDay()), 'week' => $sum(now()->subDays(6)->startOfDay()), 'month' => $sum(now()->subDays(29)->startOfDay()),
            'days' => $series, 'counts' => $counts, 'recent' => $recent,
            'setup' => array_values(array_filter($setup, fn ($s) => ! $s['ok'])),
        ]);
    }
}
