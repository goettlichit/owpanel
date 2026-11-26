<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The panel's own server is marked by a column instead of its address
 * 127.0.0.1 (M5-2, E-192). The installer added it with that address, so
 * the oldest such server gets the mark, as Server::panelHost() chose it
 * before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->boolean('hosts_panel')->default(false)->after('host');
        });

        $id = DB::table('servers')->whereIn('host', ['127.0.0.1', '::1', 'localhost'])->orderBy('created_at')->value('id');
        if ($id !== null) {
            DB::table('servers')->where('id', $id)->update(['hosts_panel' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('hosts_panel');
        });
    }
};
