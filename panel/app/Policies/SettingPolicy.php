<?php

namespace App\Policies;

use App\Models\User;

/**
 * Only administrators change the settings of the panel (E-091).
 */
class SettingPolicy
{
    public function manage(User $user): bool
    {
        return $user->isAdmin();
    }
}
