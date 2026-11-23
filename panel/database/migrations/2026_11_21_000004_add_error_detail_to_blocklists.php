<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What exactly went wrong when a blocklist could not be fetched (E-170),
 * such as the curl error or the HTTP status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blocklists', function (Blueprint $table) {
            $table->string('error_detail', 160)->nullable()->after('error');
        });
    }

    public function down(): void
    {
        Schema::table('blocklists', function (Blueprint $table) {
            $table->dropColumn('error_detail');
        });
    }
};
