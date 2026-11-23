<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visitors and page views per website and day, counted on the server from
 * the access log (E-148). Only numbers and the most visited paths; no IP
 * addresses. Kept 14 months.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('visitors')->default(0);
            $table->unsignedInteger('pageviews')->default(0);
            // [[path, views], …], the ten most visited of the day.
            $table->json('pages');
            $table->timestamps();
            $table->unique(['site_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_visits');
    }
};
