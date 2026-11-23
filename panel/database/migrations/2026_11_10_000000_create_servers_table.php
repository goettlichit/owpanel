<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Managed servers. A standalone installation has exactly one, the panel's own
 * server, reached over SSH on 127.0.0.1 like any other (docs/ARCHITEKTUR.md,
 * "Standalone und Multi-Server"). The host key is fixed when the server is
 * added; a connection with another key is refused (E-051).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 100);
            $table->string('host', 253);
            $table->unsignedSmallInteger('port')->default(22);
            // OpenSSH format without comment: "ssh-ed25519 AAAA…".
            $table->string('host_key', 255);
            $table->timestamps();

            $table->unique(['host', 'port']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
