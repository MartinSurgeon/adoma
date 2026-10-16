<?php

namespace App\Policies;

use App\Domain\Tenancy\Branch;
use App\Models\User;

/**
 * Who may see and change the branches themselves.
 *
 *  - Owners and admins see every branch and who works in each, and give people their roles there
 *    (the same people who manage users).
 *  - Adding a branch, or changing its name, region or head office, is for an owner, or for an admin
 *    the owner has allowed (User::canManageBranches). A new branch brings its own stock, books and
 *    voucher numbers, so it is not done lightly.
 */
class BranchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isTenantAdmin();
    }

    public function view(User $user, Branch $branch): bool
    {
        return $user->isTenantAdmin() && $branch->tenant_id === $user->tenant_id;
    }

    public function create(User $user): bool
    {
        return $user->canManageBranches();
    }

    public function update(User $user, Branch $branch): bool
    {
        return $user->canManageBranches() && $branch->tenant_id === $user->tenant_id;
    }

    /** Give people a role in this branch, or take it away. The same people who manage users. */
    public function manageAccess(User $user, Branch $branch): bool
    {
        return $this->view($user, $branch);
    }
}
