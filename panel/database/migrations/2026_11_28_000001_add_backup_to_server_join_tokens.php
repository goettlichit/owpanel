<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a server that joins with this code gets the backup target of the
 * panel server (E-199).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_join_tokens', function (Blueprint $table) {
            $table->boolean('backup')->default(false)->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('server_join_tokens', function (Blueprint $table) {
            $table->dropColumn('backup');
        });
    }
};
