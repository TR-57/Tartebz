<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The packaging photos in the repository are added first on their products, once each, unchanged. */
class ProductPhotosTest extends TestCase
{
    public function test_photos_are_added_once_and_first(): void
    {
        $cat = DB::table('categories')->insertGetId(['slug' => 'sweet', 'name' => 'Sweet', 'name_ar' => 'حلو']);
        $id = \App\Support\Catalog::save(['name' => 'Strips', 'name_ar' => 'شرايط', 'slug' => 'sweet-sour-strips', 'price' => 3, 'images' => ['/uploads/wp/old.jpg'],
            'category_id' => $cat, 'status' => 'active', 'manage_stock' => true, 'stock_qty' => 5, 'weight_grams' => 300]);
        DB::table('products')->where('id', $id)->update(['slug' => 'sweet-sour-strips']);
        $this->artisan('store:product-photos')->assertSuccessful();
        $images = json_decode(DB::table('products')->find($id)->images, true);
        $this->assertSame(['/uploads/products/tartebz/sweet-sour-strips-box.png', '/uploads/wp/old.jpg'], $images);
        $this->assertSame(filesize(database_path('seed/photos/sweet-sour-strips-box.png')), filesize(config('store.upload_dir').'/products/tartebz/sweet-sour-strips-box.png'));

        // Removed in the admin later: a new deploy does not bring it back.
        DB::table('products')->where('id', $id)->update(['images' => json_encode(['/uploads/wp/old.jpg'])]);
        $this->artisan('store:product-photos')->assertSuccessful();
        $this->assertSame(['/uploads/wp/old.jpg'], json_decode(DB::table('products')->find($id)->images, true));
    }
}
