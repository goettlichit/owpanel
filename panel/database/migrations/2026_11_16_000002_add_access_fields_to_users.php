<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Access of a customer to the customer area (E-128): a lock that refuses
 * every login (E-127) and an invitation link that sets the first password.
 * The invitation token is stored as SHA-256 only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('locked_at')->nullable()->after('oidc_allowed');
            $table->string('invitation_token', 64)->nullable()->after('locked_at');
            $table->timestamp('invitation_expires_at')->nullable()->after('invitation_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['locked_at', 'invitation_token', 'invitation_expires_at']);
        });
    }
};
