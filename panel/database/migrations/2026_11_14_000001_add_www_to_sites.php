<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A website can also answer to www.<domain> and send it to its main
 * address (E-110). "www_tls" says whether the certificate covers that name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('www')->default(false)->after('tls');
            $table->boolean('www_tls')->default(false)->after('www');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['www', 'www_tls']);
        });
    }
};
