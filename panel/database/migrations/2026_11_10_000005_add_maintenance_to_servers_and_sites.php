<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Updates and certificates (E-076). The panel runs server-updates and
 * site-certificates about once an hour and keeps the last answer: the
 * update state as JSON on the server, the certificate expiry per site.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // Last answer of server-updates: pending packages, last runs,
            // automatic updates, reboot details.
            $table->json('updates')->nullable();
            // Null means: check at the next opportunity.
            $table->timestamp('updates_checked_at')->nullable();
            $table->string('updates_error', 50)->nullable();
            // Whether certbot.timer renews the certificates.
            $table->boolean('certificate_renewal')->nullable();
            // Certificate of the panel itself (ow10000), on its own server.
            $table->timestamp('panel_certificate_expires_at')->nullable();
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->timestamp('certificate_expires_at')->nullable()->after('tls');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('certificate_expires_at');
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['updates', 'updates_checked_at', 'updates_error', 'certificate_renewal', 'panel_certificate_expires_at']);
        });
    }
};
