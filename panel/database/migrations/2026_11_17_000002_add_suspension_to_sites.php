<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suspension of a website (E-127): in own columns, not in the status, so
 * tasks and state of the website stay as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable();
            // "admin" or "api"; the API lifts only its own suspensions.
            $table->string('suspended_by', 10)->nullable();
            // Internal, for administrators only.
            $table->text('suspend_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['suspended_at', 'suspended_by', 'suspend_note']);
        });
    }
};
