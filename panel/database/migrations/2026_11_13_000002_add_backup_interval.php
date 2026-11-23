<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backups more often than daily (E-103): every 24, 12, 6, 3 or 1 hours
 * from the backup time on, with hourly retention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->unsignedTinyInteger('backup_interval')->default(24)->after('backup_time');
            $table->unsignedSmallInteger('backup_keep_hourly')->default(24)->after('backup_interval');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['backup_interval', 'backup_keep_hourly']);
        });
    }
};
