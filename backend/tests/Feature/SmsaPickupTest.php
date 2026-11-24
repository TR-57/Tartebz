<?php

namespace Tests\Feature;

use App\Support\Secret;
use App\Support\Settings;
use App\Support\SmsaPickups;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** "Book SMSA pickup" on an order: a day and a window inside 09:00–17:00, sent to SMSA by email or WhatsApp (faked SMSA and Tap). */
class SmsaPickupTest extends TestCase
{
    private int $productId;

    private int $awb = 231200000100;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-23 10:10:00', 'Asia/Bahrain'));   // a Monday morning in Bahrain
        $cat = DB::table('categories')->insertGetId(['slug' => 'sweet', 'name' => 'Sweet', 'name_ar' => 'حلو']);
        $this->productId = \App\Support\Catalog::save([
            'name' => 'Mango Fruits', 'name_ar' => 'مانجو', 'price' => 3, 'images' => ['/uploads/p/a.jpg'], 'category_id' => $cat,
            'status' => 'active', 'manage_stock' => true, 'stock_qty' => 50, 'weight_grams' => 500,
        ]);
        $p = Settings::get('payments');
        $p['tap'] = ['enabled' => true, 'mode' => 'test', 'testSecret' => Secret::seal('sk_test_ABCDEFGH1234'), 'testPublishable' => '',
            'liveSecret' => '', 'livePublishable' => '', 'merchantId' => '', 'chargeCurrencies' => ['BHD']];
        Settings::set('payments', $p);
        $m = Settings::get('mail');
        $m['notifyEmails'] = ['orders@tartebz.test'];
        Settings::set('mail', $m);
        Http::fake([
            'api.tap.company/v2/charges' => Http::response(['id' => 'chg_TS01', 'status' => 'INITIATED', 'transaction' => ['url' => 'https://checkout.tap.company/?x']]),
            'api.tap.company/v2/charges/*' => fn () => Http::response(['id' => 'chg_TS01', 'status' => 'CAPTURED',
                'amount' => (float) DB::table('orders')->where('tap_charge_id', 'chg_TS01')->orderByDesc('id')->value('charge_amount'), 'currency' => 'BHD',
                'reference' => ['payment' => '1'], 'source' => ['payment_method' => 'VISA'], 'response' => ['message' => 'ok']]),
            'ecomapis-sandbox.azurewebsites.net/api/shipment/b2c/new' => function () {
                $n = (string) $this->awb++;

                return Http::response(['sawb' => $n, 'waybills' => [['awb' => $n, 'awbFile' => '']]]);
            },
            'ecomapis-sandbox.azurewebsites.net/api/shipment/b2c/query/*' => Http::response(['VatPaid' => false, 'DutyPaid' => false]),
            'ecomapis-sandbox.azurewebsites.net/api/track/single/*' => Http::response(['isDelivered' => false, 'Scans' => [
                ['ScanDateTime' => '2026-11-23T14:00:00', 'ScanDescription' => 'Picked up', 'City' => 'Riffa']]]),
        ]);
        $this->actingAsAdmin($this->makeAdmin('owner', ['name' => 'Huda']));
        $setup = $this->putJson('/api/admin/smsa', ['enabled' => true, 'mode' => 'test', 'testKey' => 'smsa-sandbox-key', 'methods' => ['gcc-std'], 'services' => ['gcc-std' => 'EIDL'], 'dutiesBy' => 'consignee',
            'autoStatus' => true, 'contents' => 'Sweets', 'retailId' => 'BH-12345',
            'shipper' => ['name' => 'Tartebz Sweets', 'phone' => '+973 3753 3757', 'country' => 'BH', 'city' => 'Riffa', 'district' => 'West Riffa', 'address1' => 'Al-Ajeera Street']]);
        $this->assertSame(200, $setup->status(), $setup->getContent());
    }

    /** A paid order with an SMSA waybill (made on the sandbox; $live = pretend it was made on the live server). */
    private function shipped(bool $live = true, int $boxes = 1): int
    {
        $token = $this->postJson('/api/checkout', [
            'items' => [['id' => $this->productId, 'qty' => 2]], 'country' => 'BH', 'method' => 'tap', 'lang' => 'en',
            'first_name' => 'Sara', 'last_name' => 'Ali', 'email' => 'sara@example.com', 'phone' => '+973 3333 4444',
            'state' => 'Northern', 'city' => 'Riffa', 'address1' => 'House 12, Road 34, Block 905', 'address2' => '',
        ])->assertOk()->json('order.token');
        $this->getJson('/api/orders/'.$token);
        $id = (int) DB::table('orders')->where('token', $token)->value('id');
        $this->postJson("/api/admin/orders/$id/ship", ['parcels' => $boxes, 'weightKg' => 1.5])->assertOk();
        if ($live) {
            DB::table('shipments')->where('order_id', $id)->update(['mode' => 'live']);
        }

        return $id;
    }

    public function test_times_on_offer_follow_the_clock_and_the_9_to_5_window(): void
    {
        $id = $this->shipped();
        $f = $this->getJson("/api/admin/pickups/form")->assertOk()->json();
        $this->assertSame(['09:00', '17:00'], [$f['open'], $f['close']]);
        $this->assertTrue($f['days'][0]['today']);
        $this->assertSame('2026-11-23', $f['days'][0]['date']);
        $this->assertSame('10:30', $f['days'][0]['from'][0], 'today starts at the next half hour');
        $this->assertSame('16:00', end($f['days'][0]['from']));
        $this->assertSame('17:00', end($f['days'][0]['until']));
        $this->assertSame('09:00', $f['days'][1]['from'][0], 'later days start at 09:00');
        $this->assertCount(7, $f['days']);
        // late in the afternoon there is no room left today
        $this->travelTo(Carbon::parse('2026-11-23 16:20:00', 'Asia/Bahrain'));
        $f = $this->getJson("/api/admin/pickups/form")->json();
        $this->assertSame('2026-11-24', $f['days'][0]['date']);
        $this->assertFalse($f['days'][0]['today']);
    }

    public function test_one_booking_for_all_waiting_waybills_with_the_answers_for_smsa_and_the_booking_number(): void
    {
        $id = $this->shipped(true, 2);
        $other = $this->shipped();
        // the button on the orders list: every waybill waiting, both ticked
        $f = $this->getJson('/api/admin/pickups/form')->assertOk();
        $this->assertSame([$id, $other], array_column($f->json('waiting'), 'orderId'));
        $this->assertSame('https://ecom.smsaexpress.com/', $f->json('dashboardUrl'));
        $r = $this->postJson('/api/admin/pickups', ['orderIds' => [$id, $other], 'date' => '2026-11-23', 'from' => '11:00', 'until' => '17:00', 'note' => 'Call on arrival'])->assertOk();
        $r->assertJsonPath('pickup.status', 'requested')->assertJsonPath('pickup.parcels', 3)->assertJsonPath('pickup.smsaRef', '')
            ->assertJsonPath('pickup.heavy', false)->assertJsonPath('pickup.dashboardUrl', 'https://ecom.smsaexpress.com/');
        $p = DB::table('smsa_pickups')->first();
        $this->assertSame(['11:00', '17:00'], [$p->ready_from, $p->ready_until]);
        $this->assertSame(2, DB::table('shipments')->where('pickup_id', $p->id)->count());
        // the answers for SMSA's "Initiate Pickup Request" form, in its order
        $fields = array_column($r->json('pickup.fields'), 'value', 'key');
        $this->assertSame(['contact', 'phone', 'address', 'postal', 'city', 'heavy', 'ready', 'closing', 'notes'], array_keys($fields));
        $this->assertSame('Tartebz Sweets', $fields['contact']);
        $this->assertSame('+973 3753 3757', $fields['phone']);
        $this->assertSame('Al-Ajeera Street, West Riffa', $fields['address']);
        $this->assertSame('Riffa', $fields['city']);
        $this->assertSame('No', $fields['heavy']);
        $this->assertSame('Mon 23 Nov 2026, 11:00', $fields['ready']);
        $this->assertSame('17:00', $fields['closing']);
        foreach (DB::table('shipments')->pluck('awb') as $a) {
            $this->assertStringContainsString($a, $fields['notes']);
        }
        $this->assertStringContainsString('3 parcels', $fields['notes']);
        $this->assertStringContainsString('Call on arrival', $fields['notes']);
        // nothing is emailed or sent anywhere
        $this->assertSame(0, DB::table('mail_outbox')->where('email_key', 'smsa_pickup')->count());
        $this->assertSame(2, DB::table('order_events')->where('type', 'smsa_pickup')->count(), 'both orders show it on their history');
        // SMSA's booking number, pasted back from the dashboard
        $this->postJson("/api/admin/pickups/{$p->id}/ref", ['ref' => ''])->assertStatus(422);
        $this->postJson("/api/admin/pickups/{$p->id}/ref", ['ref' => '<script>'])->assertStatus(422);
        $this->postJson("/api/admin/pickups/{$p->id}/ref", ['ref' => 'PU-2241-88771'])->assertOk()->assertJsonPath('pickup.smsaRef', 'PU-2241-88771');
        $this->getJson('/api/admin/pickups')->assertJsonPath('open.0.smsaRef', 'PU-2241-88771');
        $this->getJson("/api/admin/orders/$id")->assertJsonPath('smsa.pickup.id', $p->id)->assertJsonPath('smsa.pickup.smsaRef', 'PU-2241-88771')
            ->assertJsonPath('smsa.pickup.when', 'Mon 23 Nov 2026, 11:00–17:00');
        $this->getJson("/api/admin/orders/$other")->assertJsonPath('smsa.pickup.id', $p->id);
        $this->assertSame(2, DB::table('order_events')->where('type', 'smsa_pickup')->where('message', 'like', '%PU-2241-88771%')->count());
        // a booked waybill is not booked twice
        $this->postJson('/api/admin/pickups', ['orderIds' => [$id], 'date' => '2026-11-23', 'from' => '11:00', 'until' => '17:00'])
            ->assertStatus(409)->assertJsonPath('error.code', 'no_waybill');
        // only new waybills are offered next time; empty list = every waybill waiting; parcels and "heavy" can be set
        $third = $this->shipped();
        $this->assertSame([$third], array_column($this->getJson('/api/admin/pickups/form')->json('waiting'), 'orderId'));
        $this->getJson('/api/admin/pickups')->assertJsonPath('waiting', 1);
        $this->postJson('/api/admin/pickups', ['date' => '2026-11-24', 'from' => '09:00', 'until' => '12:00', 'parcels' => 4, 'heavy' => true])
            ->assertOk()->assertJsonPath('pickup.parcels', 4)->assertJsonPath('pickup.heavy', true)->assertJsonPath('pickup.awbs.0.orderId', $third);
    }

    public function test_heavy_is_ticked_by_weight(): void
    {
        $s = Settings::get('smsa');
        $s['pickup']['heavyKg'] = 2;
        Settings::set('smsa', $s);
        $id = $this->shipped(true, 1);   // 1.5 kg
        $id2 = $this->shipped(true, 1);  // + 1.5 kg = 3 kg
        $r = $this->postJson('/api/admin/pickups', ['orderIds' => [$id, $id2], 'date' => '2026-11-23', 'from' => '11:00', 'until' => '17:00'])->assertOk();
        $this->assertSame('Yes', array_column($r->json('pickup.fields'), 'value', 'key')['heavy']);
    }

    public function test_window_rules(): void
    {
        $id = $this->shipped();
        $book = fn (array $w) => $this->postJson('/api/admin/pickups', $w + ['date' => '2026-11-23', 'orderIds' => [$id]]);
        $book(['from' => '15:00', 'until' => '18:00'])->assertStatus(422)->assertJsonPath('error.fields.from', 'SMSA collects between 09:00 and 17:00.');
        $book(['from' => '08:00', 'until' => '12:00'])->assertStatus(422);
        $book(['from' => '13:00', 'until' => '13:30'])->assertStatus(422)->assertJsonPath('error.fields.until', 'Give SMSA at least 1 hour to come.');
        $book(['from' => '09:00', 'until' => '12:00'])->assertStatus(422)->assertJsonPath('error.fields.from', 'That time has passed. Choose a later time.');
        $book(['from' => '12:00', 'until' => '11:00'])->assertStatus(422);
        $book(['date' => '2026-11-22', 'from' => '10:00', 'until' => '12:00'])->assertStatus(422)->assertJsonPath('error.fields.date', 'Choose today or one of the next 6 days.');
        $book(['date' => '2026-12-05', 'from' => '10:00', 'until' => '12:00'])->assertStatus(422);
        $book(['date' => '2026-11-24', 'from' => '09:00', 'until' => '10:00'])->assertOk();   // tomorrow from 9 is fine
        $this->assertSame(0, DB::table('smsa_pickups')->where('status', '<>', 'requested')->count());
    }

    public function test_sandbox_waybills_are_only_practice(): void
    {
        $sb = $this->shipped(false);
        $r = $this->postJson('/api/admin/pickups', ['orderIds' => [$sb], 'date' => '2026-11-23', 'from' => '12:00', 'until' => '16:00'])->assertOk();
        $r->assertJsonPath('pickup.sentVia', 'sandbox')->assertJsonPath('pickup.mode', 'test');
    }

    public function test_cancel_frees_the_waybill(): void
    {
        $id = $this->shipped();
        $this->postJson('/api/admin/pickups/999/cancel')->assertNotFound();
        $pid = $this->postJson("/api/admin/pickups", ['orderIds' => [$id], 'date' => '2026-11-23', 'from' => '11:00', 'until' => '17:00'])->assertOk()->json('pickup.id');
        $this->postJson("/api/admin/pickups/$pid/cancel")->assertOk()->assertJsonPath('pickup.status', 'cancelled');
        $this->postJson("/api/admin/pickups/$pid/cancel")->assertStatus(409);
        $this->assertNull(DB::table('shipments')->where('order_id', $id)->value('pickup_id'));
        // booked again for another time
        $this->postJson("/api/admin/pickups", ['orderIds' => [$id], 'date' => '2026-11-24', 'from' => '09:00', 'until' => '13:00'])->assertOk();
        $this->assertSame(2, DB::table('smsa_pickups')->count());
    }

    public function test_done_when_smsa_scans_the_parcel_and_no_waybill_no_booking(): void
    {
        $id = $this->shipped();
        $this->postJson("/api/admin/pickups", ['orderIds' => [$id], 'date' => '2026-11-23', 'from' => '11:00', 'until' => '17:00'])->assertOk();
        DB::table('shipments')->where('order_id', $id)->update(['mode' => 'test']);   // tracking is read from the (faked) sandbox
        $this->postJson("/api/admin/orders/$id/track")->assertOk();
        $this->assertSame('moving', DB::table('shipments')->where('order_id', $id)->value('status'));
        $this->assertSame('done', DB::table('smsa_pickups')->value('status'));
        $this->postJson("/api/admin/pickups", ['orderIds' => [$id], 'date' => '2026-11-23', 'from' => '11:00', 'until' => '17:00'])
            ->assertStatus(409)->assertJsonPath('error.code', 'no_waybill');
        // an order without a waybill
        $token = $this->postJson('/api/checkout', ['items' => [['id' => $this->productId, 'qty' => 1]], 'country' => 'BH', 'method' => 'whatsapp', 'lang' => 'en',
            'first_name' => 'Ali', 'last_name' => 'Hasan', 'email' => 'ali@example.com', 'phone' => '+973 3333 5555', 'state' => 'Northern', 'city' => 'Riffa',
            'address1' => 'House 1, Road 2, Block 903', 'address2' => ''])->assertOk()->json('order.token');
        $plain = (int) DB::table('orders')->where('token', $token)->value('id');
        $this->postJson("/api/admin/pickups", ['orderIds' => [$plain], 'date' => '2026-11-23', 'from' => '11:00', 'until' => '17:00'])
            ->assertStatus(409)->assertJsonPath('error.code', 'no_waybill');
    }

    public function test_pickup_settings_are_saved_and_checked(): void
    {
        $this->putJson('/api/admin/smsa', ['pickup' => ['dashboardUrl' => 'https://evil.example.com/', 'from' => '09:00', 'until' => '17:00', 'minHours' => 1]])->assertStatus(422);
        $this->putJson('/api/admin/smsa', ['pickup' => ['dashboardUrl' => 'https://ecom.smsaexpress.com/Pickup/Create', 'from' => '09:00', 'until' => '17:00', 'minHours' => 2, 'heavyKg' => 25]])
            ->assertOk()->assertJsonPath('settings.pickup.dashboardUrl', 'https://ecom.smsaexpress.com/Pickup/Create')
            ->assertJsonPath('settings.pickup.minHours', 2)->assertJsonPath('settings.pickup.heavyKg', 25);
        $this->assertSame('https://ecom.smsaexpress.com/Pickup/Create', SmsaPickups::cfg()['dashboardUrl']);
        $this->putJson('/api/admin/smsa', ['pickup' => ['from' => '16:00', 'until' => '17:00', 'minHours' => 2]])->assertStatus(422);
        // everyone who works on orders can book; only owners change the settings
        $this->signOut()->actingAsAdmin($this->makeAdmin('shop_staff'));
        $this->putJson('/api/admin/smsa', ['pickup' => ['dashboardUrl' => '']])->assertForbidden();
        $this->getJson('/api/admin/pickups/form')->assertOk();
        $this->getJson('/api/admin/pickups')->assertOk();
    }
    public function test_past_bookings_close_and_old_waybills_are_not_offered(): void
    {
        $id = $this->shipped();
        $this->postJson('/api/admin/pickups', ['orderIds' => [$id], 'date' => '2026-11-23', 'from' => '11:00', 'until' => '17:00'])->assertOk();
        $this->getJson('/api/admin/pickups')->assertJsonCount(1, 'open');
        // two days later SMSA never came: the booking closes and the waybill can be booked again
        $this->travelTo(Carbon::parse('2026-11-25 10:00:00', 'Asia/Bahrain'));
        $this->signOut()->actingAsAdmin($this->makeAdmin('shop_staff'));
        $this->getJson('/api/admin/pickups')->assertOk()->assertJsonCount(0, 'open')->assertJsonPath('waiting', 1);
        $this->assertSame('cancelled', DB::table('smsa_pickups')->value('status'));
        $this->assertNull(DB::table('shipments')->where('order_id', $id)->value('pickup_id'));
        $this->assertSame(1, DB::table('order_events')->where('order_id', $id)->where('message', 'like', '%its day passed%')->count());
        // a waybill made more than 14 days ago is no longer offered
        DB::table('shipments')->where('order_id', $id)->update(['created_at' => now()->subDays(15)]);
        $this->getJson('/api/admin/pickups')->assertJsonPath('waiting', 0);
        $this->postJson('/api/admin/pickups', ['date' => '2026-11-25', 'from' => '11:00', 'until' => '17:00'])->assertStatus(409)->assertJsonPath('error.code', 'no_waybill');
    }

    public function test_to_prepare_shows_unpacked_orders_whatever_the_date_filter(): void
    {
        $id = $this->shipped();   // paid on Mon 23 Nov, not ticked as prepared
        $this->travelTo(Carbon::parse('2026-11-24 09:30:00', 'Asia/Bahrain'));
        $this->signOut()->actingAsAdmin($this->makeAdmin('shop_staff'));
        $today = ['from' => '2026-11-24', 'to' => '2026-11-24'];
        $r = $this->getJson('/api/admin/orders?'.http_build_query($today + ['tab' => 'prepare']))->assertOk();
        $this->assertSame([$id], array_column($r->json('orders'), 'id'), 'yesterday’s order is still to prepare today');
        $r->assertJsonPath('counts.prepare', 1)->assertJsonPath('counts.all', 0);   // other tabs follow the date filter
        $this->postJson("/api/admin/orders/$id/prepared", ['done' => true])->assertOk();
        $this->getJson('/api/admin/orders?'.http_build_query($today + ['tab' => 'prepare']))->assertJsonPath('counts.prepare', 0);
    }
}
