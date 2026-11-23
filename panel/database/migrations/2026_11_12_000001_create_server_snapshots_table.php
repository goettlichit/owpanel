<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hetzner Cloud snapshots of the servers (E-095). The images live at
 * Hetzner; the panel keeps the link to the Hetzner server, the schedule and
 * a record of each run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->unsignedBigInteger('hetzner_server_id')->nullable();
            $table->boolean('snapshot_enabled')->default(false);
            $table->string('snapshot_time', 5)->default('03:30');
            $table->unsignedTinyInteger('snapshot_keep_daily')->default(7);
            $table->unsignedTinyInteger('snapshot_keep_weekly')->default(4);
        });

        Schema::create('server_snapshots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('server_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('trigger', 10);
            $table->string('status', 10)->default('pending');
            $table->unsignedBigInteger('hetzner_image_id')->nullable();
            $table->unsignedBigInteger('hetzner_action_id')->nullable();
            $table->string('error', 100)->nullable();
            $table->unsignedSmallInteger('deleted_count')->default(0);
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['server_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_snapshots');
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['hetzner_server_id', 'snapshot_enabled', 'snapshot_time', 'snapshot_keep_daily', 'snapshot_keep_weekly']);
        });
    }
};
