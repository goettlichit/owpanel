<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backup settings per server, like the snapshot settings (E-100): Storage
 * Box, directory, access from outside, time and retention. Values set so
 * far for all servers are copied to each server, then dropped.
 */
return new class extends Migration
{
    private const KEYS = ['backup_box_id', 'backup_host', 'backup_account', 'backup_path', 'backup_external',
        'backup_time', 'backup_keep_daily', 'backup_keep_weekly', 'backup_keep_monthly'];

    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->string('backup_box_name')->nullable();
            $table->string('backup_path', 100)->default('owpanel');
            $table->boolean('backup_external')->default(false);
            $table->string('backup_time', 5)->default('02:30');
            $table->unsignedTinyInteger('backup_keep_daily')->default(7);
            $table->unsignedTinyInteger('backup_keep_weekly')->default(4);
            $table->unsignedTinyInteger('backup_keep_monthly')->default(6);
        });

        $old = DB::table('settings')->whereIn('key', self::KEYS)->pluck('value', 'key');
        $values = array_filter([
            'backup_path' => $old['backup_path'] ?? null,
            'backup_external' => isset($old['backup_external']) ? $old['backup_external'] === '1' : null,
            'backup_time' => $old['backup_time'] ?? null,
            'backup_keep_daily' => isset($old['backup_keep_daily']) ? (int) $old['backup_keep_daily'] : null,
            'backup_keep_weekly' => isset($old['backup_keep_weekly']) ? (int) $old['backup_keep_weekly'] : null,
            'backup_keep_monthly' => isset($old['backup_keep_monthly']) ? (int) $old['backup_keep_monthly'] : null,
        ], fn ($value) => $value !== null);
        if (isset($old['backup_box_id'])) {
            // Every server used the one box so far; its login host goes along.
            $values['backup_box_id'] = (int) $old['backup_box_id'];
            $values['backup_host'] = $old['backup_host'] ?? null;
        } else {
            $values['backup_box_id'] = null;
            $values['backup_ready'] = false;
        }
        DB::table('servers')->update($values);
        DB::table('settings')->whereIn('key', self::KEYS)->delete();
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['backup_box_name', 'backup_path', 'backup_external', 'backup_time',
                'backup_keep_daily', 'backup_keep_weekly', 'backup_keep_monthly']);
        });
    }
};
