<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | The orders board for shop staff (/staff):
 |  - admins.role gets "shop_staff"
 |  - orders: who prepared it (the tick on the board), where it came from (website / staff), who created it,
 |    and alert_at – when it became an order the team must act on (sound + phone notification)
 |  - push_subscriptions: the phones / computers that get a notification for each new order
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE admins MODIFY role ENUM('owner','manager','staff','order_manager','order_assistant','shop_staff') NOT NULL DEFAULT 'staff'");

        Schema::table('orders', function (Blueprint $t) {
            $t->string('source', 10)->default('website')->after('payment_method');   // website | staff
            $t->unsignedInteger('created_by')->nullable()->after('source');
            $t->string('created_by_name', 120)->default('')->after('created_by');
            $t->timestamp('prepared_at')->nullable()->after('paid_at');
            $t->string('prepared_by', 120)->default('')->after('prepared_at');
            $t->timestamp('alert_at')->nullable()->index()->after('prepared_by');
            $t->index('country');
        });

        Schema::create('push_subscriptions', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('admin_id')->index();
            $t->char('endpoint_hash', 64)->unique();
            $t->text('endpoint');
            $t->string('p256dh', 200);
            $t->string('auth', 100);
            $t->string('device', 120)->default('');
            $t->unsignedSmallInteger('fails')->default(0);
            $t->timestamp('last_sent_at')->nullable();
            $t->timestamps();
            $t->foreign('admin_id')->references('id')->on('admins')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
        Schema::table('orders', function (Blueprint $t) {
            $t->dropIndex(['country']);
            $t->dropColumn(['source', 'created_by', 'created_by_name', 'prepared_at', 'prepared_by', 'alert_at']);
        });
        DB::statement("UPDATE admins SET role = 'staff' WHERE role = 'shop_staff'");
        DB::statement("ALTER TABLE admins MODIFY role ENUM('owner','manager','staff','order_manager','order_assistant') NOT NULL DEFAULT 'staff'");
    }
};
