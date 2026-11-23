<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An optional name for each blocklist (E-170); without one the panel shows
 * the address of the list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blocklists', function (Blueprint $table) {
            $table->string('name', 60)->nullable()->after('server_id');
        });
    }

    public function down(): void
    {
        Schema::table('blocklists', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
