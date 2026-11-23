<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Own firewall rules and fail2ban exceptions per server (E-090). The panel
 * holds the lists and sends them as a whole to the server.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firewall_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('server_id')->constrained()->cascadeOnDelete();
            $table->string('protocol', 4);
            $table->string('ports', 11);
            $table->string('source', 50)->nullable();
            $table->string('comment')->nullable();
            $table->timestamps();
        });

        Schema::create('fail2ban_ignores', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('server_id')->constrained()->cascadeOnDelete();
            $table->string('address', 50);
            $table->string('comment')->nullable();
            $table->timestamps();
            $table->unique(['server_id', 'address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fail2ban_ignores');
        Schema::dropIfExists('firewall_rules');
    }
};
