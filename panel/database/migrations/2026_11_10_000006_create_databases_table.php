<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MariaDB databases of websites (E-078). A database and its user share one
 * name, e.g. ow38217_shop. The password is stored encrypted with the app
 * key, so the panel can show it again and later sign in to phpMyAdmin.
 * Deleting a website deletes its databases on the server (site-delete) and
 * here (cascade).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('databases', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('server_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('name', 24)->unique();
            // Encrypted (Laravel "encrypted" cast), never in logs or task arguments.
            $table->text('password');
            // A new password until db-create confirmed it; the old one stays valid.
            $table->text('new_password')->nullable();
            $table->string('status', 20)->index();
            $table->timestamps();
        });

        Schema::table('server_tasks', function (Blueprint $table) {
            $table->foreignUlid('database_id')->nullable()->after('site_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('server_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('database_id');
        });

        Schema::dropIfExists('databases');
    }
};
