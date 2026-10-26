<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Orders. Every amount column without a prefix is BHD (what the admin and reports add up);
 | charge_amount / charge_currency are what the customer was actually charged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $t) {
            $t->increments('id');
            $t->string('number', 20)->nullable()->unique();
            $t->char('token', 40)->unique();                 // the customer's link to this order (never the id)
            $t->string('status', 30)->default('pending_payment')->index();
            $t->string('payment_method', 20)->default('tap');
            $t->string('payment_status', 20)->default('unpaid')->index();
            $t->string('first_name', 80);
            $t->string('last_name', 80)->default('');
            $t->string('email', 190)->default('');
            $t->string('phone', 30)->default('');
            $t->char('country', 2);
            $t->string('state', 80)->default('');
            $t->string('city', 80)->default('');
            $t->string('address1', 200)->default('');
            $t->string('address2', 200)->default('');
            $t->text('notes')->nullable();
            $t->char('lang', 2)->default('ar');
            $t->char('currency', 3)->default('BHD');          // shown to the customer
            $t->decimal('fx_rate', 14, 6)->default(1);        // customer currency per 1 BHD, at checkout
            $t->decimal('subtotal', 12, 3)->default(0);
            $t->decimal('shipping', 12, 3)->default(0);
            $t->decimal('discount', 12, 3)->default(0);
            $t->decimal('total', 12, 3)->default(0);
            $t->char('charge_currency', 3)->default('BHD');
            $t->decimal('charge_amount', 14, 3)->default(0);
            $t->unsignedInteger('weight_grams')->default(0);
            $t->string('tap_charge_id', 64)->default('')->index();
            $t->string('tap_status', 20)->default('');
            $t->string('tap_mode', 5)->default('');
            $t->timestamp('paid_at')->nullable();
            $t->boolean('stock_taken')->default(false);
            $t->string('carrier', 20)->default('');
            $t->string('awb', 40)->default('')->index();
            $t->string('label_path', 255)->default('');
            $t->string('tracking_status', 200)->default('');
            $t->timestamp('tracking_at')->nullable();
            $t->timestamp('shipped_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->string('ip', 45)->default('');
            $t->string('user_agent', 255)->default('');
            $t->timestamps();
            $t->index('created_at');
        });

        Schema::create('order_items', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('order_id');
            $t->unsignedInteger('product_id')->nullable()->index();
            $t->string('name', 255);
            $t->string('name_ar', 255)->default('');
            $t->string('image', 300)->default('');
            $t->decimal('price', 12, 3);
            $t->unsignedSmallInteger('qty');
            $t->decimal('line_total', 12, 3);
            $t->unsignedInteger('weight_grams')->default(0);
            $t->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
        });

        Schema::create('order_events', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('order_id')->index();
            $t->string('type', 40);
            $t->string('message', 500)->default('');
            $t->json('data')->nullable();
            $t->unsignedInteger('admin_id')->nullable();
            $t->string('admin_name', 120)->default('');
            $t->timestamp('created_at')->nullable();
            $t->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_events');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
