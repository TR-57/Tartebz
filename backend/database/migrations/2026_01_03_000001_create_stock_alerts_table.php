<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* "Tell me when it's back": a customer leaves an email or a phone number on an out-of-stock product. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_alerts', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('product_id');
            $t->string('email', 190)->nullable();
            $t->string('phone', 30)->nullable();      // digits with country code, e.g. 97333334444
            $t->char('lang', 2)->default('ar');
            $t->char('country', 2)->default('');
            $t->string('ip', 45)->default('');
            $t->timestamp('created_at')->nullable();
            $t->timestamp('notified_at')->nullable()->index(); // email sent, or staff marked the WhatsApp message as sent
            $t->string('notified_by', 20)->default('');         // email | whatsapp
            $t->unique(['product_id', 'email']);
            $t->unique(['product_id', 'phone']);
            $t->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_alerts');
    }
};
