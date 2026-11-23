<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/**
 * Panel accounts (E-081). Only administrators manage them; customer
 * accounts follow with the customer area (milestone 7).
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Not one's own account, and never the last administrator with
     * two-factor authentication: it is the emergency access
     * (docs/SICHERHEIT.md, "Anmeldung am Panel", rule 8).
     */
    public function delete(User $user, User $target): bool
    {
        if (! $user->isAdmin() || $user->is($target)) {
            return false;
        }

        return ! $target->isAdmin() || ! $target->hasConfirmedTwoFactor() || User::where('role', Role::Admin)
            ->whereNotNull('two_factor_confirmed_at')
            ->whereKeyNot($target->getKey())
            ->exists();
    }

    /**
     * Pocket ID on or off: administrators may always use it, so the switch
     * exists for customer accounts only (E-114).
     */
    public function manageOidc(User $user, User $target): bool
    {
        return $user->isAdmin() && ! $target->isAdmin();
    }
}
