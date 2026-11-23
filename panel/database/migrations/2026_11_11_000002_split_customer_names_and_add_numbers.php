<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customers get first name, last name and an optional company instead of
 * one name, and a customer number given by the panel, e.g. K10001 (E-085).
 * The number of the homepage stays in external_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedInteger('number')->nullable()->unique()->after('id');
            $table->string('first_name')->default('')->after('number');
            $table->string('last_name')->default('')->after('first_name');
            $table->string('company')->nullable()->after('last_name');
        });

        // Existing customers keep their name as last name, in the order
        // they were created.
        $next = 10001;
        foreach (DB::table('customers')->orderBy('created_at')->orderBy('id')->get(['id', 'name']) as $customer) {
            DB::table('customers')->where('id', $customer->id)->update(['number' => $next++, 'last_name' => $customer->name]);
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('name')->default('');
        });
        foreach (DB::table('customers')->get(['id', 'first_name', 'last_name', 'company']) as $customer) {
            DB::table('customers')->where('id', $customer->id)
                ->update(['name' => $customer->company ?: trim($customer->first_name.' '.$customer->last_name)]);
        }
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['number']);
            $table->dropColumn(['number', 'first_name', 'last_name', 'company']);
        });
    }
};
