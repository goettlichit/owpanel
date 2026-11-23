<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tasks started together, e.g. the scheduled backups of one run of a
 * server. The message "backup finished" covers exactly one batch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_tasks', function (Blueprint $table) {
            $table->ulid('batch')->nullable()->index()->after('after_task_id');
        });
    }

    public function down(): void
    {
        Schema::table('server_tasks', function (Blueprint $table) {
            $table->dropIndex(['batch']);
            $table->dropColumn('batch');
        });
    }
};
