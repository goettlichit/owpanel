<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * File of a database export or import (E-082): the name below the
 * transfer directory, e.g. 01jc….sql.gz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_tasks', function (Blueprint $table) {
            $table->string('transfer_file', 64)->nullable()->after('arguments');
        });
    }

    public function down(): void
    {
        Schema::table('server_tasks', function (Blueprint $table) {
            $table->dropColumn('transfer_file');
        });
    }
};
