<?php

namespace Tests\Feature;

use App\Support\Settings;
use App\Support\Smsa;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Addresses SMSA accepts (lines up to 100 characters, city and district up to 50) – at checkout, in the waybill and when
 * the team edits an order's customer details.
 */
class CustomerDetailsTest extends TestCase
{
    private int $productId;

    /** @var array<int, array> what was sent to SMSA */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $cat = DB::table('categories')->insertGetId(['slug' => 'sweet', 'name' => 'Sweet', 'name_ar' => 'حلو']);
        $this->productId = \App\Support\Catalog::save([
            'name' => 'Mango Fruits', 'name_ar' => 'مانجو', 'price' => 3, 'images' => ['/uploads/p/a.jpg'], 'category_id' => $cat,
            'status' => 'active', 'manage_stock' => true, 'stock_qty' => 50, 'weight_grams' => 500,
        ]);
        Http::fake([
            'ecomapis-sandbox.azurewebsites.net/api/shipment/b2c/new' => function ($req) {
                $this->sent[] = $req->data();
                $n = (string) (231200000500 + count($this->sent));

                return Http::response(['sawb' => $n, 'waybills' => [['awb' => $n, 'awbFile' => '']]]);
            },
            'ecomapis-sandbox.azurewebsites.net/*' => Http::response([]),
        ]);
        $this->actingAsAdmin($this->makeAdmin('owner'));
        $this->putJson('/api/admin/smsa', ['enabled' => true, 'mode' => 'test', 'testKey' => 'smsa-sandbox-key', 'methods' => ['gcc-std'], 'services' => ['gcc-std' => 'EIDL'],
            'dutiesBy' => 'consignee', 'autoStatus' => true, 'contents' => 'Sweets',
            'shipper' => ['name' => 'Tartebz Sweets', 'phone' => '+973 3753 3757', 'country' => 'BH', 'city' => 'Riffa', 'district' => 'West Riffa', 'address1' => 'Al-Ajeera Street']])
            ->assertOk();
    }

    private function checkout(array $over = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/checkout', $over + [
            'items' => [['id' => $this->productId, 'qty' => 2]], 'country' => 'BH', 'method' => 'whatsapp', 'lang' => 'en',
            'first_name' => 'Sara', 'last_name' => 'Ali', 'email' => 'sara@example.com', 'phone' => '+973 3333 4444',
            'state' => 'Northern', 'city' => 'Riffa', 'address1' => 'House 12, Road 34, Block 905', 'address2' => '',
        ]);
    }

    public function test_checkout_refuses_addresses_smsa_would_refuse(): void
    {
        $r = $this->checkout(['address1' => str_repeat('Building 1 ', 10).'x', 'city' => str_repeat('Doha', 13), 'state' => str_repeat('a', 51), 'address2' => str_repeat('b', 101)])
            ->assertStatus(422);
        $this->assertStringContainsString('under 100 characters', $r->json('error.fields.address1'));
        $this->assertSame('Keep the city under 50 characters.', $r->json('error.fields.city'));
        $this->assertSame('Keep the area under 50 characters.', $r->json('error.fields.state'));
        $this->assertSame('Keep this line under 100 characters.', $r->json('error.fields.address2'));
        $this->assertSame(0, DB::table('orders')->count());

        // Exactly at the limits is fine; spaces are tidied.
        $this->checkout(['address1' => '  '.str_repeat('a', 100).'  ', 'city' => str_repeat('c', 50), 'state' => str_repeat('s', 50)])->assertOk();
        $this->assertSame(100, mb_strlen(DB::table('orders')->value('address1')));
    }

    public function test_waybill_address_always_fits_smsa_limits(): void
    {
        // The address of order #20116: a long first line, a landmark in the second and no area.
        [$l1, $l2, $district] = Smsa::addressLines(
            'Doha, Qatar Zone 69 Street 869 Building 34 Al Gharrafa near Al Gharrafa Sports Club, Villa 7, Gate 2 on the left side',
            'Near the big mosque and the petrol station on the main road towards Al Rayyan, opposite to the supermarket entrance',
            '', 'Doha/qatar, DOHA, the capital city of the State of Qatar, Middle East');
        $this->assertLessThanOrEqual(100, mb_strlen($l1));
        $this->assertLessThanOrEqual(100, mb_strlen($l2));
        $this->assertLessThanOrEqual(50, mb_strlen($district));
        $this->assertStringStartsWith('Doha, Qatar Zone 69', $l1);
        $this->assertStringContainsString('Gate 2', $l2);   // what did not fit in line 1 moves to line 2

        // A very short first line gets the area added (SMSA wants at least 10 characters).
        [$l1, , $district] = Smsa::addressLines('Villa 7', '', 'West Riffa', 'Riffa');
        $this->assertGreaterThanOrEqual(10, mb_strlen($l1));
        $this->assertSame('West Riffa', $district);

        // An older order with a long second line and area still goes through.
        $id = (int) $this->checkout()->assertOk()->json('order.id') ?: (int) DB::table('orders')->max('id');
        DB::table('orders')->where('id', $id)->update(['payment_status' => 'paid', 'paid_at' => now(), 'state' => str_repeat('Area ', 15), 'address2' => str_repeat('Landmark ', 20)]);
        $this->postJson("/api/admin/orders/$id/ship", ['parcels' => 1, 'weightKg' => 1])->assertOk();
        $c = end($this->sent)['ConsigneeAddress'];
        $this->assertLessThanOrEqual(50, mb_strlen($c['District']));
        $this->assertLessThanOrEqual(50, mb_strlen($c['City']));
        $this->assertLessThanOrEqual(100, mb_strlen($c['AddressLine1']));
        $this->assertLessThanOrEqual(100, mb_strlen($c['AddressLine2']));
    }

    public function test_team_edits_customer_details_on_an_order(): void
    {
        $this->checkout()->assertOk();
        $id = (int) DB::table('orders')->max('id');

        // Shop staff (the orders board) can fix an address.
        $this->actingAsAdmin($this->makeAdmin('shop_staff'));
        $bad = $this->putJson("/api/admin/orders/$id/customer", ['address1' => str_repeat('x', 101), 'city' => 'R'])->assertStatus(422);
        $this->assertArrayHasKey('address1', $bad->json('error.fields'));
        $this->assertArrayHasKey('city', $bad->json('error.fields'));

        $r = $this->putJson("/api/admin/orders/$id/customer", ['address1' => 'Villa 7, Road 12', 'address2' => 'Near the mosque', 'phone' => '+973 3333 5555'])->assertOk();
        $this->assertEqualsCanonicalizing(['address1', 'address2', 'phone'], $r->json('changed'));
        $this->assertFalse($r->json('waybillKeepsOldAddress'));
        $o = DB::table('orders')->find($id);
        $this->assertSame(['Villa 7, Road 12', 'Near the mosque', 'Sara'], [$o->address1, $o->address2, $o->first_name]);
        $this->assertStringContainsString('97333335555', preg_replace('/\D/', '', $o->phone));
        $ev = DB::table('order_events')->where('order_id', $id)->where('type', 'customer')->first();
        $this->assertSame('Customer details changed: phone, address, address line 2.', $ev->message);

        // An emptied box clears the field; a required one cannot be emptied.
        $this->putJson("/api/admin/orders/$id/customer", ['state' => ''])->assertOk()->assertJsonPath('changed', ['state']);
        $this->assertSame('', DB::table('orders')->where('id', $id)->value('state'));
        $this->putJson("/api/admin/orders/$id/customer", ['city' => ''])->assertStatus(422)->assertJsonPath('error.fields.city', 'Enter your city.');

        // Saving the same again changes nothing and writes no event.
        $this->putJson("/api/admin/orders/$id/customer", ['address1' => 'Villa 7, Road 12'])->assertOk()->assertJsonPath('changed', []);
        $this->assertSame(2, DB::table('order_events')->where('order_id', $id)->where('type', 'customer')->count());

        // With a waybill already made, the team is told it keeps the old address.
        $this->actingAsAdmin($this->makeAdmin('owner'));
        DB::table('orders')->where('id', $id)->update(['payment_status' => 'paid', 'paid_at' => now()]);
        $ship = $this->postJson("/api/admin/orders/$id/ship", ['parcels' => 1, 'weightKg' => 1]);
        $this->assertSame(200, $ship->status(), $ship->getContent());
        $this->putJson("/api/admin/orders/$id/customer", ['city' => 'Isa Town'])->assertOk()->assertJsonPath('waybillKeepsOldAddress', true);

        // Roles without the orders board cannot.
        $this->actingAsAdmin($this->makeAdmin('order_assistant'));
        $this->putJson("/api/admin/orders/$id/customer", ['city' => 'Manama'])->assertStatus(403);
    }
}
