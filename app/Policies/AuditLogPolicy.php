<?php

namespace App\Policies;

use App\Models\User;

/** The record of who did what is for owners and admins to read. Nobody can change it. */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isTenantAdmin();
    }
}
