<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One subaccount of the Storage Box per server (E-097): which box, which
 * subaccount, and its login, so the server only reaches its own directory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->unsignedBigInteger('backup_box_id')->nullable();
            $table->unsignedBigInteger('backup_subaccount_id')->nullable();
            $table->string('backup_host')->nullable();
            $table->string('backup_account', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['backup_box_id', 'backup_subaccount_id', 'backup_host', 'backup_account']);
        });
    }
};
