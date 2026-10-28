<?php

namespace Tests\Feature;

use App\Support\Secret;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The orders board (/staff): shop staff role, staff-created orders, payment links, the prepared tick, invoice, new-order alerts. */
class StaffBoardTest extends TestCase
{
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $cat = DB::table('categories')->insertGetId(['slug' => 'sweet', 'name' => 'Sweet', 'name_ar' => 'حلو']);
        $this->productId = \App\Support\Catalog::save([
            'name' => 'Mango Fruits', 'name_ar' => 'مانجو', 'price' => 3, 'images' => ['/uploads/p/a.jpg'], 'category_id' => $cat,
            'status' => 'active', 'manage_stock' => true, 'stock_qty' => 10, 'weight_grams' => 500,
        ]);
        $p = Settings::get('payments');
        $p['tap'] = ['enabled' => true, 'mode' => 'test', 'testSecret' => Secret::seal('sk_test_ABCDEFGH1234'), 'testPublishable' => '',
            'liveSecret' => '', 'livePublishable' => '', 'merchantId' => '', 'chargeCurrencies' => ['BHD']];
        Settings::set('payments', $p);
    }

    private function order(array $over = []): array
    {
        return $over + [
            'items' => [['id' => $this->productId, 'qty' => 2]], 'country' => 'BH', 'lang' => 'ar', 'payment' => 'link',
            'first_name' => 'Sara', 'last_name' => 'Ali', 'phone' => '3333 4444', 'email' => 'sara@example.com',
            'city' => 'Riffa', 'address1' => 'House 12, Road 34, Block 905',
        ];
    }

    public function test_shop_staff_get_the_board_only(): void
    {
        $this->actingAsAdmin($this->makeAdmin('shop_staff'));
        $this->getJson('/api/admin/orders')->assertOk();
        $this->getJson('/api/admin/board/products')->assertOk()->assertJsonPath('products.0.free', 10)->assertJsonPath('tap', true);
        $this->getJson('/api/admin/dashboard')->assertStatus(403);
        $this->getJson('/api/admin/products')->assertStatus(403);
        $this->postJson('/api/admin/orders/1/refund', ['amount' => 1])->assertStatus(403);
        $this->actingAsAdmin($this->makeAdmin('order_assistant'));
        $this->getJson('/api/admin/orders')->assertStatus(403);
        $this->actingAsAdmin($this->makeAdmin('staff'));
        $this->getJson('/api/admin/board/pulse')->assertOk();
        // The owner can give the role.
        $this->actingAsAdmin($this->makeAdmin('owner'));
        $this->postJson('/api/admin/staff', ['name' => 'Shop Person', 'email' => 'shop@example.test', 'role' => 'shop_staff', 'password' => 'Shop-Pass-2026'])->assertOk();
        $this->assertSame('shop_staff', DB::table('admins')->where('email', 'shop@example.test')->value('role'));
    }

    public function test_delivery_is_worked_out_from_weight_and_country(): void
    {
        $this->actingAsAdmin($this->makeAdmin('shop_staff'));
        $this->postJson('/api/admin/board/quote', ['items' => [['id' => $this->productId, 'qty' => 2]], 'country' => 'BH'])
            ->assertOk()->assertJsonPath('quote.shipping', 1.5)->assertJsonPath('quote.total', 7.5)->assertJsonPath('quote.weightGrams', 1000);
        $this->postJson('/api/admin/board/quote', ['items' => [['id' => $this->productId, 'qty' => 2]], 'country' => 'SA'])
            ->assertOk()->assertJsonPath('quote.shipping', 4.2);   // 1 kg on the weight table
        $this->postJson('/api/admin/board/quote', ['items' => [['id' => $this->productId, 'qty' => 5]], 'country' => 'SA'])
            ->assertOk()->assertJsonPath('quote.shipping', 7.3);   // 2.5 kg on the weight table
    }

    public function test_payment_link_order_from_whatsapp(): void
    {
        $staff = $this->makeAdmin('shop_staff', ['name' => 'Fatima']);
        $this->actingAsAdmin($staff);
        $this->postJson('/api/admin/board/orders', $this->order(['phone' => '1', 'payment' => '']))->assertStatus(422)
            ->assertJsonPath('error.fields', fn ($f) => isset($f['phone'], $f['payment']));
        $r = $this->postJson('/api/admin/board/orders', $this->order(['expected_total' => 7.5]))->assertOk();
        $o = DB::table('orders')->find($r->json('order.id'));
        $this->assertSame(['staff', 'Fatima', 'tap', 'pending_payment', '+973 33334444'], [$o->source, $o->created_by_name, $o->payment_method, $o->status, $o->phone]);
        $this->assertNull($o->alert_at, 'the team made it, so no new-order alarm');
        $this->assertStringStartsWith('https://wa.me/97333334444?text=', $r->json('whatsappUrl'));
        $this->assertStringContainsString($o->token, $r->json('payUrl'));
        $this->assertStringContainsString('رابط الدفع', urldecode($r->json('whatsappUrl')));
        // Stock is kept for the customer (as long as a WhatsApp order), not taken yet.
        $this->assertSame(2, (int) DB::table('stock_holds')->where('order_id', $o->id)->sum('qty'));
        $this->assertSame(10, (int) DB::table('products')->find($this->productId)->stock_qty);
        $this->assertGreaterThan(now()->addHours(20), \Illuminate\Support\Carbon::parse(DB::table('stock_holds')->where('order_id', $o->id)->value('expires_at')));

        // The customer opens the link: nothing charged yet, "Pay now" opens Tap.
        $this->signOut();
        $this->getJson('/api/orders/'.$o->token)->assertJsonPath('order.hasCharge', false)->assertJsonPath('order.canRetry', true);
        Http::fake(['api.tap.company/v2/charges' => Http::response(['id' => 'chg_SB01', 'status' => 'INITIATED', 'transaction' => ['url' => 'https://checkout.tap.company/?x']])]);
        $this->postJson('/api/orders/'.$o->token.'/pay')->assertOk()->assertJsonPath('redirect', 'https://checkout.tap.company/?x');

        // Link again by email.
        $this->actingAsAdmin($staff);
        $this->postJson("/api/admin/orders/{$o->id}/payment-link", ['via' => 'email'])->assertOk()->assertJsonPath('sent', 'email');
        $this->assertDatabaseHas('mail_outbox', ['to_email' => 'sara@example.com', 'subject' => 'رابط الدفع لطلبك رقم '.$o->number]);
    }

    public function test_paid_and_pay_later_orders_and_the_prepared_tick(): void
    {
        $this->actingAsAdmin($this->makeAdmin('shop_staff', ['name' => 'Ali']));
        $this->postJson('/api/admin/board/orders', $this->order(['payment' => 'paid']))->assertStatus(422)->assertJsonPath('error.fields.paid_how', fn ($m) => $m !== null);
        $paid = $this->postJson('/api/admin/board/orders', $this->order(['payment' => 'paid', 'paid_how' => 'Benefit transfer', 'email' => '']))->assertOk()->json('order');
        $this->assertSame('paid', $paid['status']);
        $this->assertSame(8, (int) DB::table('products')->find($this->productId)->stock_qty);
        $later = $this->postJson('/api/admin/board/orders', $this->order(['payment' => 'later', 'country' => 'SA', 'phone' => '0555 123 456']))->assertOk()->json('order');
        $this->assertSame('awaiting_whatsapp', $later['status']);
        $this->assertSame('+966 555123456', $later['phone']);

        // Tabs and filters.
        $this->getJson('/api/admin/orders?tab=prepare')->assertJsonPath('total', 1)->assertJsonPath('orders.0.lines.0.qty', 2);
        $this->getJson('/api/admin/orders?tab=all&country=SA')->assertJsonPath('total', 1);
        $this->getJson('/api/admin/orders?tab=all&from='.now()->addDay()->toDateString())->assertJsonPath('total', 0);
        $this->getJson('/api/admin/orders?tab=all&from='.now()->toDateString().'&to='.now()->toDateString())->assertJsonPath('total', 2);

        // Tick from the list: prepared, then untick.
        $this->postJson("/api/admin/orders/{$paid['id']}/prepared", ['done' => true])->assertOk()
            ->assertJsonPath('order.status', 'processing')->assertJsonPath('order.prepared', true)->assertJsonPath('order.preparedBy', 'Ali');
        $this->getJson('/api/admin/orders?tab=prepare')->assertJsonPath('total', 0);
        $this->getJson('/api/admin/orders?tab=prepared')->assertJsonPath('total', 1);
        $this->postJson("/api/admin/orders/{$paid['id']}/prepared", ['done' => false])->assertOk()->assertJsonPath('order.status', 'paid');

        // Money arrives for the WhatsApp one: shop staff may mark it paid.
        $this->postJson("/api/admin/orders/{$later['id']}/paid", ['how' => 'Cash'])->assertOk()->assertJsonPath('order.status', 'paid');

        // Invoice.
        $this->get("/api/admin/orders/{$paid['id']}/invoice")->assertOk()->assertSee('فاتورة')->assertSee($paid['number'])->assertSee('مانجو');
        $this->get("/api/admin/orders/{$paid['id']}/invoice?lang=en&paper=receipt")->assertOk()->assertSee('Invoice')->assertSee('Mango Fruits')->assertSee('class="receipt"', false);
    }

    public function test_new_website_orders_ring_the_board(): void
    {
        $this->actingAsAdmin($this->makeAdmin('shop_staff'));
        $since = $this->getJson('/api/admin/board/pulse')->assertOk()->json('now');
        $this->assertSame([], $this->getJson('/api/admin/board/pulse?since='.urlencode($since))->json('new'));
        $this->signOut();
        // A customer orders on WhatsApp from the website.
        $this->postJson('/api/checkout', $this->order(['method' => 'whatsapp', 'last_name' => 'Ali', 'state' => '']))->assertOk();
        $this->actingAsAdmin($this->makeAdmin('shop_staff'));
        $new = $this->getJson('/api/admin/board/pulse?since='.urlencode($since))->assertOk()->json('new');
        $this->assertCount(1, $new);
        $this->assertSame('whatsapp', $new[0]['method']);
        $this->assertSame(1, $this->getJson('/api/admin/board/pulse')->json('counts.unpaid'));
    }

    public function test_notification_subscriptions(): void
    {
        $a = $this->makeAdmin('shop_staff');
        $this->actingAsAdmin($a);
        $key = $this->getJson('/api/admin/board/push-key')->assertOk()->json('publicKey');
        $this->assertSame(87, strlen($key));
        $this->assertStringNotContainsString(Settings::get('push')['privateKey'], json_encode(Settings::get('push')['publicKey']));
        $this->assertNotSame('', \App\Support\Secret::open(Settings::get('push')['privateKey']));
        $this->postJson('/api/admin/board/push', ['subscription' => ['endpoint' => 'http://evil.test/x']])->assertStatus(422);
        $sub = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abcdefghijklmnop', 'keys' => [
            'p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM', 'auth' => 'tBHItJI5svbpez7KI4CCXg']];
        $this->postJson('/api/admin/board/push', ['subscription' => $sub, 'device' => 'Android'])->assertOk();
        $this->postJson('/api/admin/board/push', ['subscription' => $sub, 'device' => 'Android'])->assertOk();
        $this->assertSame(1, DB::table('push_subscriptions')->where('admin_id', $a->id)->count());
        $this->postJson('/api/admin/board/push/remove', ['endpoint' => $sub['endpoint']])->assertOk();
        $this->assertSame(0, DB::table('push_subscriptions')->count());
    }
}
