<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications by mail and push (E-101). Each administrator chooses the
 * events and channels and may set quiet hours; messages from the quiet
 * hours wait and are sent together when they end. An alert is sent once
 * when a problem appears and once when it is gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_prefs')->nullable();
            $table->boolean('quiet_enabled')->default(false);
            $table->string('quiet_from', 5)->default('22:00');
            $table->string('quiet_until', 5)->default('07:00');
        });

        // Browsers that receive push messages, one row per device.
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            $table->string('public_key', 100);
            $table->string('auth', 50);
            $table->string('label', 100);
            $table->timestamp('last_success_at')->nullable();
            $table->unsignedSmallInteger('failures')->default(0);
            $table->timestamps();
        });

        // Open and resolved problems, one row per problem and object.
        Schema::create('alerts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('key', 100)->unique();
            $table->string('event', 30);
            $table->json('data')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        // Messages held back during quiet hours.
        Schema::create('pending_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 10);
            $table->json('message');
            $table->timestamp('created_at');
            $table->index(['user_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_notifications');
        Schema::dropIfExists('alerts');
        Schema::dropIfExists('push_subscriptions');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['notification_prefs', 'quiet_enabled', 'quiet_from', 'quiet_until']);
        });
    }
};
