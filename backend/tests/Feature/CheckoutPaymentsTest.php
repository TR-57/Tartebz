<?php

namespace Tests\Feature;

use App\Support\Secret;
use App\Support\Settings;
use App\Support\Tap;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Orders, Tap card payments (faked Tap API) and SMSA shipments (faked SMSA API). */
class CheckoutPaymentsTest extends TestCase
{
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $cat = DB::table('categories')->insertGetId(['slug' => 'sweet', 'name' => 'Sweet', 'name_ar' => 'حلو']);
        $this->productId = \App\Support\Catalog::save([
            'name' => 'Mango Fruits', 'name_ar' => 'مانجو', 'price' => 3, 'images' => ['/uploads/p/a.jpg'], 'category_id' => $cat,
            'status' => 'active', 'manage_stock' => true, 'stock_qty' => 5, 'weight_grams' => 500,
        ]);
        $p = Settings::get('payments');
        $p['tap'] = ['enabled' => true, 'mode' => 'test', 'testSecret' => Secret::seal('sk_test_ABCDEFGH1234'), 'testPublishable' => '',
            'liveSecret' => '', 'livePublishable' => '', 'merchantId' => '', 'chargeCurrencies' => ['BHD', 'SAR']];
        Settings::set('payments', $p);
    }

    private function customer(array $over = []): array
    {
        return $over + [
            'items' => [['id' => $this->productId, 'qty' => 2]], 'country' => 'BH', 'method' => 'tap', 'lang' => 'en',
            'first_name' => 'Sara', 'last_name' => 'Ali', 'email' => 'sara@example.com', 'phone' => '+973 3333 4444',
            'state' => 'Northern', 'city' => 'Riffa', 'address1' => 'House 12, Road 34, Block 905', 'address2' => '',
        ];
    }

    private function fakeTap(string $status = 'CAPTURED', ?float $amount = null, string $currency = 'BHD'): void
    {
        Http::fake([
            'api.tap.company/v2/charges' => Http::response(['id' => 'chg_TS01', 'status' => 'INITIATED', 'transaction' => ['url' => 'https://checkout.tap.company/?mode=page&token=x']]),
            'api.tap.company/v2/charges/chg_TS01' => Http::response(['id' => 'chg_TS01', 'status' => $status, 'amount' => $amount ?? 6.5, 'currency' => $currency,
                'reference' => ['payment' => '123'], 'source' => ['payment_method' => 'VISA'], 'response' => ['message' => 'x']]),
        ]);
    }

    public function test_server_prices_the_cart_and_ignores_the_browser(): void
    {
        $q = $this->postJson('/api/checkout/quote', ['items' => [['id' => $this->productId, 'qty' => 2, 'price' => 0.001]], 'country' => 'BH'])->assertOk()->json('quote');
        $this->assertEquals(6.0, $q['subtotal']);
        $this->assertEquals(1.5, $q['shipping']);           // Bahrain flat
        $this->assertEquals(7.5, $q['total']);
        $sa = $this->postJson('/api/checkout/quote', ['items' => [['id' => $this->productId, 'qty' => 2]], 'country' => 'SA'])->json('quote');
        $this->assertEquals(4.2, $sa['shipping']);          // 1 kg by the weight table
        $this->assertSame('SAR', $sa['chargeCurrency']);
        $this->assertEquals(102.0, $sa['chargeAmount']);     // (6 + 4.2) BHD × 10
        $this->postJson('/api/checkout/quote', ['items' => [], 'country' => 'US'])->assertStatus(422);
        $this->postJson('/api/checkout/quote', ['items' => [['id' => $this->productId, 'qty' => 9]], 'country' => 'BH'])
            ->assertJsonPath('quote.issues.0.code', 'only')->assertJsonPath('quote.lines.0.qty', 5);
    }

    public function test_card_payment_counts_only_after_tap_confirms_it(): void
    {
        $this->fakeTap('CAPTURED', 7.5);
        $r = $this->postJson('/api/checkout', $this->customer(['expected_total' => 7.5]))->assertOk();
        $this->assertStringStartsWith('https://checkout.tap.company/', $r->json('redirect'));
        $token = $r->json('order.token');
        $o = DB::table('orders')->where('token', $token)->first();
        $this->assertSame('pending_payment', $o->status);
        $this->assertSame('20001', $o->number);
        $this->assertSame('chg_TS01', $o->tap_charge_id);
        $this->assertSame(5, (int) DB::table('products')->find($this->productId)->stock_qty, 'no stock taken before payment');
        Http::assertSent(fn ($req) => $req->url() === 'https://api.tap.company/v2/charges' && $req['amount'] == 7.5 && $req['currency'] === 'BHD'
            && $req->hasHeader('Authorization', 'Bearer sk_test_ABCDEFGH1234') && str_contains($req['redirect']['url'], $token));

        // A forged webhook body claiming CAPTURED changes nothing by itself: Tap is asked, and Tap's answer counts.
        $this->postJson('/api/payments/tap/webhook', ['id' => 'chg_TS01', 'status' => 'CAPTURED'], ['Origin' => ''])->assertOk();
        $o = DB::table('orders')->find($o->id);
        $this->assertSame('paid', $o->status);
        $this->assertSame(3, (int) DB::table('products')->find($this->productId)->stock_qty);
        $this->assertDatabaseHas('mail_outbox', ['to_email' => 'sara@example.com']);

        // Twice is still once.
        \Illuminate\Support\Facades\Cache::flush();
        $this->postJson('/api/payments/tap/webhook', ['id' => 'chg_TS01'])->assertOk();
        $this->getJson('/api/orders/'.$token)->assertOk()->assertJsonPath('order.status', 'paid')->assertJsonPath('order.testMode', true);
        $this->assertSame(3, (int) DB::table('products')->find($this->productId)->stock_qty);
        $this->assertSame(1, DB::table('order_events')->where('order_id', $o->id)->where('type', 'paid')->count());
    }

    public function test_wrong_amount_or_failed_payment_is_not_paid(): void
    {
        $this->fakeTap('CAPTURED', 0.1); // Tap says captured, but for the wrong amount
        $token = $this->postJson('/api/checkout', $this->customer())->json('order.token');
        $this->getJson('/api/orders/'.$token)->assertJsonPath('order.paymentStatus', 'unpaid');
        $this->assertDatabaseHas('order_events', ['type' => 'payment_mismatch']);

        Http::swap(new \Illuminate\Http\Client\Factory);
        $this->fakeTap('DECLINED');
        $token2 = $this->postJson('/api/checkout', $this->customer())->json('order.token');
        $this->getJson('/api/orders/'.$token2)->assertJsonPath('order.status', 'payment_failed')->assertJsonPath('order.canRetry', true);
        $this->postJson('/api/orders/'.$token2.'/pay')->assertOk()->assertJsonStructure(['redirect']);
    }

    public function test_cart_change_is_shown_before_paying_and_whatsapp_orders_wait_for_staff(): void
    {
        $this->fakeTap();
        $this->postJson('/api/checkout', $this->customer(['expected_total' => 5]))->assertStatus(409)->assertJsonPath('error.code', 'changed');
        $this->postJson('/api/checkout', $this->customer(['email' => 'x', 'phone' => '1']))->assertStatus(422)
            ->assertJsonPath('error.fields', fn ($f) => isset($f['email'], $f['phone']));

        $r = $this->postJson('/api/checkout', $this->customer(['method' => 'whatsapp']))->assertOk();
        $this->assertStringStartsWith('https://wa.me/', $r->json('whatsapp'));
        $id = DB::table('orders')->where('token', $r->json('order.token'))->value('id');
        $this->assertSame('awaiting_whatsapp', DB::table('orders')->find($id)->status);

        // Staff cannot mark paid; a manager can, once.
        $this->actingAsAdmin($this->makeAdmin('staff'));
        $this->postJson("/api/admin/orders/$id/paid", ['how' => 'BenefitPay'])->assertStatus(403);
        $this->actingAsAdmin($this->makeAdmin('manager'));
        $this->postJson("/api/admin/orders/$id/paid", ['how' => 'BenefitPay transfer'])->assertOk()->assertJsonPath('order.status', 'paid');
        $this->postJson("/api/admin/orders/$id/paid", ['how' => 'again'])->assertStatus(409);
        $this->assertSame(3, (int) DB::table('products')->find($this->productId)->stock_qty);
        // Cancelling gives the stock back.
        $this->postJson("/api/admin/orders/$id/cancel", ['reason' => 'customer changed mind'])->assertOk()->assertJsonPath('order.status', 'cancelled');
        $this->assertSame(5, (int) DB::table('products')->find($this->productId)->stock_qty);
    }

    public function test_tap_keys_are_checked_sealed_and_never_shown(): void
    {
        $this->actingAsAdmin($this->makeAdmin('manager'));
        $this->getJson('/api/admin/payments')->assertStatus(403);
        $this->actingAsAdmin($this->makeAdmin('owner'));
        $this->putJson('/api/admin/payments', ['mode' => 'live', 'liveSecret' => 'sk_test_oops', 'enabled' => true])
            ->assertStatus(422)->assertJsonPath('error.fields.liveSecret', 'This does not look like a live secret key – it should start with sk_live_.');
        $r = $this->putJson('/api/admin/payments', ['mode' => 'live', 'liveSecret' => 'sk_live_REAL99998888', 'enabled' => true])->assertOk();
        $this->assertStringNotContainsString('sk_live_REAL99998888', $r->getContent());
        $r->assertJsonPath('tap.liveSecret.set', true)->assertJsonPath('tap.liveSecret.last4', '8888')->assertJsonPath('tap.mode', 'live');
        $this->assertStringNotContainsString('REAL9999', (string) DB::table('settings')->where('k', 'payments')->value('v'));
        $this->assertStringNotContainsString('REAL9999', (string) DB::table('audit_log')->pluck('details')->implode(' '));

        $keyOk = true;
        Http::fake(function () use (&$keyOk) {
            return $keyOk
                ? Http::response(['errors' => [['code' => '1', 'description' => 'Not found']]], 404)   // right key, unknown charge
                : Http::response(['errors' => [['description' => 'Invalid key']]], 401);
        });
        $this->postJson('/api/admin/payments/test', ['mode' => 'live'])->assertOk()->assertJsonPath('connected', true);
        $keyOk = false;
        $this->postJson('/api/admin/payments/test', ['mode' => 'live'])->assertOk()->assertJsonPath('connected', false);
    }

    public function test_smsa_shipment_label_and_tracking(): void
    {
        $this->fakeTap('CAPTURED', 7.5);
        $token = $this->postJson('/api/checkout', $this->customer())->json('order.token');
        $this->getJson('/api/orders/'.$token)->assertJsonPath('order.status', 'paid');
        $id = DB::table('orders')->where('token', $token)->value('id');

        $this->actingAsAdmin($this->makeAdmin('owner'));
        $this->postJson("/api/admin/orders/$id/ship")->assertStatus(409)->assertJsonPath('error.code', 'smsa_not_set');
        $this->putJson('/api/admin/shipping', ['enabled' => true, 'mode' => 'test', 'testKey' => 'smsa-sandbox-key',
            'shipper' => ['name' => 'Tartebz Sweets', 'phone' => '+973 3753 3757', 'country' => 'BH', 'city' => 'Riffa', 'address1' => 'Al-Ajeera Street, West Riffa']])
            ->assertOk()->assertJsonPath('smsa.testKey.set', true)->assertJsonPath('smsa.problems', []);

        Http::fake([
            'ecomapis-sandbox.azurewebsites.net/api/shipment/b2c/new' => Http::response(['sawb' => 'S1', 'waybills' => [['awb' => '231200000001', 'awbFile' => base64_encode('%PDF-1.4 label')]]]),
            'ecomapis-sandbox.azurewebsites.net/api/track/single/*' => Http::response(['AWB' => '231200000001', 'isDelivered' => true,
                'Scans' => [['ScanType' => 'DL', 'ScanDescription' => 'Delivered', 'City' => 'Riffa'], ['ScanType' => 'PU', 'ScanDescription' => 'Picked up']]]),
            'ecomapis-sandbox.azurewebsites.net/api/lookUp/ServiceTypes' => Http::response([['serviceCode' => 'EDDL', 'serviceName' => 'Express delivery']]),
        ]);
        $this->postJson('/api/admin/shipping/test', ['mode' => 'test'])->assertOk()->assertJsonPath('connected', true)->assertJsonPath('services.0.code', 'EDDL');
        $this->postJson("/api/admin/orders/$id/ship")->assertOk()->assertJsonPath('order.awb', '231200000001')->assertJsonPath('order.hasLabel', true);
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/api/shipment/b2c/new') && $req->hasHeader('apikey', 'smsa-sandbox-key')
            && $req['ConsigneeAddress']['ContactName'] === 'Sara Ali' && $req['ConsigneeAddress']['ContactPhoneNumber'] === '97333334444'
            && $req['Weight'] == 1.0 && $req['DeclaredValue'] == 6.0 && $req['WaybillType'] === 'PDF');
        $this->get("/api/admin/orders/$id/label")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->postJson("/api/admin/orders/$id/ship")->assertStatus(409);
        $this->postJson("/api/admin/orders/$id/track")->assertOk()->assertJsonPath('order.status', 'delivered')->assertJsonPath('order.trackingStatus', 'Delivered – Riffa');
    }

    public function test_sync_job_catches_payments_whose_notification_was_lost(): void
    {
        $this->fakeTap('CAPTURED', 7.5);
        $token = $this->postJson('/api/checkout', $this->customer())->json('order.token');
        DB::table('orders')->where('token', $token)->update(['updated_at' => now()->subMinutes(10)]);
        $this->artisan('store:sync-payments')->assertSuccessful();
        $this->assertSame('paid', DB::table('orders')->where('token', $token)->value('status'));
    }
}
