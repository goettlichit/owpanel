<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public addresses of a server, shown in the panel (E-072). The panel
 * itself connects to "host", which is 127.0.0.1 for its own server.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->string('public_ipv4', 15)->nullable()->after('port');
            $table->string('public_ipv6', 45)->nullable()->after('public_ipv4');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['public_ipv4', 'public_ipv6']);
        });
    }
};
