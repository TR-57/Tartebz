<?php

namespace Tests\Feature;

use App\Support\Holds;
use App\Support\Secret;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Stock reservations (cart and order holds), refunds (Tap and recorded), Apple Pay domain file, store:doctor. */
class HoldsRefundsTest extends TestCase
{
    private int $productId;

    private string $cartA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private string $cartB = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    protected function setUp(): void
    {
        parent::setUp();
        $cat = DB::table('categories')->insertGetId(['slug' => 'sweet', 'name' => 'Sweet', 'name_ar' => 'حلو']);
        $this->productId = \App\Support\Catalog::save([
            'name' => 'Mango Fruits', 'name_ar' => 'مانجو', 'price' => 3, 'images' => ['/uploads/p/a.jpg'], 'category_id' => $cat,
            'status' => 'active', 'manage_stock' => true, 'stock_qty' => 1, 'weight_grams' => 500,
        ]);
        $p = Settings::get('payments');
        $p['tap'] = ['enabled' => true, 'mode' => 'test', 'testSecret' => Secret::seal('sk_test_ABCDEFGH1234'), 'testPublishable' => '',
            'liveSecret' => '', 'livePublishable' => '', 'merchantId' => '', 'chargeCurrencies' => ['BHD']];
        Settings::set('payments', $p);
    }

