<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * restic backups to a Hetzner Storage Box (E-096): one repository per
 * server with its own password, stored encrypted; a switch per website.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->text('backup_repo_password')->nullable();
            $table->boolean('backup_ready')->default(false);
            $table->string('backup_public_key')->nullable();
        });
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('backup_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('backup_enabled');
        });
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['backup_repo_password', 'backup_ready', 'backup_public_key']);
        });
    }
};
