<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who a website belongs to (E-139): a customer (customer_id), the
 * administration (admin_owned) or nobody yet. Existing websites without
 * customer stay unassigned until an administrator decides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('admin_owned')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('admin_owned');
        });
    }
};
