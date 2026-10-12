<?php

namespace App\Policies;

use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use App\Domain\Tenancy\Role;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Who may do what with ledgers.
 *
 *  - Anyone with access to the active office can look.
 *  - A clerk can add and edit customers and suppliers in their own office.
 *  - A manager can also add and edit banks, cash accounts and expenses.
 *  - Shared ledgers (used by every office) are for owners and admins only.
 *  - System accounts and office accounts are never edited or switched off by hand.
 *  - Switching a ledger on or off needs a manager.
 */
class LedgerPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->activeRole($user) !== null;
    }

    public function create(User $user, string $kind = 'customer', bool $shared = false): bool
    {
        $ledgerKind = LedgerKind::tryFrom($kind);

        if ($ledgerKind === null || ! $ledgerKind->userCreatable()) {
            return false;
        }

        if ($shared) {
            return $user->isTenantAdmin();
        }

        return $this->activeRole($user)?->atLeast($ledgerKind->minimumRole()) ?? false;
    }

    public function update(User $user, Ledger $ledger): bool
    {
        return $this->canChange($user, $ledger, $ledger->kind->minimumRole());
    }

    public function toggle(User $user, Ledger $ledger): bool
    {
        return $this->canChange($user, $ledger, Role::Manager);
    }

    private function canChange(User $user, Ledger $ledger, Role $minimum): bool
    {
        if ($ledger->is_system) {
            return false;
        }

        if ($ledger->is_shared) {
            return $user->isTenantAdmin();
        }

        return $user->roleIn($ledger->branch_id)?->atLeast($minimum) ?? false;
    }

    /** The user's role in the office they are working in, or as admin when viewing all offices. */
    private function activeRole(User $user): ?Role
    {
        $context = app(TenantContext::class);

        if ($context->viewingAllBranches()) {
            return $user->isTenantAdmin() ? Role::from($user->tenant_role) : null;
        }

        return $context->branchId() === null ? null : $user->roleIn($context->branchId());
    }
}
