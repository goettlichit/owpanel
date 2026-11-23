<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links between panel accounts and accounts at Pocket ID or another OpenID
 * Connect provider (E-113, E-114). A person is found by issuer and "sub",
 * never by e-mail address; one link per account and issuer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oidc_identities', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('issuer', 255);
            $table->string('subject', 255);
            // Shown in the account so a person sees which login is linked.
            $table->string('email', 255)->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->unique(['issuer', 'subject']);
            $table->unique(['user_id', 'issuer']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oidc_identities');
    }
};
