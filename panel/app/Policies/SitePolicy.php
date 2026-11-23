<?php

namespace App\Policies;

use App\Models\Site;
use App\Models\User;

/**
 * Administrators may do everything; customers what E-124 allows them, and
 * only with their own websites (E-074, E-143). The pages of the
 * administration additionally need role "admin" (route middleware), so a
 * customer never sees servers, Linux users or task logs there
 * (docs/SICHERHEIT.md, "Kundenbereich", rule 4).
 */
class SitePolicy
{
    /**
     * The website belongs to the customer the account belongs to.
     */
    public static function owns(User $user, Site $site): bool
    {
        return $user->isCustomer()
            && $user->customer_id !== null
            && $site->customer_id === $user->customer_id;
    }

    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Site $site): bool
    {
        return $user->isAdmin() || self::owns($user, $site);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Run site-create again after it failed.
     */
    public function retry(User $user, Site $site): bool
    {
        return $user->isAdmin();
    }

    /**
     * Request a certificate for a site that runs on HTTP only, and turn
     * www on or off (E-124).
     */
    public function requestCertificate(User $user, Site $site): bool
    {
        return $user->isAdmin() || self::owns($user, $site);
    }

    /**
     * Switch the PHP version (E-172): administrators and the customer of
     * the website.
     */
    public function changePhp(User $user, Site $site): bool
    {
        return $user->isAdmin() || self::owns($user, $site);
    }

    /**
     * Move the website to another domain (E-112).
     */
    public function changeDomain(User $user, Site $site): bool
    {
        return $user->isAdmin();
    }

    /**
     * Change which customer the website belongs to (E-084).
     */
    public function assignCustomer(User $user, Site $site): bool
    {
        return $user->isAdmin();
    }

    /**
     * Access and error log of the website (E-086, E-124).
     */
    public function viewLogs(User $user, Site $site): bool
    {
        return $user->isAdmin() || self::owns($user, $site);
    }

    /**
     * Tasks of the website with their logs: commands and paths of the
     * server, administrators only (E-124).
     */
    public function viewTasks(User $user, Site $site): bool
    {
        return $user->isAdmin();
    }

    /**
     * Backups of the website (E-096, E-124): see them, back up now and
     * restore. For customers the package limits the backups and restores
     * per day (E-145).
     */
    public function manageBackups(User $user, Site $site): bool
    {
        return $user->isAdmin() || self::owns($user, $site);
    }

    /**
     * Switch the automatic backup of the website on or off and rebuild a
     * website the server lacks (E-106): administrators only.
     */
    public function configureBackups(User $user, Site $site): bool
    {
        return $user->isAdmin();
    }

    /**
     * Cron jobs of the website (E-088, E-124). They run as the website's
     * user; the package limits are checked in the action (E-139).
     */
    public function manageCron(User $user, Site $site): bool
    {
        return $user->isAdmin() || self::owns($user, $site);
    }

    /**
     * SFTP access of the website (E-089, E-124).
     */
    public function manageSftp(User $user, Site $site): bool
    {
        return $user->isAdmin() || self::owns($user, $site);
    }

    /**
     * Check the DNS of the website against the addresses it needs (E-124).
     */
    public function checkDns(User $user, Site $site): bool
    {
        return $user->isAdmin() || self::owns($user, $site);
    }

    /**
     * Change records in a Hetzner zone (E-116): administrators only.
     */
    public function manageDns(User $user, Site $site): bool
    {
        return $user->isAdmin();
    }

    /**
     * Suspend and unsuspend a website (E-127): administrators only. The
     * API (milestone 7.1) lifts only the suspensions it set itself.
     */
    public function suspend(User $user, Site $site): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Site $site): bool
    {
        return $user->isAdmin();
    }
}
