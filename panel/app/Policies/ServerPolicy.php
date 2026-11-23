<?php

namespace App\Policies;

use App\Models\Server;
use App\Models\User;

/**
 * Servers are for administrators only. Customers never see servers
 * (docs/SICHERHEIT.md, "Kundenbereich", rule 4).
 */
class ServerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Server $server): bool
    {
        return $user->isAdmin();
    }

    /**
     * Start the harmless test script (server/bin/panel-test).
     */
    public function runTest(User $user, Server $server): bool
    {
        return $user->isAdmin();
    }

    /**
     * Ask for an update check at the next minute.
     */
    public function checkUpdates(User $user, Server $server): bool
    {
        return $user->isAdmin();
    }

    /**
     * Restart the server (server/bin/server-reboot, E-076).
     */
    /**
     * SSH password login of the server (E-155).
     */
    public function manageSsh(User $user, Server $server): bool
    {
        return $user->isAdmin();
    }

    /**
     * Install all pending updates (E-154).
     */
    public function upgrade(User $user, Server $server): bool
    {
        return $user->isAdmin();
    }

    public function reboot(User $user, Server $server): bool
    {
        return $user->isAdmin();
    }

    /**
     * Own firewall rules and fail2ban (E-090).
     */
    public function manageFirewall(User $user, Server $server): bool
    {
        return $user->isAdmin();
    }

    /**
     * Hetzner snapshots of the server (E-095).
     */
    /**
     * DNS of the server's host name and reverse DNS (E-153).
     */
    public function manageDns(User $user, Server $server): bool
    {
        return $user->isAdmin();
    }

    public function manageSnapshots(User $user, Server $server): bool
    {
        return $user->isAdmin();
    }

    /**
     * Backups of the websites on the server (E-096), like each website's
     * own backups only for administrators.
     */
    public function manageBackups(User $user, Server $server): bool
    {
        return $user->isAdmin();
    }
}
