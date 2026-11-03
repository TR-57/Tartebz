<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Maintenance mode: the switch, the page in the web folder, what pauses and who still gets through. */
class MaintenanceTest extends TestCase
{
    private string $dir;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/tz-maint-'.uniqid();
        mkdir($this->dir);
        config(['store.public_dir' => $this->dir]);
        $cat = DB::table('categories')->insertGetId(['slug' => 'sweet', 'name' => 'Sweet', 'name_ar' => 'حلو']);
        $this->productId = \App\Support\Catalog::save(['name' => 'Mango', 'name_ar' => 'مانجو', 'slug' => 'mango', 'price' => 3, 'images' => ['/uploads/p/a.jpg'],
            'category_id' => $cat, 'status' => 'active', 'manage_stock' => true, 'stock_qty' => 5, 'weight_grams' => 500]);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/maintenance-on.html');
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function order(): array
    {
        return ['items' => [['id' => $this->productId, 'qty' => 1]], 'country' => 'BH', 'method' => 'whatsapp', 'lang' => 'en', 'first_name' => 'Sara', 'last_name' => 'Ali',
            'email' => '', 'phone' => '+973 3600 1234', 'city' => 'Riffa', 'state' => '', 'address1' => 'House 12, Road 34', 'address2' => ''];
    }

    public function test_managers_switch_it_and_the_page_is_written(): void
    {
        $this->actingAsAdmin($this->makeAdmin('staff'));
        $this->putJson('/api/admin/maintenance', ['on' => true])->assertStatus(403);
        $this->actingAsAdmin($this->makeAdmin('manager', ['name' => 'Mona']));
        $this->putJson('/api/admin/maintenance', ['on' => true, 'messageAr' => 'نرجع الساعة ٨ مساءً', 'message' => 'Back at 8 pm'])->assertOk()
            ->assertJsonPath('maintenance.on', true)->assertJsonPath('maintenance.by', 'Mona');
        $page = file_get_contents($this->dir.'/maintenance-on.html');
        $this->assertStringContainsString('نرجع الساعة ٨ مساءً', $page);
        $this->assertStringContainsString('Back at 8 pm', $page);
        $this->getJson('/api/admin/me')->assertJsonPath('maintenance', true);
        $this->get('/api/admin/maintenance/preview')->assertOk()->assertSee('Back at 8 pm');
        $this->assertDatabaseHas('audit_log', ['action' => 'maintenance_on']);

        $this->putJson('/api/admin/maintenance', ['on' => false])->assertOk()->assertJsonPath('maintenance.on', false);
        $this->assertFileDoesNotExist($this->dir.'/maintenance-on.html');
    }

    public function test_customers_are_paused_and_the_team_gets_through(): void
    {
        \App\Support\Maintenance::set(true, 'Back soon', '', null);
        // Visitors
        $this->postJson('/api/checkout', $this->order())->assertStatus(503)->assertJsonPath('error.code', 'maintenance')->assertJsonPath('error.message', 'Back soon');
        $this->postJson('/api/cart/hold', ['cart' => str_repeat('a', 32), 'items' => []])->assertStatus(503);
        $this->postJson('/api/stock-alerts', ['product' => $this->productId, 'contact' => 'x@example.com'])->assertStatus(503);
        $this->get('/product/mango')->assertStatus(503)->assertHeader('Retry-After', '3600')->assertSee('Back soon');
        $this->getJson('/api/storefront')->assertOk()->assertJsonPath('maintenance', true);
        $this->postJson('/api/payments/tap/webhook', ['id' => 'chg_unknown'])->assertOk(); // Tap still reaches the shop
        // The team
        $this->actingAsAdmin($this->makeAdmin('shop_staff'));
        $this->postJson('/api/checkout', $this->order())->assertOk();
        $this->get('/product/mango')->assertOk();
    }

    public function test_command_line_switch(): void
    {
        $this->artisan('store:maintenance', ['action' => 'on', '--message' => 'Upgrading'])->assertSuccessful();
        $this->assertTrue(\App\Support\Maintenance::on());
        $this->assertFileExists($this->dir.'/maintenance-on.html');
        $this->artisan('store:maintenance', ['action' => 'off'])->assertSuccessful();
        $this->assertFalse(\App\Support\Maintenance::on());
        $this->assertFileDoesNotExist($this->dir.'/maintenance-on.html');
    }
}
