<?php

namespace App\Policies;

use App\Models\ServerTask;
use App\Models\User;

/**
 * Server tasks show server names, scripts and raw logs, so only
 * administrators see them. Customers will get their own view of what
 * happens with their websites in milestone 7.
 */
class ServerTaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, ServerTask $task): bool
    {
        return $user->isAdmin();
    }
}
