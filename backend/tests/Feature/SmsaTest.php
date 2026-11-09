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
        $phone = ['BH' => '+973 3333 4444', 'SA' => '+966 512345678', 'AE' => '+971 501234567'][$country];
        $token = $this->postJson('/api/checkout', $over + [
            'items' => [['id' => $this->productId, 'qty' => 2]], 'country' => $country, 'method' => 'tap', 'lang' => 'en',
            'first_name' => 'Sara', 'last_name' => 'Ali', 'email' => 'sara@example.com', 'phone' => $phone,
            'state' => 'Northern', 'city' => ['BH' => 'Riffa', 'SA' => 'Riyadh', 'AE' => 'Dubai'][$country], 'address1' => 'House 12, Road 34, Block 905', 'address2' => '',
        ])->assertOk()->json('order.token');
        $this->getJson('/api/orders/'.$token)->assertJsonPath('order.status', 'paid');

        return (int) DB::table('orders')->where('token', $token)->value('id');
    }

    private function switchOn(array $over = []): void
    {
        $this->putJson('/api/admin/smsa', $over + ['enabled' => true, 'mode' => 'test', 'testKey' => 'smsa-sandbox-key', 'methods' => ['sa-std', 'gcc-std'],
            'services' => ['sa-std' => 'EIDL', 'gcc-std' => 'EIED'], 'dutiesBy' => 'consignee', 'autoStatus' => true, 'contents' => 'Sweets',
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
        $this->assertStringContainsString('https://smsaexpress.com/trackingdetails?tracknumbers=231200000001',
            (string) DB::table('mail_outbox')->where('subject', 'like', 'Tracking number%')->value('html'));
        $this->postJson("/api/admin/orders/$id/ship")->assertStatus(409)->assertJsonPath('error.code', 'has_awb');

        $this->get("/api/admin/orders/$id/label")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $half = $this->get("/api/admin/orders/$id/label?percentage=50")->assertOk();
        $this->assertStringStartsWith('%PDF', $half->getContent());
        $this->getJson("/api/admin/orders/$id/label?percentage=150")->assertStatus(422);
        $this->get("/api/admin/orders/labels?ids=$id")->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->postJson("/api/admin/orders/$id/track")->assertOk()->assertJsonPath('order.status', 'delivered')
            ->assertJsonPath('order.shipment.status', 'delivered')->assertJsonPath('order.trackingStatus', 'Delivered – Riffa');
        $this->assertSame(2, DB::table('order_events')->where('order_id', $id)->where('type', 'tracking')->count());
        $this->assertSame(1, DB::table('mail_outbox')->where('subject', 'like', '%has been delivered%')->count());
    }

    public function test_abroad_order_gets_its_waybill_by_air_by_itself_and_prints_with_the_invoice(): void
    {
        $this->fakeAll();
        $this->actingAsAdmin($this->makeAdmin('owner'));
        $this->switchOn();
        Settings::set('smsa', ['since' => now()->subMinute()->toDateTimeString()] + Settings::get('smsa'));
        $id = $this->order('SA', ['shipping_method' => 'sa-std']);
        $this->assertSame('sa-std', DB::table('orders')->where('id', $id)->value('shipping_method'));
        $this->assertEquals(4.3, (float) DB::table('orders')->where('id', $id)->value('shipping'));   // 1 kg: 3.500 + 0.800
        $bh = $this->order('BH');   // Bahrain: our own team, not SMSA

        $this->artisan('store:smsa')->assertSuccessful();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/shipment/b2c/new') && $r['ConsigneeAddress']['Country'] === 'SA'
            && $r['ServiceCode'] === 'EIDL' && $r['VatPaid'] === false && $r['DutyPaid'] === false);
        $sh = DB::table('shipments')->where('order_id', $id)->first();
        $this->assertSame('consignee', $sh->duties_by);
        $this->assertSame('EIDL', $sh->service);
        $this->assertNull(DB::table('shipments')->where('order_id', $bh)->first(), 'Bahrain orders are not sent to SMSA');
        $this->assertStringContainsString('by air (EIDL)', (string) DB::table('order_events')->where('order_id', $id)->where('type', 'shipment')->value('message'));

        $page = $this->get("/api/admin/orders/$id/print")->assertOk()->getContent();
        $this->assertStringContainsString('data-src="/api/admin/orders/'.$id.'/label"', $page);
        $this->assertStringContainsString('GCC courier', $page);
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
        $id = $this->order('SA', ['shipping_method' => 'sa-std']);
        DB::table('orders')->where('id', $id)->update(['prepared_at' => now(), 'prepared_by' => 'Ali']);
        $this->artisan('store:smsa')->assertSuccessful();
        $sh = DB::table('shipments')->where('order_id', $id)->first();
        $this->assertSame('failed', $sh->status);
        $this->assertNull(DB::table('orders')->where('id', $id)->value('prepared_at'), 'back on the team’s "to prepare" list');
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

    public function test_an_order_with_an_empty_address_is_not_sent_to_smsa(): void
    {
        $this->fakeAll();
        $this->actingAsAdmin($this->makeAdmin('owner'));
        $this->switchOn();
        $id = $this->order('AE', ['shipping_method' => 'gcc-std']);
        DB::table('orders')->where('id', $id)->update(['address1' => ' ']);
        $this->postJson("/api/admin/orders/$id/ship")->assertStatus(422)->assertJsonPath('error.code', 'fix_address')
            ->assertJsonPath('error.message', fn ($m) => str_contains($m, 'address is empty'));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/api/shipment/b2c/new'));
        $this->assertSame('failed', DB::table('shipments')->where('order_id', $id)->value('status'));
        // fixed: by road (EIED), as chosen for the rest of the GCC
        DB::table('orders')->where('id', $id)->update(['address1' => 'Sheikh Zayed Road, Tower 2']);
        $this->postJson("/api/admin/orders/$id/ship")->assertOk()->assertJsonPath('order.shipment.service', 'EIED');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/shipment/b2c/new') && $r['ConsigneeAddress']['Country'] === 'AE'
            && $r['ConsigneeAddress']['District'] === 'Dubai' && $r['ServiceCode'] === 'EIED' && $r['Weight'] == 1.0);
    }

    public function test_smsa_lists_what_is_missing_and_stays_off(): void
    {
        $this->actingAsAdmin($this->makeAdmin('owner'));
        $this->putJson('/api/admin/smsa', ['enabled' => true, 'methods' => []])->assertStatus(422)->assertJsonPath('error.code', 'smsa_setup');
        $this->getJson('/api/admin/smsa')->assertOk()->assertJsonPath('active', false)
            ->assertJsonPath('missing', fn ($m) => in_array('at least one delivery method sent with SMSA', $m, true) && in_array('the sender phone', $m, true));
    }

    public function test_the_sniper_shopping_rates(): void
    {
        $q = fn (string $cc, int $qty, ?string $m = null) => $this->postJson('/api/checkout/quote', array_filter(['items' => [['id' => $this->productId, 'qty' => $qty]],
            'country' => $cc, 'shipping_method' => $m]))->assertOk()->json('quote');
        // 0.5 kg -> Saudi 3.500, UAE / Kuwait 4.000; 1.5 kg -> 5.100 / 6.000; 2 kg -> 5.900 / 7.000 (each piece is 0.5 kg)
        foreach ([[1, 3.5, 4.0], [3, 5.1, 6.0], [4, 5.9, 7.0]] as [$qty, $sa, $gcc]) {
            $this->assertEquals($sa, $q('SA', $qty)['shipping']);
            $this->assertEquals($gcc, $q('AE', $qty)['shipping']);
            $this->assertEquals($gcc, $q('KW', $qty)['shipping']);
        }
        $sa = $q('SA', 1)['methods'][0];
        $this->assertSame(['sa-std', 'GCC courier', '3–5 working days', '3–5 أيام عمل'], [$sa['id'], $sa['name']['en'], $sa['eta']['en'], $sa['eta']['ar']]);
        // Bahrain: standard 2.000 up to 5 kg (+0.300 a kg, max 6.000, free over 25.000), same-day 4.000, collect free
        $bh = $q('BH', 2);
        $this->assertSame(['bh-std', 'bh-exp', 'bh-pick'], array_column($bh['methods'], 'id'));
        $this->assertEquals([2.0, 4.0, 0.0], array_column($bh['methods'], 'price'));
        $this->assertSame(['1–2 working days', 'Same day'], [$bh['methods'][0]['eta']['en'], $bh['methods'][1]['eta']['en']]);
        $this->assertEquals(4.0, $q('BH', 2, 'bh-exp')['shipping']);
        $this->assertEquals(0.0, $q('BH', 9, 'bh-std')['shipping']);    // 27.000 of goods: free over 25.000
        DB::table('products')->where('id', $this->productId)->update(['price' => 0.25, 'stock_qty' => 99]);   // cheap, so free delivery over 25.000 stays out of the way
        $this->assertEquals(2.6, $q('BH', 13, 'bh-std')['shipping']);   // 6.5 kg: 2.000 + 2 × 0.300
        $this->assertEquals(4.1, $q('BH', 24, 'bh-std')['shipping']);   // 12 kg: 2.000 + 7 × 0.300
        $this->assertEquals(6.0, $q('BH', 48, 'bh-std')['shipping']);   // 24 kg: capped at 6.000
        $this->assertNotContains('bh-exp', array_column($q('BH', 42)['methods'], 'id'));   // 21 kg: same-day takes 20 kg at most
        $this->assertSame([], $q('SA', 62)['methods']);                  // 31 kg: over the courier's 30 kg
        // a product with no weight counts as 0.5 kg
        DB::table('products')->where('id', $this->productId)->update(['weight_grams' => 0, 'own_weight' => 0]);
        $this->assertSame(500, $q('SA', 1)['weightGrams']);

        // The editor: destinations, general settings, a quoted method
        $this->actingAsAdmin($this->makeAdmin('owner'));
        $r = $this->getJson('/api/admin/shipping')->assertOk()->json();
        $this->assertSame(['bh', 'sa', 'gcc'], array_column($r['destinations'], 'id'));
        $this->assertSame(['fallbackKg' => 0.5, 'cutoffHour' => 14, 'weekend' => [5, 6]], $r['general']);
        $d = $r['destinations'];
        $d[2]['methods'][] = ['name' => ['en' => 'Road freight', 'ar' => 'شحن بري'], 'pricing' => 'quoted', 'etaMin' => 5, 'etaMax' => 10];
        $bad = $d;
        $bad[2]['countries'] = ['SA'];
        $this->putJson('/api/admin/shipping', ['destinations' => $bad])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['destinations.2.countries']]]);
        $this->putJson('/api/admin/shipping', ['destinations' => $d, 'general' => ['fallbackKg' => 1, 'cutoffHour' => 13, 'weekend' => [5, 6]]])->assertOk()
            ->assertJsonPath('general.cutoffHour', 13);
        $this->signOut();
        $ae = $q('AE', 1, 'gcc-road-freight');
        $this->assertSame('gcc-road-freight', $ae['method']['id']);
        $this->assertTrue($ae['method']['quoted']);
        $this->assertEquals(0.0, $ae['shipping']);
        $this->assertSame(1000, $ae['weightGrams']);   // the default weight is now 1 kg
    }
}
