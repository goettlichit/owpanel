<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blocklists of a server's firewall (E-170). The entries themselves are
 * cached in storage/app/private/blocklists, not in the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocklists', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('server_id')->constrained()->cascadeOnDelete();
            $table->string('url', 500);
            $table->unsignedTinyInteger('interval_hours');
            $table->unsignedInteger('entries')->default(0);
            $table->unsignedInteger('ignored')->default(0);
            $table->char('content_hash', 64)->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('changed_at')->nullable();
            $table->string('error', 32)->nullable();
            $table->unsignedSmallInteger('failures')->default(0);
            $table->timestamps();
            $table->unique(['server_id', 'url']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocklists');
    }
};
