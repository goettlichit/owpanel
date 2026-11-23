<?php

namespace App\Policies;

use App\Models\User;

/**
 * Only administrators see the audit log. Customers see neither other
 * customers nor panel internals (docs/SICHERHEIT.md, "Kundenbereich").
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }
}
