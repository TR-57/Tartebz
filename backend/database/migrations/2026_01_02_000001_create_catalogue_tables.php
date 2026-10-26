<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Phase 2 – catalogue: categories, products, variations, bundles, the media library and queued photo downloads.
 | One storefront: the category placement lives on the product itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $t) {
            $t->increments('id');
            $t->string('slug', 60)->unique();
            $t->unsignedInteger('parent_id')->nullable()->index();
            $t->string('name', 120);
            $t->string('name_ar', 120)->default('');
            $t->text('blurb')->nullable();
            $t->text('blurb_ar')->nullable();
            $t->string('image', 255)->default('');
            $t->string('image_pos', 20)->default('50% 50%');
            $t->string('icon', 40)->default('');
            $t->integer('sort')->default(0);
            $t->boolean('enabled')->default(true);
            $t->boolean('in_menu')->default(true);
            $t->boolean('on_home')->default(false);
            $t->string('size_image', 255)->default('');
            $t->string('size_garment', 20)->default('');
            $t->unsignedInteger('weight_grams')->nullable();
            $t->string('seo_title', 200)->default('');
            $t->string('seo_title_ar', 200)->default('');
            $t->string('seo_desc', 400)->default('');
            $t->string('seo_desc_ar', 400)->default('');
            $t->timestamps();
        });

        Schema::create('products', function (Blueprint $t) {
            $t->increments('id');
            $t->string('slug', 190)->unique();
            $t->enum('type', ['simple', 'variable'])->default('simple');
            $t->boolean('bundle_only')->default(false);
            $t->string('name', 255);
            $t->string('name_ar', 255)->default('');
            $t->string('brand', 120)->default('');
            $t->string('sku', 80)->default('')->index();
            $t->string('barcode', 64)->default('')->index();
            $t->decimal('price', 12, 3)->default(0);
            $t->decimal('regular_price', 12, 3)->default(0);
            $t->enum('tax_status', ['taxable', 'none'])->default('taxable');
            $t->json('certs')->nullable();
            $t->json('industries')->nullable();
            $t->json('sizes')->nullable();
            $t->json('colours')->nullable();
            $t->json('lengths')->nullable();
            $t->json('colour_images')->nullable();
            $t->json('images')->nullable();
            $t->mediumText('description')->nullable();
            $t->mediumText('description_ar')->nullable();
            $t->text('short_desc')->nullable();
            $t->text('short_desc_ar')->nullable();
            // Tartebz: candy-specific attributes shown on the storefront
            $t->string('flavor', 120)->default('');
            $t->string('flavor_ar', 120)->default('');
            $t->unsignedTinyInteger('sour_level')->nullable(); // 1 gentle … 5 electric
            $t->unsignedInteger('weight_grams')->default(0);
            $t->boolean('own_weight')->default(false);
            $t->boolean('manage_stock')->default(false);
            $t->integer('stock_qty')->default(0);
            $t->boolean('in_stock')->default(true);
            $t->string('permalink', 255)->default('');
            $t->enum('status', ['active', 'hidden'])->default('active')->index();
            $t->string('seo_title', 200)->default('');
            $t->string('seo_title_ar', 200)->default('');
            $t->string('seo_desc', 400)->default('');
            $t->string('seo_desc_ar', 400)->default('');
            $t->unsignedInteger('category_id')->nullable()->index();
            $t->integer('sort_rank')->default(9999);
            $t->enum('gender', ['', 'men', 'women', 'unisex'])->default('');
            $t->boolean('is_new')->default(false);
            $t->boolean('best_seller')->default(false); // the "★ Best seller" badge
            $t->timestamps();
            $t->index('created_at');
        });

        Schema::create('product_variations', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('product_id');
            $t->string('colour', 60)->default('');
            $t->string('size', 40)->default('');
            $t->string('length', 20)->default('');
            $t->string('sku', 80)->default('')->index();
            $t->string('barcode', 64)->nullable()->unique();
            $t->string('barcode2', 64)->default('')->index();
            $t->integer('stock_qty')->default(0);
            $t->boolean('enabled')->default(true);
            $t->integer('position')->default(0);
            $t->string('image', 255)->default(''); // always empty: photos are per colour on the product
            $t->unsignedBigInteger('woo_id')->nullable()->unique();
            $t->timestamps();
            $t->unique(['product_id', 'colour', 'size', 'length']);
            $t->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });

        Schema::create('product_bundles', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('product_id');
            $t->unsignedTinyInteger('pieces');
            $t->decimal('price', 10, 3);
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['product_id', 'pieces']);
            $t->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });

        Schema::create('media', function (Blueprint $t) {
            $t->increments('id');
            $t->string('path', 255)->unique();          // relative to the uploads folder
            $t->string('url', 300);
            $t->string('original_name', 190)->default('');
            $t->string('title', 190)->default('');
            $t->string('alt_en', 300)->default('');
            $t->string('alt_ar', 300)->default('');
            $t->string('mime', 60)->default('');
            $t->unsignedInteger('size')->default(0);
            $t->unsignedInteger('width')->nullable();
            $t->unsignedInteger('height')->nullable();
            $t->enum('kind', ['image', 'document', 'video', 'other'])->default('image')->index();
            $t->string('folder', 20)->default('library')->index(); // use: product, content, logo, brand, library
            $t->unsignedInteger('version')->default(0);
            $t->unsignedInteger('uploaded_by')->nullable();
            $t->timestamps();
            $t->index('created_at');
        });

        Schema::create('photo_fetches', function (Blueprint $t) {
            $t->increments('id');
            $t->string('url', 1000);
            $t->char('url_hash', 40)->index();
            $t->unsignedInteger('product_id')->nullable()->index();
            $t->unsignedInteger('variation_id')->nullable();
            $t->unsignedSmallInteger('position')->default(0);
            $t->enum('status', ['pending', 'done', 'failed'])->default('pending')->index();
            $t->string('local', 255)->default('');
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->string('error', 255)->default('');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['photo_fetches', 'media', 'product_bundles', 'product_variations', 'products', 'categories'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
