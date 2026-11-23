<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last push deliveries per device with the answer of the push service
 * (E-147), so a late message can be told apart from one the panel never
 * sent. Only the title of the message, never its body or the endpoint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('push_subscription_id')->constrained()->cascadeOnDelete();
            // HTTP status of the push service; null when it was not reached.
            $table->unsignedSmallInteger('status')->nullable();
            $table->string('title', 150);
            $table->timestamp('created_at');
            $table->index(['push_subscription_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_deliveries');
    }
};
