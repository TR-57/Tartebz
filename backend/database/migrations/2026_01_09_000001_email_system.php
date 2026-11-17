<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | The email system: a smarter outbox (one email per event, retries with back-off, linked to the order),
 | subscribers and unsubscribes, coupons, saved carts for abandoned-cart emails, product reviews and campaigns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_outbox', function (Blueprint $t) {
            $t->string('email_key', 60)->default('')->after('kind')->index();   // which email (order_paid, cart_1 …)
            $t->string('dedupe_key', 190)->nullable()->unique()->after('email_key'); // one email per event, ever
            $t->unsignedInteger('order_id')->nullable()->index()->after('dedupe_key');
            $t->char('lang', 2)->default('')->after('order_id');
            $t->json('headers')->nullable()->after('text_body');                 // List-Unsubscribe …
            $t->dateTime('next_attempt_at')->nullable()->index()->after('locked_until');
        });

        // Everyone we may send marketing to, and everyone who said no. Order emails are always sent.
        Schema::create('mail_subscribers', function (Blueprint $t) {
            $t->increments('id');
            $t->string('email', 190)->unique();
            $t->string('name', 120)->default('');
            $t->char('lang', 2)->default('');
            $t->char('country', 2)->default('');
            $t->string('source', 20)->default('');            // popup | checkout
            $t->dateTime('consent_at')->nullable();
            $t->string('consent_text', 300)->default('');
            $t->dateTime('unsubscribed_at')->nullable()->index();
            $t->timestamps();
        });

        Schema::create('coupons', function (Blueprint $t) {
            $t->increments('id');
            $t->string('code', 40)->unique();
            $t->string('title', 120)->default('');
            $t->string('title_ar', 120)->default('');
            $t->enum('type', ['percent', 'fixed', 'free_shipping'])->default('percent');
            $t->decimal('value', 12, 3)->default(0);             // percent, or BHD
            $t->decimal('min_subtotal', 12, 3)->nullable();
            $t->decimal('max_discount', 12, 3)->nullable();
            $t->dateTime('starts_at')->nullable();
            $t->dateTime('ends_at')->nullable()->index();
            $t->boolean('enabled')->default(true);
            $t->unsignedInteger('usage_limit')->nullable();
            $t->unsignedInteger('per_customer_limit')->nullable();
            $t->json('emails')->nullable();                      // only for these addresses
            $t->json('product_ids')->nullable();
            $t->json('category_ids')->nullable();
            $t->boolean('combinable')->default(false);
            $t->boolean('show_in_account')->default(false);
            $t->string('source', 20)->default('manual');         // manual | cart | popup | campaign
            $t->unsignedInteger('used_count')->default(0);
            $t->unsignedInteger('created_by')->nullable();
            $t->timestamps();
        });

        // A use is counted when the order is paid, and given back if that order is cancelled.
        Schema::create('coupon_uses', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('coupon_id')->index();
            $t->unsignedInteger('order_id')->unique();
            $t->string('email', 190)->default('')->index();
            $t->decimal('amount', 12, 3)->default(0);
            $t->enum('status', ['used', 'released'])->default('used');
            $t->timestamps();
            $t->foreign('coupon_id')->references('id')->on('coupons')->cascadeOnDelete();
        });

        Schema::table('orders', function (Blueprint $t) {
            $t->unsignedInteger('coupon_id')->nullable()->after('discount');
            $t->string('coupon_code', 40)->default('')->after('coupon_id');
            $t->dateTime('reminder_sent_at')->nullable()->after('alert_at');
            $t->dateTime('review_asked_at')->nullable()->after('reminder_sent_at');
            $t->dateTime('ready_at')->nullable()->after('shipped_at');
        });

        // Carts we know an email for, kept for the abandoned-cart emails. Also unpaid orders the system gave up on.
        Schema::create('carts', function (Blueprint $t) {
            $t->increments('id');
            $t->char('cart_key', 32)->nullable()->unique();     // the storefront's cart id
            $t->char('token', 40)->unique();                    // the "return to your cart" link
            $t->string('email', 190)->default('')->index();
            $t->string('name', 120)->default('');
            $t->char('lang', 2)->default('ar');
            $t->char('country', 2)->default('');
            $t->json('items')->nullable();                      // [{id, qty}]
            $t->decimal('value', 12, 3)->default(0);            // BHD
            $t->unsignedInteger('order_id')->nullable()->index(); // set: this is an unpaid order to win back
            $t->dateTime('last_activity_at')->nullable()->index();
            $t->unsignedTinyInteger('step')->default(0);        // emails sent so far (0–3)
            $t->dateTime('step1_at')->nullable();
            $t->dateTime('step2_at')->nullable();
            $t->dateTime('step3_at')->nullable();
            $t->unsignedInteger('coupon_id')->nullable();
            $t->unsignedInteger('recovered_order_id')->nullable()->index();
            $t->dateTime('stopped_at')->nullable()->index();
            $t->string('stop_reason', 40)->default('');
            $t->timestamps();
        });

        Schema::create('reviews', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('product_id')->index();
            $t->unsignedInteger('order_id')->nullable()->index();
            $t->string('name', 80)->default('');
            $t->string('email', 190)->default('');
            $t->unsignedTinyInteger('rating');
            $t->text('body')->nullable();
            $t->char('lang', 2)->default('ar');
            $t->enum('status', ['pending', 'approved', 'rejected'])->default('pending')->index();
            $t->unsignedInteger('approved_by')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->timestamps();
            $t->unique(['product_id', 'order_id']);
            $t->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });

        Schema::create('campaigns', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name', 120);
            $t->string('subject', 255)->default('');
            $t->string('subject_ar', 255)->default('');
            $t->text('body')->nullable();
            $t->text('body_ar')->nullable();
            $t->json('audience')->nullable();                   // {type: all|countries|list, countries[], emails[]}
            $t->enum('status', ['draft', 'sending', 'done'])->default('draft');
            $t->unsignedInteger('total')->default(0);
            $t->unsignedInteger('created_by')->nullable();
            $t->dateTime('started_at')->nullable();
            $t->dateTime('finished_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['campaigns', 'reviews', 'carts', 'coupon_uses', 'coupons', 'mail_subscribers'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['coupon_id', 'coupon_code', 'reminder_sent_at', 'review_asked_at', 'ready_at']));
        Schema::table('mail_outbox', function (Blueprint $t) {
            $t->dropUnique(['dedupe_key']);
            $t->dropColumn(['email_key', 'dedupe_key', 'order_id', 'lang', 'headers', 'next_attempt_at']);
        });
    }
};
