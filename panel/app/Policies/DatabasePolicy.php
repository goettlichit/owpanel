<?php

namespace App\Policies;

use App\Models\Database;
use App\Models\Site;
use App\Models\User;

/**
 * Databases belong to a website. Administrators may do everything,
 * customers the same with the databases of their own websites (E-124,
 * E-144), checked here centrally. Limits of the package are checked in the
 * action (App\Customers\PackageLimits).
 */
class DatabasePolicy
{
    private static function own(User $user, Database $database): bool
    {
        return $database->site !== null && SitePolicy::owns($user, $database->site);
    }

    public function viewAny(User $user, Site $site): bool
    {
        return $user->isAdmin() || SitePolicy::owns($user, $site);
    }

    public function view(User $user, Database $database): bool
    {
        return $user->isAdmin() || self::own($user, $database);
    }

    public function create(User $user, Site $site): bool
    {
        return $user->isAdmin() || SitePolicy::owns($user, $site);
    }

    /**
     * Show the password in plain text.
     */
    public function revealPassword(User $user, Database $database): bool
    {
        return $user->isAdmin() || self::own($user, $database);
    }

    public function changePassword(User $user, Database $database): bool
    {
        return $user->isAdmin() || self::own($user, $database);
    }

    /**
     * Run db-create again after it failed: administrators only, the task
     * log tells why it failed.
     */
    public function retry(User $user, Database $database): bool
    {
        return $user->isAdmin();
    }

    /**
     * Use the database tool: tables, rows and the SQL console (E-079).
     * Everything runs as the database user, so it reaches only this
     * database.
     */
    public function query(User $user, Database $database): bool
    {
        return $user->isAdmin() || self::own($user, $database);
    }

    /**
     * Download a dump of the database (E-082).
     */
    public function export(User $user, Database $database): bool
    {
        return $user->isAdmin() || self::own($user, $database);
    }

    /**
     * Import a dump; it can overwrite tables (E-082).
     */
    public function import(User $user, Database $database): bool
    {
        return $user->isAdmin() || self::own($user, $database);
    }

    public function delete(User $user, Database $database): bool
    {
        return $user->isAdmin() || self::own($user, $database);
    }
}
