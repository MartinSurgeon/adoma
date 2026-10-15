<?php

namespace App\Domain\Tenancy;

enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Manager = 'manager';
    case Clerk = 'clerk';
    case Viewer = 'viewer';

    public function rank(): int
    {
        return match ($this) {
            self::Owner => 50,
            self::Admin => 40,
            self::Manager => 30,
            self::Clerk => 20,
            self::Viewer => 10,
        };
    }

    public function atLeast(self $minimum): bool
    {
        return $this->rank() >= $minimum->rank();
    }

    /** Roles that may be stored per branch in branch_user. */
    public static function branchRoles(): array
    {
        return [self::Manager, self::Clerk, self::Viewer];
    }
}
