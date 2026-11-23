<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server tasks (E-052): one run of an approved server script with status,
 * log and result. The queue worker fills in the log while the script runs.
 * The user is kept as a plain ID like in the audit log, so tasks survive
 * when the user is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_tasks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('server_id')->constrained()->restrictOnDelete();
            $table->ulid('user_id')->nullable()->index();
            $table->string('script', 50);
            $table->json('arguments');
            $table->string('status', 20)->index();
            $table->longText('output')->nullable();
            // Last output line of the script as JSON (docs/ARCHITEKTUR.md,
            // "Schnittstelle der Serverskripte").
            $table->json('result')->nullable();
            $table->integer('exit_code')->nullable();
            // Error code from the script or the panel, translated in the interface.
            $table->string('error', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_tasks');
    }
};
