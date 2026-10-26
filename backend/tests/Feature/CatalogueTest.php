<?php

namespace Tests\Feature;

use App\Support\Currency;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Phase 2a: products, categories, quick stock, bulk actions and the storefront feed. */
class CatalogueTest extends TestCase
{
    private function category(string $slug = 'sweet-sour', array $over = []): int
    {
        return DB::table('categories')->insertGetId($over + ['slug' => $slug, 'name' => ucfirst($slug), 'name_ar' => 'حامض', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function product(array $over = []): array
    {
        return $over + [
            'name' => 'Mango Fruits', 'name_ar' => 'مانجو', 'price' => 3, 'images' => ['/uploads/products/a.jpg'],
            'category_id' => $this->category('cat'.uniqid()), 'status' => 'active', 'manage_stock' => true, 'stock_qty' => 5,
            'flavor' => 'Mango', 'sour_level' => 3, 'weight_grams' => 500,
            'description' => 'Sweet and sour mango.', 'description_ar' => 'مانجو حامض حلو.',
        ];
    }

    public function test_product_rules_are_reported_per_field_and_a_valid_product_saves(): void
    {
        $this->actingAsAdmin($this->makeAdmin('manager'));
        $this->postJson('/api/admin/products', ['name' => '', 'price' => 0])
            ->assertStatus(422)
            ->assertJsonPath('error.fields.name', 'Give the product a name.')
            ->assertJsonPath('error.fields.price', 'Enter a price above zero.')
            ->assertJsonPath('error.fields.images', 'Add at least one photo.')
            ->assertJsonPath('error.fields.category_id', 'Choose a category for the product.');

        $id = $this->postJson('/api/admin/products', $this->product())->assertStatus(201)->json('product.id');
        $p = DB::table('products')->find($id);
        $this->assertSame('mango-fruits', $p->slug);
        $this->assertSame('3.000', $p->price);
        $this->assertSame(3, (int) $p->sour_level);
        $this->assertDatabaseHas('audit_log', ['action' => 'product_created', 'entity_id' => (string) $id]);

        // Partial update keeps everything else.
        $this->putJson("/api/admin/products/$id", ['price' => 3.5])->assertOk();
        $this->assertSame('Mango Fruits', DB::table('products')->find($id)->name);
        $this->assertSame('3.500', DB::table('products')->find($id)->price);
    }

    public function test_quick_stock_and_variable_products(): void
    {
        $this->actingAsAdmin($this->makeAdmin('manager'));
        $id = $this->postJson('/api/admin/products', $this->product())->json('product.id');
        $this->postJson("/api/admin/products/$id/stock", ['stock_qty' => 0])->assertOk()->assertJsonPath('product.available', false);
        $this->postJson("/api/admin/products/$id/stock", ['stock_qty' => 'lots'])->assertStatus(422);
        $this->postJson("/api/admin/products/$id/stock", ['stock_qty' => 12])->assertOk()->assertJsonPath('product.stockQty', 12);

        $vid = $this->postJson('/api/admin/products', $this->product(['name' => 'Strips', 'type' => 'variable', 'variations' => [
            ['size' => 'Small', 'stock_qty' => 2], ['size' => 'Small', 'stock_qty' => 1],
        ]]))->assertStatus(422)->assertJsonPath('error.fields', fn ($f) => isset($f['variations.1.colour']));
        $vid = $this->postJson('/api/admin/products', $this->product(['name' => 'Strips', 'type' => 'variable', 'variations' => [
            ['size' => 'Small', 'stock_qty' => 2], ['size' => 'Large', 'stock_qty' => 0],
        ]]))->assertStatus(201)->json('product.id');
        $this->assertSame(2, DB::table('product_variations')->where('product_id', $vid)->count());
        $this->postJson("/api/admin/products/$vid/stock", ['stock_qty' => 3])->assertStatus(422);
    }

    public function test_bulk_show_skips_products_that_break_the_rules_and_delete_works(): void
    {
        $this->actingAsAdmin($this->makeAdmin('manager'));
        $ok = $this->postJson('/api/admin/products', $this->product(['status' => 'hidden']))->json('product.id');
        // A hidden import without a photo (like the yellow marshmallow) may exist, but cannot be shown.
        $noPhoto = \App\Support\Catalog::save($this->product(['name' => 'No photo', 'images' => [], 'status' => 'hidden']), null, true);
        $r = $this->postJson('/api/admin/products/bulk', ['ids' => [$ok, $noPhoto], 'action' => 'show'])->assertOk();
        $this->assertSame(1, $r->json('done'));
        $this->assertSame('active', DB::table('products')->find($ok)->status);
        $this->assertSame('hidden', DB::table('products')->find($noPhoto)->status);

        $this->getJson('/api/admin/products?tab=missing')->assertOk()->assertJsonPath('counts.missing', 1);
        $this->postJson('/api/admin/products/bulk', ['ids' => [$noPhoto], 'action' => 'delete'])->assertOk()->assertJsonPath('done', 1);
        $this->assertDatabaseMissing('products', ['id' => $noPhoto]);
    }

    public function test_categories_cannot_loop_or_be_deleted_while_used(): void
    {
        $this->actingAsAdmin($this->makeAdmin('manager'));
        $a = $this->postJson('/api/admin/categories', ['name' => 'Sweet', 'name_ar' => 'حلو'])->assertStatus(201)->json('id');
        $b = $this->postJson('/api/admin/categories', ['name' => 'Inner', 'name_ar' => 'داخلي', 'parent_id' => $a])->json('id');
        $this->putJson("/api/admin/categories/$a", ['parent_id' => $b])->assertStatus(422)->assertJsonPath('error.fields.parent_id', 'A category cannot sit inside itself.');
        $this->postJson('/api/admin/categories', ['name' => 'X'])->assertStatus(422)->assertJsonPath('error.fields.name_ar', 'Give the category its Arabic name.');

        $this->deleteJson("/api/admin/categories/$a")->assertStatus(409);
        $this->postJson('/api/admin/products', $this->product(['category_id' => $b]))->assertStatus(201);
        $this->deleteJson("/api/admin/categories/$b")->assertStatus(409)->assertJsonPath('error.code', 'in_use');

        // A hidden parent hides its children and their products on the website.
        $this->putJson("/api/admin/categories/$a", ['enabled' => false])->assertOk();
        $this->signOut()->getJson('/api/storefront')->assertOk()->assertJsonCount(0, 'products');
    }

    public function test_storefront_feed_has_live_products_currencies_and_answers_304_when_unchanged(): void
    {
        $this->actingAsAdmin($this->makeAdmin('manager'));
        $id = $this->postJson('/api/admin/products', $this->product(['best_seller' => true]))->json('product.id');
        $this->postJson('/api/admin/products', $this->product(['name' => 'Secret', 'status' => 'hidden']));
        $this->signOut();

        $r = $this->getJson('/api/storefront')->assertOk();
        $this->assertCount(1, $r->json('products'));
        $p = $r->json('products.0');
        $this->assertSame($id, $p['id']);
        $this->assertEquals(3, $p['price']);                       // always BHD
        $this->assertSame('best-sellers', $p['cats'][0]);
        $this->assertSame('/img/800/uploads/products/a.jpg', $p['images'][0]['src']);
        $this->assertSame('BHD', $r->json('baseCurrency'));
        $sar = collect($r->json('currencies'))->firstWhere('code', 'SAR');
        $this->assertEquals(10, $sar['rate']);
        $this->assertSame(['SA'], $sar['countries']);

        $etag = $r->headers->get('ETag');
        $this->get('/api/storefront', ['If-None-Match' => $etag])->assertStatus(304);
    }

    public function test_owner_sets_currency_rates_and_bhd_stays_the_base(): void
    {
        $this->actingAsAdmin($this->makeAdmin('owner'));
        $list = $this->getJson('/api/admin/currencies')->assertOk()->json('list');
        $list = array_values(array_filter($list, fn ($c) => $c['code'] !== 'BHD'));
        $this->putJson('/api/admin/currencies', ['list' => $list])->assertStatus(422)->assertJsonPath('error.fields.list', 'BHD must stay in the list – every price is kept in BHD.');

        $list = $this->getJson('/api/admin/currencies')->json('list');
        foreach ($list as &$c) {
            if ($c['code'] === 'SAR') {
                $c['rate'] = 9.95;
            }
            if ($c['code'] === 'BHD') {
                $c['rate'] = 5; // ignored: the base is always 1
            }
        }
        unset($c);
        $list[] = ['code' => 'XYZ', 'rate' => 2, 'countries' => ['SA']]; // SA already pays in SAR
        $this->putJson('/api/admin/currencies', ['list' => $list])->assertStatus(422)->assertJsonPath('error.fields', fn ($f) => isset($f['list.7.countries']));
        array_pop($list);
        $this->putJson('/api/admin/currencies', ['list' => $list])->assertOk();

        $this->assertEquals(1, Currency::get('BHD')['rate']);
        $this->assertEquals(29.85, Currency::fromBase(3, 'SAR'));    // 3 BHD shown as SAR 29.85
        $this->assertEquals(3.0, Currency::toBase(29.85, 9.95));     // and counted back as 3.000 BHD
        $this->assertEquals(2.46, Currency::fromBase(3, 'KWD'));
        $this->assertDatabaseHas('audit_log', ['action' => 'currencies_saved']);

        $this->actingAsAdmin($this->makeAdmin('manager'));
        $this->putJson('/api/admin/currencies', ['list' => $list])->assertStatus(403);
    }
}
