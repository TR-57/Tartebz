<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Delivery methods (Admin › Shipping rates) and SMSA shipments, the same model as Sniper Safety:
 |  - orders keep the delivery method the customer chose (by air, by road …)
 |  - shipments: one SMSA waybill per order
 |      status  created (waybill made) → moving (SMSA has it) → delivered
 |              failed (SMSA refused it: error says why; "Try again" in the admin, or the next automatic try)
 |      duties_by  who SMSA bills for import VAT and duty: shipper (our account) | consignee (the customer); null inside Bahrain
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->string('shipping_method', 60)->default('')->after('shipping');
            $t->string('shipping_method_name', 120)->default('')->after('shipping_method');
            $t->string('shipping_method_name_ar', 120)->default('')->after('shipping_method_name');
        });
        Schema::create('shipments', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('order_id')->unique();
            $t->string('carrier', 20)->default('smsa');
            $t->string('mode', 10)->default('live');            // test (sandbox) | live
            $t->string('awb', 40)->default('')->index();
            $t->string('service', 10)->default('');             // EDDL inside Bahrain, EIDL by air, EIED by road
            $t->string('duties_by', 10)->nullable();
            $t->enum('status', ['created', 'moving', 'delivered', 'failed', 'cancelled'])->default('created')->index();
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->string('error', 500)->default('');
            $t->string('last_scan', 255)->default('');
            $t->dateTime('last_scan_at')->nullable();
            $t->json('scans')->nullable();
            $t->dateTime('checked_at')->nullable();
            $t->timestamps();
            $t->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['shipping_method', 'shipping_method_name', 'shipping_method_name_ar']));
    }
};
