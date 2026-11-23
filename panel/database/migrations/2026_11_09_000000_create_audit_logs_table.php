<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log (E-053). Entries are never changed. User and customer are kept
 * as plain IDs without foreign keys, so entries survive when a user or
 * customer is deleted (docs/SICHERHEIT.md, "Kundenbereich", rule 7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestamp('created_at')->index();
            $table->ulid('user_id')->nullable()->index();
            $table->ulid('customer_id')->nullable()->index();
            $table->string('action', 100)->index();
            $table->string('subject_type', 100)->nullable();
            $table->string('subject_id', 26)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('context')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
