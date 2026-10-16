<?php

namespace App\Policies;

use App\Domain\Tenancy\Role;
use App\Models\User;

/**
 * Who may manage whose account.
 *
 *  - Owners and admins manage the people of their own business.
 *  - Only an owner may create, edit or switch off an owner or an admin.
 *  - Nobody manages themselves here. Own name and password live on the Profile page,
 *    which also means an admin can never lock themselves out or demote themselves.
 *  - Other businesses are never reachable. The controller returns 404 for them.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isTenantAdmin();
    }

    public function create(User $actor): bool
    {
        return $actor->isTenantAdmin();
    }

    public function manage(User $actor, User $target): bool
    {
        if (! $actor->isTenantAdmin() || $actor->tenant_id === null) {
            return false;
        }

        if ($actor->tenant_id !== $target->tenant_id || $actor->is($target)) {
            return false;
        }

        if ($target->isTenantAdmin()) {
            return $this->assignAdminRoles($actor);
        }

        return true;
    }

    /** Making someone an owner or admin is an owner-only decision. */
    public function assignAdminRoles(User $actor): bool
    {
        return $actor->tenant_role === Role::Owner->value;
    }

    /** So is letting an admin add and edit branches. */
    public function grantBranchManagement(User $actor): bool
    {
        return $actor->tenant_role === Role::Owner->value;
    }
}
