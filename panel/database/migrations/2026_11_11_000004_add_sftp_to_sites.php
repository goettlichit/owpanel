<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SFTP access of the websites (E-089). The password is stored encrypted, so
 * the panel can show it again; the keys are public keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('sftp_enabled')->default(false);
            $table->text('sftp_password')->nullable();
        });

        Schema::create('sftp_keys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('public_key');
            $table->string('fingerprint', 100);
            $table->timestamps();
            $table->unique(['site_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sftp_keys');
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['sftp_enabled', 'sftp_password']);
        });
    }
};
