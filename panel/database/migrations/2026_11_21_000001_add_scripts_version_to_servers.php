<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Version of the server scripts and fingerprints of the release keys each
 * server reports (E-167); null before the first answer of a server-status
 * that knows them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->string('scripts_version', 64)->nullable();
            $table->json('release_keys')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['scripts_version', 'release_keys']);
        });
    }
};
