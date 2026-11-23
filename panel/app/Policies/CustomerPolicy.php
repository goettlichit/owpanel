<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

/**
 * Customers are managed by administrators (E-084). Customers never see
 * other customers (docs/SICHERHEIT.md, "Kundenbereich", rule 4).
 */
class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->isAdmin();
    }

    /**
     * Only without websites and accounts; those are reassigned or deleted
     * first, so nothing is left without an owner by accident.
     */
    /**
     * One access per customer (E-128).
     */
    public function createAccess(User $user, Customer $customer): bool
    {
        return $user->isAdmin() && ! $customer->users()->exists();
    }

    public function manageAccess(User $user, Customer $customer): bool
    {
        return $user->isAdmin() && $customer->users()->exists();
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $user->isAdmin() && ! $customer->sites()->exists() && ! $customer->users()->exists();
    }
}
