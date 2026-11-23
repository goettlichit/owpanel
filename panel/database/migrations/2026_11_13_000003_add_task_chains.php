<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rebuilding websites from their backups (E-106): a task can wait for the
 * one before it and starts only when that one succeeded. The status check
 * reports which websites exist on a server, so the panel can tell those
 * it knows but the server no longer has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_tasks', function (Blueprint $table) {
            $table->ulid('after_task_id')->nullable()->after('database_id')->index();
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->json('present_sites')->nullable()->after('reboot_required');
        });
    }

    public function down(): void
    {
        Schema::table('server_tasks', function (Blueprint $table) {
            $table->dropIndex(['after_task_id']);
            $table->dropColumn('after_task_id');
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('present_sites');
        });
    }
};
