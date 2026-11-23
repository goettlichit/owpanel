<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DNS records the panel set for a website (E-116): only these may be
 * removed together with the website later; records someone else set
 * always stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_dns_records', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('zone_id', 64);
            $table->string('zone_name', 253);
            // Name inside the zone ("@" for the apex) and type (A or AAAA).
            $table->string('name', 253);
            $table->string('type', 8);
            // The value the panel set; when it differs later, someone else
            // changed the record and it is no longer the panel's.
            $table->string('value', 64);
            $table->unsignedInteger('ttl');
            $table->timestamps();

            $table->unique(['site_id', 'name', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_dns_records');
    }
};
