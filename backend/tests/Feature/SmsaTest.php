<?php

namespace Tests\Feature;

use App\Support\Secret;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Delivery methods (by air / by road per destination) and SMSA as in Sniper Safety (faked SMSA and Tap APIs). */
class SmsaTest extends TestCase
{
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $cat = DB::table('categories')->insertGetId(['slug' => 'sweet', 'name' => 'Sweet', 'name_ar' => 'حلو']);
        $this->productId = \App\Support\Catalog::save([
            'name' => 'Mango Fruits', 'name_ar' => 'مانجو', 'price' => 3, 'images' => ['/uploads/p/a.jpg'], 'category_id' => $cat,
            'status' => 'active', 'manage_stock' => true, 'stock_qty' => 20, 'weight_grams' => 500,
        ]);
        $p = Settings::get('payments');
        $p['tap'] = ['enabled' => true, 'mode' => 'test', 'testSecret' => Secret::seal('sk_test_ABCDEFGH1234'), 'testPublishable' => '',
            'liveSecret' => '', 'livePublishable' => '', 'merchantId' => '', 'chargeCurrencies' => ['BHD', 'SAR']];
        Settings::set('payments', $p);
    }

    /** SMSA's standard 4 × 6 in label (288 × 432 pt). */
    private static function labelPdf(): string
    {
        $pdf = new \setasign\Fpdi\Fpdi('P', 'pt', [288, 432]);
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->Text(20, 40, 'SMSA 231200000001');

        return $pdf->Output('S');
    }

    private function fakeAll(array $smsaNew = null, int $newStatus = 200, string $amount = '7.5'): void
    {
        Http::fake([
            'api.tap.company/v2/charges' => Http::response(['id' => 'chg_TS01', 'status' => 'INITIATED', 'transaction' => ['url' => 'https://checkout.tap.company/?x']]),
            'api.tap.company/v2/charges/*' => fn ($req) => Http::response(['id' => 'chg_TS01', 'status' => 'CAPTURED',
                'amount' => (float) DB::table('orders')->where('tap_charge_id', 'chg_TS01')->orderByDesc('id')->value('charge_amount'),
                'currency' => (string) DB::table('orders')->where('tap_charge_id', 'chg_TS01')->orderByDesc('id')->value('charge_currency'),
                'reference' => ['payment' => '1'], 'source' => ['payment_method' => 'VISA'], 'response' => ['message' => 'ok']]),
            'ecomapis-sandbox.azurewebsites.net/api/shipment/b2c/new' => Http::response($smsaNew ?? ['sawb' => '231200000001', 'waybills' => [['awb' => '231200000001', 'awbFile' => base64_encode(self::labelPdf())]]], $newStatus),
            'ecomapis-sandbox.azurewebsites.net/api/shipment/b2c/query/*' => Http::response(['VatPaid' => false, 'DutyPaid' => false]),
            'ecomapis-sandbox.azurewebsites.net/api/track/single/0000000000' => Http::response([], 404),
            'ecomapis-sandbox.azurewebsites.net/api/track/single/*' => Http::response(['AWB' => '231200000001', 'isDelivered' => true, 'Scans' => [
                ['ScanDateTime' => '2026-11-01T10:00:00', 'ScanDescription' => 'Picked up', 'City' => 'Manama'],
                ['ScanDateTime' => '2026-11-02T09:00:00', 'ScanDescription' => 'Delivered', 'City' => 'Riffa'],
            ]]),
        ]);
    }

    private function order(string $country = 'BH', array $over = []): int
    {
        $phone = ['BH' => '+973 3333 4444', 'SA' => '+966 512345678'][$country];
        $token = $this->postJson('/api/checkout', $over + [
            'items' => [['id' => $this->productId, 'qty' => 2]], 'country' => $country, 'method' => 'tap', 'lang' => 'en',
            'first_name' => 'Sara', 'last_name' => 'Ali', 'email' => 'sara@example.com', 'phone' => $phone,
            'state' => 'Northern', 'city' => $country === 'BH' ? 'Riffa' : 'Riyadh', 'address1' => 'House 12, Road 34, Block 905', 'address2' => '',
        ])->assertOk()->json('order.token');
        $this->getJson('/api/orders/'.$token)->assertJsonPath('order.status', 'paid');

        return (int) DB::table('orders')->where('token', $token)->value('id');
    }

    private function switchOn(array $over = []): void
    {
        $this->putJson('/api/admin/smsa', $over + ['enabled' => true, 'mode' => 'test', 'testKey' => 'smsa-sandbox-key', 'methods' => ['bh-home', 'gcc-air', 'gcc-road'],
            'services' => ['gcc-air' => 'EIDL', 'gcc-road' => 'EIED'], 'dutiesBy' => 'consignee', 'autoStatus' => true, 'contents' => 'Sweets',
            'shipper' => ['name' => 'Tartebz Sweets', 'phone' => '+973 3753 3757', 'country' => 'BH', 'city' => 'Riffa', 'address1' => 'Al-Ajeera Street, West Riffa']])
            ->assertOk()->assertJsonPath('active', true)->assertJsonPath('keys.test.set', true)->assertJsonPath('missing', []);
    }

    public function test_waybill_by_hand_label_with_printed_value_and_tracking(): void
    {
        $this->fakeAll();
        $id = $this->order('BH');
        $this->actingAsAdmin($this->makeAdmin('owner'));
        $this->postJson("/api/admin/orders/$id/ship")->assertStatus(409)->assertJsonPath('error.code', 'smsa_not_set');
        $this->switchOn();
        $this->postJson('/api/admin/smsa/test', ['mode' => 'test'])->assertOk()->assertJsonPath('connected', true);

        $o = $this->postJson("/api/admin/orders/$id/ship")->assertOk()->json('order');
        $this->assertSame('231200000001', $o['awb']);
        $this->assertSame('created', $o['shipment']['status']);
        $this->assertSame('EDDL', $o['shipment']['service']);          // inside Bahrain
        $this->assertNull($o['shipment']['dutiesBy']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/shipment/b2c/new') && $r->hasHeader('apikey', 'smsa-sandbox-key')
            && $r['ConsigneeAddress']['ContactName'] === 'Sara Ali' && $r['ConsigneeAddress']['ContactPhoneNumber'] === '97333334444'
            && $r['ServiceCode'] === 'EDDL' && $r['DeclaredValue'] == 6.0 && $r['ContentDescription'] === 'Sweets' && $r['VatPaid'] === false);
        $this->assertSame(1, DB::table('mail_outbox')->where('subject', 'like', 'Tracking number%')->count());
        $this->postJson("/api/admin/orders/$id/ship")->assertStatus(409)->assertJsonPath('error.code', 'has_awb');

        $this->get("/api/admin/orders/$id/label")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $half = $this->get("/api/admin/orders/$id/label?percentage=50")->assertOk();
        $this->assertStringStartsWith('%PDF', $half->getContent());
        $this->getJson("/api/admin/orders/$id/label?percentage=150")->assertStatus(422);
        $this->get("/api/admin/orders/labels?ids=$id")->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->postJson("/api/admin/orders/$id/track")->assertOk()->assertJsonPath('order.status', 'delivered')
            ->assertJsonPath('order.shipment.status', 'delivered')->assertJsonPath('order.trackingStatus', 'Delivered – Riffa');
        $this->assertSame(2, DB::table('order_events')->where('order_id', $id)->where('type', 'tracking')->count());
    }

    public function test_abroad_order_gets_its_waybill_by_air_by_itself_and_prints_with_the_invoice(): void
    {
        $this->fakeAll();
        $this->actingAsAdmin($this->makeAdmin('owner'));
        $this->switchOn();
        Settings::set('smsa', ['since' => now()->subMinute()->toDateTimeString()] + Settings::get('smsa'));
        $id = $this->order('SA', ['shipping_method' => 'gcc-air']);
        $this->assertSame('gcc-air', DB::table('orders')->where('id', $id)->value('shipping_method'));
        $bh = $this->order('BH');

        $this->artisan('store:smsa')->assertSuccessful();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/shipment/b2c/new') && $r['ConsigneeAddress']['Country'] === 'SA'
            && $r['ServiceCode'] === 'EIDL' && $r['VatPaid'] === false && $r['DutyPaid'] === false);
        $sh = DB::table('shipments')->where('order_id', $id)->first();
        $this->assertSame('consignee', $sh->duties_by);
        $this->assertSame('EIDL', $sh->service);

        $page = $this->get("/api/admin/orders/$id/print")->assertOk()->getContent();
        $this->assertStringContainsString('data-src="/api/admin/orders/'.$id.'/label"', $page);
        $this->assertStringContainsString('By air (SMSA)', $page);
        $home = $this->get("/api/admin/orders/$bh/print")->assertOk()->getContent();
        $this->assertStringNotContainsString('data-src=', $home);   // Bahrain: the invoice only
        $both = $this->get("/api/admin/orders/print?ids=$id,$bh")->assertOk()->getContent();
        $this->assertSame(2, substr_count($both, 'class="doc"'));
    }

    public function test_a_refused_waybill_is_tried_again_three_times_at_most(): void
    {
        $this->fakeAll(['errors' => ['ConsigneeAddress.City' => ['Unknown city']]], 400);
        $this->actingAsAdmin($this->makeAdmin('owner'));
        $this->switchOn();
        Settings::set('smsa', ['since' => now()->subMinute()->toDateTimeString()] + Settings::get('smsa'));
        $id = $this->order('BH');
        $this->artisan('store:smsa')->assertSuccessful();
        $sh = DB::table('shipments')->where('order_id', $id)->first();
        $this->assertSame('failed', $sh->status);
        $this->assertStringContainsString('Unknown city', $sh->error);
        $this->artisan('store:smsa');                     // too soon: not tried again
        $this->assertSame(1, (int) DB::table('shipments')->where('order_id', $id)->value('attempts'));
        foreach ([2, 3, 3] as $want) {
            DB::table('shipments')->where('order_id', $id)->update(['updated_at' => now()->subMinutes(11)]);
            $this->artisan('store:smsa');
            $this->assertSame($want, (int) DB::table('shipments')->where('order_id', $id)->value('attempts'));
        }
        $this->getJson('/api/admin/smsa')->assertOk()->assertJsonPath('counts.failed', 1)->assertJsonPath('failed.0.id', $id);
    }

    public function test_rates_per_destination_with_air_and_road(): void
    {
        $this->actingAsAdmin($this->makeAdmin('owner'));
        $rates = $this->getJson('/api/admin/shipping')->assertOk()->json('destinations');
        $this->assertSame(['bh', 'gcc'], array_column($rates, 'id'));
        $rates[1]['methods'][1]['active'] = true;          // switch "by road" on
        $rates[] = ['name' => ['en' => 'World', 'ar' => 'العالم'], 'countries' => ['*'], 'methods' => [['name' => ['en' => 'By air'], 'pricing' => 'formula', 'base' => 8, 'firstKg' => 0.5, 'stepKg' => 0.5, 'perStep' => 2, 'etaMin' => 5, 'etaMax' => 9]]];
        $bad = $rates;
        $bad[2]['countries'] = ['SA'];
        $this->putJson('/api/admin/shipping', ['destinations' => $bad])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['destinations.2.countries']]]);
        $this->putJson('/api/admin/shipping', ['destinations' => $rates])->assertOk();

        $q = $this->postJson('/api/checkout/quote', ['items' => [['id' => $this->productId, 'qty' => 2]], 'country' => 'SA'])->json('quote');
        $this->assertSame(['gcc-air', 'gcc-road'], array_column($q['methods'], 'id'));
        $this->assertEquals(4.2, $q['methods'][0]['price']);   // 1 kg by the air table
        $this->assertEquals(3.0, $q['methods'][1]['price']);   // road: 3 for the first kg
        $this->assertSame('gcc-air', $q['method']['id']);
        $road = $this->postJson('/api/checkout/quote', ['items' => [['id' => $this->productId, 'qty' => 4]], 'country' => 'SA', 'shipping_method' => 'gcc-road'])->json('quote');
        $this->assertEquals(4.0, $road['shipping']);           // 2 kg: 3 + 2 × 0.5
        $us = $this->postJson('/api/checkout/quote', ['items' => [['id' => $this->productId, 'qty' => 2]], 'country' => 'US'])->assertOk()->json('quote');
        $this->assertEquals(10.0, $us['shipping']);            // everywhere else: 8 + 1 step
    }
}
