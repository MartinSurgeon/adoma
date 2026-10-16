<?php

namespace App\Domain\Tenancy;

use App\Domain\Audit\Audit;
use App\Models\User;

/**
 * Which branches someone works in, and their role in each: the most sensitive thing an admin changes.
 * Every change goes through here, from the user's page or from the branch's page, so each one is recorded
 * the same way: who had what before, and what after.
 */
final class BranchAccess
{
    /** [branch name => role], in a fixed order so two of them can be compared. */
    public static function map(User $user): array
    {
        $map = $user->branches()->withoutGlobalScopes()->get()
            ->mapWithKeys(fn (Branch $branch) => [$branch->name => $branch->pivot->role])->all();

        ksort($map);

        return $map;
    }

    /**
     * Give someone exactly these branches and roles ([branch id => role]). Owners and admins reach every
     * branch without rows of their own, so they keep none. Records the change if there was one.
     */
    public static function set(User $user, array $roles): void
    {
        $before = self::map($user);

        $rows = $user->isTenantAdmin() ? [] : collect($roles)->mapWithKeys(fn ($role, $id) => [(int) $id => ['role' => $role]])->all();
        $user->branches()->sync($rows);

        $after = self::map($user);

        if ($before !== $after) {
            Audit::record('access_changed', $user, $before === [] ? [] : ['offices' => $before], ['offices' => $after]);
        }
    }

    /** One branch only: give a role there, change it, or (null) take it away. Other branches stay as they are. */
    public static function setIn(Branch $branch, User $user, ?string $role): void
    {
        $roles = $user->branches()->withoutGlobalScopes()->get()
            ->mapWithKeys(fn (Branch $b) => [$b->id => $b->pivot->role])->all();

        if ($role === null) {
            unset($roles[$branch->id]);
        } else {
            $roles[$branch->id] = $role;
        }

        self::set($user, $roles);
    }
}
