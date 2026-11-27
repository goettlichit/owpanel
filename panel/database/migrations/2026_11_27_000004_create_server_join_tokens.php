<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time codes for "Add server" (E-166, E-196) and the private address of
 * each server, over which the panel steers it and the gateway reaches its
 * backend.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_join_tokens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // SHA-256 of the code; the code itself is shown once and never stored.
            $table->char('token_hash', 64)->unique();
            $table->string('name', 100);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->foreignUlid('server_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->string('private_ipv4', 15)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('private_ipv4');
        });
        Schema::dropIfExists('server_join_tokens');
    }
};
