<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHP settings per website and their limits in packages (E-173). Website
 * values are null until changed, which means the defaults of the panel.
 * Existing packages get the same limits a new package starts with, so no
 * customer can raise memory or run time beyond them unnoticed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->unsignedSmallInteger('php_memory_mb')->nullable();
            $table->unsignedSmallInteger('php_upload_mb')->nullable();
            $table->unsignedSmallInteger('php_time_s')->nullable();
            $table->boolean('php_display_errors')->default(false);
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->unsignedSmallInteger('php_memory_mb')->nullable();
            $table->unsignedSmallInteger('php_upload_mb')->nullable();
            $table->unsignedSmallInteger('php_time_s')->nullable();
            $table->unsignedSmallInteger('php_processes')->nullable();
        });

        DB::table('packages')->update([
            'php_memory_mb' => 512,
            'php_upload_mb' => 128,
            'php_time_s' => 300,
            'php_processes' => 10,
        ]);
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['php_memory_mb', 'php_upload_mb', 'php_time_s', 'php_display_errors']);
        });
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn(['php_memory_mb', 'php_upload_mb', 'php_time_s', 'php_processes']);
        });
    }
};
