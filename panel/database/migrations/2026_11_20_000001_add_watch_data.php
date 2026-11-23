<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data for the new notifications (E-160): the state of the services each
 * server reports, and the devices an administrator has signed in from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // {"nginx": true, "mariadb": false, ...}; null: not reported yet.
            $table->json('services')->nullable();
        });
        Schema::table('users', function (Blueprint $table) {
            // Hashes of the device cookies with label and last use.
            $table->json('known_devices')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('services');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('known_devices');
        });
    }
};
