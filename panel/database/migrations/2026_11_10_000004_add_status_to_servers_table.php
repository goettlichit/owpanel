<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * State and load of servers (E-075). The panel runs server-status once a
 * minute: the latest answer goes into "servers", each measurement into
 * "server_metrics", which keeps seven days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // Reported by the server, e.g. web1.example.de.
            $table->string('hostname', 253)->nullable()->after('name');
            $table->string('os_name', 80)->nullable();
            $table->string('kernel', 64)->nullable();
            $table->unsignedSmallInteger('cpu_cores')->nullable();
            $table->timestamp('booted_at')->nullable();
            $table->boolean('reboot_required')->default(false);
            // Last attempt, last successful answer and the error of the last
            // attempt, if it failed.
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('check_error', 50)->nullable();
        });

        Schema::create('server_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('server_id')->constrained()->cascadeOnDelete();
            $table->timestamp('recorded_at');
            $table->decimal('cpu_percent', 4, 1);
            $table->decimal('load1', 7, 2);
            $table->decimal('load5', 7, 2);
            $table->decimal('load15', 7, 2);
            $table->unsignedBigInteger('memory_total');
            $table->unsignedBigInteger('memory_used');
            $table->unsignedBigInteger('swap_total');
            $table->unsignedBigInteger('swap_used');
            $table->unsignedBigInteger('disk_total');
            $table->unsignedBigInteger('disk_used');
            // Byte counters since boot; rates are computed between two rows.
            $table->unsignedBigInteger('network_rx');
            $table->unsignedBigInteger('network_tx');

            $table->index(['server_id', 'recorded_at']);
            $table->index('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_metrics');

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn([
                'hostname', 'os_name', 'kernel', 'cpu_cores', 'booted_at', 'reboot_required',
                'checked_at', 'last_seen_at', 'check_error',
            ]);
        });
    }
};