    private function hold(string $cart, int $qty): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/cart/hold', ['cart' => $cart, 'items' => [['id' => $this->productId, 'qty' => $qty]]]);
    }

    private function customer(array $over = []): array
    {
        return $over + [
            'items' => [['id' => $this->productId, 'qty' => 1]], 'country' => 'BH', 'method' => 'tap', 'lang' => 'en',
            'first_name' => 'Sara', 'last_name' => 'Ali', 'email' => 'sara@example.com', 'phone' => '+973 3333 4444',
            'state' => 'Northern', 'city' => 'Riffa', 'address1' => 'House 12, Road 34, Block 905', 'address2' => '',
        ];
    }

    private function fakeTap(array $refund = ['id' => 're_TS01', 'status' => 'PENDING']): void
    {
        Http::fake([
            'api.tap.company/v2/charges' => Http::response(['id' => 'chg_TS01', 'status' => 'INITIATED', 'transaction' => ['url' => 'https://checkout.tap.company/?x']]),
            'api.tap.company/v2/charges/chg_TS01' => Http::response(['id' => 'chg_TS01', 'status' => 'CAPTURED', 'amount' => 4.5, 'currency' => 'BHD']),
            'api.tap.company/v2/refunds' => Http::response($refund),
            'api.tap.company/v2/refunds/re_TS01' => Http::response(['id' => 're_TS01', 'status' => 'REFUNDED']),
        ]);
    }

    public function test_the_last_piece_is_kept_for_the_first_cart_only(): void
    {
        $this->hold($this->cartA, 1)->assertOk()->assertJsonPath('items.0.qty', 1)->assertJsonStructure(['expiresAt', 'minutes']);
        // Another customer cannot hold it, cannot see it, and cannot order it.
        $this->hold($this->cartB, 1)->assertOk()->assertJsonPath('items.0.qty', 0);
        $feed = collect($this->getJson('/api/storefront')->json('products'))->firstWhere('id', $this->productId);
        $this->assertSame(0, $feed['stockLeft']);
        $this->assertSame(1, $feed['reserved']);
        $this->postJson('/api/checkout/quote', ['items' => [['id' => $this->productId, 'qty' => 1]], 'country' => 'BH', 'cart' => $this->cartB])
            ->assertJsonPath('quote.issues.0.code', 'held')->assertJsonPath('quote.lines', []);
        $this->fakeTap();
        $this->postJson('/api/checkout', $this->customer(['cart' => $this->cartB]))->assertStatus(409);

        // The holder can order it; the hold moves to the order.
        $token = $this->postJson('/api/checkout', $this->customer(['cart' => $this->cartA]))->assertOk()->json('order.token');
        $order = DB::table('orders')->where('token', $token)->first();
        $this->assertSame($this->cartA, $order->cart_key);
        $this->assertSame(0, DB::table('stock_holds')->where('cart_key', $this->cartA)->count());
        $this->assertSame(1, (int) DB::table('stock_holds')->where('order_id', $order->id)->sum('qty'));
        // Back from a failed payment, the same cart may keep the piece again: its own order does not compete with it.
        $this->hold($this->cartA, 1)->assertJsonPath('items.0.qty', 1);
        $this->hold($this->cartB, 1)->assertJsonPath('items.0.qty', 0);

        // Paid: stock taken once, holds gone.
        $this->getJson('/api/orders/'.$token)->assertJsonPath('order.paymentStatus', 'paid');
        $this->assertSame(0, (int) DB::table('products')->find($this->productId)->stock_qty);
        $this->assertSame(0, DB::table('stock_holds')->where('order_id', $order->id)->count());
    }

    public function test_holds_run_out_and_the_piece_goes_back_on_sale(): void
    {
        $this->hold($this->cartA, 1)->assertJsonPath('items.0.qty', 1);
        $this->travel(Holds::cartMinutes() + 1)->minutes();
        $this->hold($this->cartB, 1)->assertJsonPath('items.0.qty', 1);
        $this->assertSame(1, Holds::purge());
        // Asking for more than the stock gets what is there; emptying the cart lets go.
        $this->hold($this->cartB, 5)->assertJsonPath('items.0.qty', 1)->assertJsonPath('items.0.wanted', 5);
        $this->postJson('/api/cart/hold', ['cart' => $this->cartB, 'items' => []])->assertOk();
        $this->assertSame(0, DB::table('stock_holds')->count());
        $this->postJson('/api/cart/hold', ['cart' => 'not-a-key', 'items' => []])->assertStatus(422);
    }

    public function test_one_connection_cannot_hold_the_whole_shop(): void
    {
        DB::table('products')->where('id', $this->productId)->update(['stock_qty' => 500]);
        $this->hold($this->cartA, 50)->assertJsonPath('items.0.qty', 50);
        $this->hold($this->cartB, 50)->assertJsonPath('items.0.qty', Holds::MAX_UNITS_PER_IP - 50);
    }

    public function test_unpaid_order_keeps_its_pieces_until_its_time_ends(): void
    {
        Http::fake([
            'api.tap.company/v2/charges' => Http::response(['id' => 'chg_TS01', 'status' => 'INITIATED', 'transaction' => ['url' => 'https://checkout.tap.company/?x']]),
            'api.tap.company/v2/charges/chg_TS01' => Http::response(['id' => 'chg_TS01', 'status' => 'DECLINED']),
        ]);
        $token = $this->postJson('/api/checkout', $this->customer(['cart' => $this->cartA]))->assertOk()->json('order.token');
        $this->getJson('/api/orders/'.$token)->assertJsonPath('order.status', 'payment_failed')->assertJsonPath('order.heldUntil', fn ($v) => $v !== null);
        $this->hold($this->cartB, 1)->assertJsonPath('items.0.qty', 0);
        // The order's time runs out and someone else takes the piece: paying again is refused.
        $this->travel(61)->minutes();
        $this->hold($this->cartB, 1)->assertJsonPath('items.0.qty', 1);
        $this->postJson('/api/orders/'.$token.'/pay')->assertStatus(409)->assertJsonPath('error.code', 'changed');
    }

    public function test_card_refund_partial_then_full_with_restock(): void
    {
        DB::table('products')->where('id', $this->productId)->update(['stock_qty' => 5]);
        $this->fakeTap();
        $token = $this->postJson('/api/checkout', $this->customer())->json('order.token');
        $this->getJson('/api/orders/'.$token)->assertJsonPath('order.paymentStatus', 'paid');
        $id = DB::table('orders')->where('token', $token)->value('id');
        $this->assertSame(4, (int) DB::table('products')->find($this->productId)->stock_qty);

        $this->actingAsAdmin($this->makeAdmin('staff'));
        $this->postJson("/api/admin/orders/$id/refund", ['amount' => 1])->assertStatus(403);
        $this->actingAsAdmin($this->makeAdmin('manager'));
        $this->postJson("/api/admin/orders/$id/refund", ['amount' => 9])->assertStatus(422);
        $this->postJson("/api/admin/orders/$id/refund", ['amount' => 1, 'restock' => true])->assertStatus(422)->assertJsonPath('error.fields.restock', fn ($m) => $m !== null);

        // Partial: pending until Tap confirms (webhook re-fetches the refund).
        $r = $this->postJson("/api/admin/orders/$id/refund", ['amount' => 1.5, 'reason' => 'one bag damaged'])->assertOk();
        $r->assertJsonPath('refund.status', 'pending')->assertJsonPath('order.refundable', 3);
        Http::assertSent(fn ($req) => $req->url() === 'https://api.tap.company/v2/refunds' && $req['charge_id'] === 'chg_TS01' && $req['amount'] == 1.5 && $req['currency'] === 'BHD');
        $this->postJson('/api/payments/tap/webhook', ['id' => 're_TS01', 'status' => 'REFUNDED'])->assertOk();
        $o = DB::table('orders')->find($id);
        $this->assertSame('paid', $o->payment_status);
        $this->assertEquals(1.5, (float) $o->refunded_bhd);
        $this->assertDatabaseHas('mail_outbox', ['to_email' => 'sara@example.com', 'subject' => 'Refund for your order '.$o->number]);

        // Full rest, recorded by hand (e.g. done in the Tap dashboard), with restock.
        $this->postJson("/api/admin/orders/$id/refund", ['amount' => 3, 'method' => 'manual', 'restock' => true])->assertOk()
            ->assertJsonPath('order.status', 'refunded')->assertJsonPath('order.paymentStatus', 'refunded')->assertJsonPath('order.refundable', 0);
        $this->assertSame(5, (int) DB::table('products')->find($this->productId)->stock_qty);
        $this->assertEquals(4.5, (float) DB::table('orders')->find($id)->refunded_bhd);
        $this->postJson("/api/admin/orders/$id/refund", ['amount' => 0.1, 'method' => 'manual'])->assertStatus(409);

        // A late Tap notice for the charge does not make it "paid" again; dashboard revenue is net.
        \Illuminate\Support\Facades\Cache::flush();
        $this->postJson('/api/payments/tap/webhook', ['id' => 'chg_TS01'])->assertOk();
        $this->assertSame('refunded', DB::table('orders')->find($id)->status);
        $this->actingAsAdmin($this->makeAdmin('owner'));
        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('today.revenue', 0);
    }

    public function test_refund_when_tap_cannot_be_reached_stays_pending_until_settled(): void
    {
        $this->fakeTap();
        $token = $this->postJson('/api/checkout', $this->customer())->json('order.token');
        $this->getJson('/api/orders/'.$token);
        $id = DB::table('orders')->where('token', $token)->value('id');
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['api.tap.company/v2/refunds' => fn () => throw new \Illuminate\Http\Client\ConnectionException('down')]);
        $this->actingAsAdmin($this->makeAdmin('manager'));
        $this->postJson("/api/admin/orders/$id/refund", ['amount' => 4.5])->assertStatus(502);
        $rf = DB::table('order_refunds')->where('order_id', $id)->first();
        $this->assertSame('pending', $rf->status);
        // It still blocks a second refund of the same money.
        $this->postJson("/api/admin/orders/$id/refund", ['amount' => 1])->assertStatus(422);
        $this->postJson("/api/admin/orders/$id/refunds/{$rf->id}/check", ['result' => 'failed'])->assertOk()->assertJsonPath('order.refundable', 4.5);
        $this->assertSame('paid', DB::table('orders')->find($id)->payment_status);
    }

    public function test_whatsapp_order_refund_is_recorded_by_hand_only(): void
    {
        $r = $this->postJson('/api/checkout', $this->customer(['method' => 'whatsapp']))->assertOk();
        $id = DB::table('orders')->where('token', $r->json('order.token'))->value('id');
        $this->actingAsAdmin($this->makeAdmin('manager'));
        $this->postJson("/api/admin/orders/$id/refund", ['amount' => 1, 'method' => 'manual'])->assertStatus(409); // not paid yet
        $this->postJson("/api/admin/orders/$id/paid", ['how' => 'Benefit transfer'])->assertOk();
        $this->postJson("/api/admin/orders/$id/refund", ['amount' => 1])->assertStatus(409)->assertJsonPath('error.code', 'not_card');
        $this->postJson("/api/admin/orders/$id/refund", ['amount' => 1, 'method' => 'manual'])->assertOk()->assertJsonPath('refund.status', 'done');
    }

    public function test_apple_pay_domain_file_and_doctor(): void
    {
        $this->get('/.well-known/apple-developer-merchantid-domain-association')->assertNotFound();
        $this->actingAsAdmin($this->makeAdmin('owner'));
        $this->postJson('/api/admin/payments/apple-pay-file', ['text' => '<script>alert(1)</script>'])->assertStatus(422);
        $this->postJson('/api/admin/payments/apple-pay-file', ['text' => '7B227073704964223A2241424344227D'])->assertOk()->assertJsonPath('applePay.fileSet', true);
        $this->get('/.well-known/apple-developer-merchantid-domain-association')->assertOk()->assertSee('7B227073704964223A2241424344227D');

        $this->artisan('store:sync-payments')->assertSuccessful();
        $this->assertTrue(\App\Support\Doctor::cronRecent());
        $checks = collect(\App\Support\Doctor::run())->keyBy(1);
        $this->assertSame('ok', $checks['Database connection'][0]);
        $this->assertSame('ok', $checks['Scheduled tasks ran in the last 15 minutes'][0]);
    }

    public function test_admin_sees_held_units_and_can_set_hold_times(): void
    {
        $this->hold($this->cartA, 1);
        $this->actingAsAdmin($this->makeAdmin('manager'));
        $row = collect($this->getJson('/api/admin/products')->json('products'))->firstWhere('id', $this->productId);
        $this->assertSame(1, $row['held']);
        $this->putJson('/api/admin/website', ['cartHoldMinutes' => 500, 'paymentLogos' => [], 'delivery' => []])->assertStatus(422);
        $this->putJson('/api/admin/website', ['cartHoldMinutes' => 20, 'orderHoldMinutes' => 45, 'whatsappHoldHours' => 12, 'paymentLogos' => [], 'delivery' => []])
            ->assertOk()->assertJsonPath('holds.cartHoldMinutes', 20)->assertJsonPath('holds.orderHoldMinutes', 45);
        $this->assertSame(20, Holds::cartMinutes());
        $this->assertSame(12, Settings::get('orders')['whatsappHoldHours']);
    }
}
