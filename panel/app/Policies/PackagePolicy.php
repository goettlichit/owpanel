<?php

namespace App\Policies;

use App\Models\Package;
use App\Models\User;

/**
 * Packages are managed by administrators (E-125). Customers see the limits
 * of their own package later in the customer area, never the list.
 */
class PackagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Package $package): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Package $package): bool
    {
        return $user->isAdmin();
    }

    /**
     * Only while no customer has the package, so no customer loses his
     * limits by accident.
     */
    public function delete(User $user, Package $package): bool
    {
        return $user->isAdmin() && ! $package->customers()->exists();
    }
}
