<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SMSA pickup bookings: a day and a time window for the courier to collect one or more waybills from the shop. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smsa_pickups', function (Blueprint $t) {
            $t->increments('id');
            $t->string('mode', 10)->default('live');                 // test (sandbox: not sent to SMSA) | live
            $t->date('pickup_date');
            $t->string('ready_from', 5);                              // HH:MM, shop time
            $t->string('ready_until', 5);
            $t->enum('status', ['requested', 'cancelled', 'done'])->default('requested')->index();
            $t->unsignedSmallInteger('parcels')->default(1);
            $t->decimal('weight_kg', 8, 2)->default(0);
            $t->string('note', 300)->default('');
            $t->string('sent_via', 20)->default('');                   // email | whatsapp | copy | sandbox
            $t->string('sent_to', 255)->default('');
            $t->text('message')->nullable();                          // the request exactly as sent
            $t->string('created_by', 120)->default('');
            $t->dateTime('cancelled_at')->nullable();
            $t->dateTime('done_at')->nullable();
            $t->timestamps();
        });
        Schema::table('shipments', function (Blueprint $t) {
            $t->unsignedInteger('pickup_id')->nullable()->index()->after('status');
            $t->unsignedSmallInteger('parcels')->default(1)->after('service');      // boxes on the waybill
            $t->decimal('weight_kg', 8, 2)->nullable()->after('parcels');          // as sent to SMSA (null: the order's weight)
        });
    }

    public function down(): void
    {
        Schema::table('shipments', fn (Blueprint $t) => $t->dropColumn(['pickup_id', 'parcels', 'weight_kg']));
        Schema::dropIfExists('smsa_pickups');
    }
};
