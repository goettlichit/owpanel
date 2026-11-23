<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storage per website and quotas (E-126): what site-usage measured, the
 * hard limit the panel last set, and whether a server has user quotas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->unsignedBigInteger('usage_files_bytes')->nullable();
            $table->unsignedBigInteger('usage_db_bytes')->nullable();
            $table->timestamp('usage_measured_at')->nullable();
            // Hard limit for the files in KiB as set on the server; 0 none.
            $table->unsignedBigInteger('quota_kib')->default(0);
        });
        Schema::table('servers', function (Blueprint $table) {
            // null: not measured yet.
            $table->boolean('quota_active')->nullable();
            $table->string('storage_filesystem', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['usage_files_bytes', 'usage_db_bytes', 'usage_measured_at', 'quota_kib']);
        });
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['quota_active', 'storage_filesystem']);
        });
    }
};
