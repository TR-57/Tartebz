<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | stock_holds – pieces kept aside so two customers never pay for the same last piece:
 |   cart holds   (cart_key set)  – while the product sits in a customer's cart (renewed while they are active)
 |   order holds  (order_id set)  – while an order waits for its payment
 | order_refunds – money given back (through Tap, or recorded when returned another way).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_holds', function (Blueprint $t) {
            $t->increments('id');
            $t->char('cart_key', 32)->nullable();
            $t->unsignedInteger('order_id')->nullable()->index();
            $t->unsignedInteger('product_id')->index();
            $t->unsignedSmallInteger('qty');
            $t->string('ip', 45)->default('');
            $t->timestamp('expires_at')->index();
            $t->timestamps();
            $t->unique(['cart_key', 'product_id']);
            $t->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $t->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
        });

        Schema::create('order_refunds', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('order_id')->index();
            $t->string('method', 10);                  // tap | manual
            $t->string('tap_refund_id', 64)->default('')->index();
            $t->string('status', 10)->default('pending'); // pending | done | failed
            $t->decimal('amount', 14, 3);              // in the currency the customer paid
            $t->char('currency', 3);
            $t->decimal('amount_bhd', 12, 3);          // what reports subtract
            $t->string('reason', 300)->default('');
            $t->boolean('restocked')->default(false);
            $t->unsignedInteger('admin_id')->nullable();
            $t->string('admin_name', 120)->default('');
            $t->timestamps();
            $t->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
        });

        // Refunded so far (finished refunds only): in the paid currency, and in BHD for reports.
        Schema::table('orders', function (Blueprint $t) {
            $t->decimal('refunded_amount', 14, 3)->default(0)->after('charge_amount');
            $t->decimal('refunded_bhd', 12, 3)->default(0)->after('refunded_amount');
            // The browser cart the order came from: its reservations and the cart's never compete with each other.
            $t->char('cart_key', 32)->nullable()->after('token')->index();
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['refunded_amount', 'refunded_bhd', 'cart_key']));
        Schema::dropIfExists('order_refunds');
        Schema::dropIfExists('stock_holds');
    }
};
