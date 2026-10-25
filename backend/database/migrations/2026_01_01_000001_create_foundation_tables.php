<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Phase 1 – foundation: settings, staff accounts, sessions, one-time tokens,
 | activity log, translations, the email outbox and the cache tables (rate limits).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $t) {
            $t->string('k', 80)->primary();
            $t->json('v');
            $t->dateTime('updated_at')->nullable();
        });

        Schema::create('admins', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name', 120);
            $t->string('email', 190)->unique();
            $t->string('password_hash', 255);
            $t->enum('role', ['owner', 'manager', 'staff', 'order_manager', 'order_assistant'])->default('staff');
            $t->boolean('active')->default(true);
            $t->boolean('order_emails')->default(true); // owners: new-order and order-update emails
            $t->dateTime('last_login_at')->nullable();
            $t->timestamps();
        });

        // One table for customer and admin sessions. Only the SHA-256 of the cookie token is stored.
        Schema::create('sessions', function (Blueprint $t) {
            $t->char('id', 64)->primary();
            $t->enum('subject_type', ['user', 'admin']);
            $t->unsignedInteger('subject_id');
            $t->string('ip', 45)->default('');
            $t->string('user_agent', 255)->default('');
            $t->dateTime('created_at');
            $t->dateTime('last_seen_at');
            $t->dateTime('expires_at')->index();
            $t->index(['subject_type', 'subject_id']);
        });

        // Admin one-time tokens (password reset). Raw token only in the email.
        Schema::create('admin_tokens', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('admin_id')->index();
            $t->string('kind', 20)->default('reset');
            $t->char('token_hash', 64)->unique();
            $t->dateTime('expires_at');
            $t->dateTime('used_at')->nullable();
            $t->dateTime('created_at');
        });

        Schema::create('audit_log', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedInteger('admin_id')->nullable()->index();
            $t->string('admin_name', 120)->default('');
            $t->string('action', 60)->index();
            $t->string('entity', 60)->default('');
            $t->string('entity_id', 60)->default('');
            $t->json('details')->nullable();
            $t->string('ip', 45)->default('');
            $t->dateTime('created_at')->index();
        });

        // Admin edits to built-in wording; they win over the generated catalogue.
        Schema::create('translations', function (Blueprint $t) {
            $t->increments('id');
            $t->enum('scope', ['site', 'admin']);
            $t->char('hash', 40);
            $t->text('source');
            $t->text('ar')->nullable();
            $t->text('en')->nullable();
            $t->boolean('keep')->default(false);
            $t->boolean('reported')->default(false);
            $t->unsignedInteger('seen')->default(0);
            $t->string('page', 255)->default('');
            $t->unsignedInteger('updated_by')->nullable();
            $t->timestamps();
            $t->unique(['scope', 'hash']);
        });

        Schema::create('mail_outbox', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('kind', 40)->default('transactional'); // transactional | marketing | staff
            $t->string('to_email', 190)->index();
            $t->string('to_name', 190)->default('');
            $t->string('reply_to', 190)->default('');
            $t->string('subject', 255);
            $t->mediumText('html');
            $t->mediumText('text_body')->nullable();
            $t->enum('status', ['queued', 'sending', 'sent', 'failed', 'skipped'])->default('queued')->index();
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->dateTime('locked_until')->nullable();
            $t->string('last_error', 500)->default('');
            $t->unsignedInteger('campaign_id')->nullable()->index();
            $t->dateTime('created_at')->index();
            $t->dateTime('sent_at')->nullable();
        });

        // Framework cache (rate-limit counters, tokens).
        Schema::create('cache', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->mediumText('value');
            $t->integer('expiration');
        });
        Schema::create('cache_locks', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->string('owner');
            $t->integer('expiration');
        });
    }

    public function down(): void
    {
        foreach (['cache_locks', 'cache', 'mail_outbox', 'translations', 'audit_log', 'admin_tokens', 'sessions', 'admins', 'settings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
