<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Counters per network card (E-111), e.g. the public interface and a
 * Hetzner private network: {"eth0": {"rx": …, "tx": …, "public": true}}.
 * Null for older measurements, which only have the total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_metrics', function (Blueprint $table) {
            $table->json('network_interfaces')->nullable()->after('network_tx');
        });
    }

    public function down(): void
    {
        Schema::table('server_metrics', function (Blueprint $table) {
            $table->dropColumn('network_interfaces');
        });
    }
};
