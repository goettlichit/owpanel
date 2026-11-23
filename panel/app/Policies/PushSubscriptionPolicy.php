<?php

namespace App\Policies;

use App\Models\PushSubscription;
use App\Models\User;

/**
 * Notifications are for administrators only (E-101); each one manages
 * only the own devices.
 */
class PushSubscriptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, PushSubscription $subscription): bool
    {
        return $user->isAdmin() && $subscription->user_id === $user->getKey();
    }
}
