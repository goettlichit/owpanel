<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Packages with limits for a whole customer (E-125). Every limit is
 * nullable: null means unlimited. A customer has at most one package; a
 * package in use cannot be deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name')->unique();
            $table->unsignedInteger('max_sites')->nullable();
            $table->unsignedInteger('storage_mb')->nullable();
            $table->unsignedInteger('max_databases')->nullable();
            $table->unsignedInteger('max_cron_jobs')->nullable();
            $table->unsignedSmallInteger('min_cron_interval')->nullable();
            $table->unsignedSmallInteger('daily_self_service')->nullable();
            $table->timestamps();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->foreignUlid('package_id')->nullable()->after('locale')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('package_id');
        });
        Schema::dropIfExists('packages');
    }
};
