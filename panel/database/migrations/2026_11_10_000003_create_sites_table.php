<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Websites (E-074). Each site has its own Linux user "ow" plus a random
 * number, unique on all servers (E-034). Domain and user are unique across
 * the panel, so a site can later move to another server without conflicts.
 * Server tasks point to their site, so a site shows what happened to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('server_id')->constrained()->restrictOnDelete();
            // Sites without a customer belong to the operator (docs/ARCHITEKTUR.md).
            $table->foreignUlid('customer_id')->nullable()->constrained()->restrictOnDelete();
            // Stored in ASCII, internationalised domains as punycode (xn--…).
            $table->string('domain', 253)->unique();
            $table->string('type', 20);
            $table->string('system_user', 10)->unique();
            // Reported by site-create; null until the first run succeeded.
            $table->string('php_version', 10)->nullable();
            // Whether a certificate was requested, used again on a retry.
            $table->boolean('wants_tls')->default(true);
            // Whether the site runs on HTTPS with HTTP/3, as reported by site-create.
            $table->boolean('tls')->default(false);
            $table->string('status', 20)->index();
            $table->timestamps();
        });

        Schema::table('server_tasks', function (Blueprint $table) {
            // Kept when the site is deleted; the task log stays readable.
            $table->foreignUlid('site_id')->nullable()->after('server_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('server_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('site_id');
        });

        Schema::dropIfExists('sites');
    }
};
