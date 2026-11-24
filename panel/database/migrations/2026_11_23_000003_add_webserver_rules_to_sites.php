<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Own nginx rules of a website (E-184): extra response headers as a list of
 * name and value, blocked paths as a list of strings. Null means none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->json('nginx_headers')->nullable();
            $table->json('nginx_blocked_paths')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['nginx_headers', 'nginx_blocked_paths']);
        });
    }
};
