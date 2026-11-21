<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SMSA's pickup form asks whether the shipment is heavy. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smsa_pickups', function (Blueprint $t) {
            $t->boolean('heavy')->default(false)->after('weight_kg');
        });
    }

    public function down(): void
    {
        Schema::table('smsa_pickups', fn (Blueprint $t) => $t->dropColumn('heavy'));
    }
};
