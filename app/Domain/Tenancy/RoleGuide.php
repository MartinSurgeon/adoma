<?php

namespace App\Domain\Tenancy;

/**
 * What each role can do, in plain words, for the screens that hand out roles.
 *
 * Viewer, clerk and manager are given per branch: someone can be a manager in Kumasi and a viewer in Central.
 * Admin and owner cover every branch. The rules themselves live in the policies; RoleGuideTest checks every
 * line here against them, so this table cannot drift from what the app really allows.
 */
final class RoleGuide
{
    /** The columns, from least to most. */
    public const ROLES = ['viewer', 'clerk', 'manager', 'admin', 'owner'];

    /** @return list<array{key: string, can: string, roles: list<string>, note?: string}> */
    public static function rows(): array
    {
        $everyone = self::ROLES;
        $clerkUp = ['clerk', 'manager', 'admin', 'owner'];
        $managerUp = ['manager', 'admin', 'owner'];
        $admins = ['admin', 'owner'];

        return [
            ['key' => 'read', 'can' => 'See transactions, stock, ledgers and reports', 'roles' => $everyone],
            ['key' => 'record', 'can' => 'Record purchases, sales, receipts and payments', 'roles' => $clerkUp],
            ['key' => 'people', 'can' => 'Add and edit customers and suppliers', 'roles' => $clerkUp],
            ['key' => 'accounts', 'can' => 'Add and edit banks, cash accounts and expenses', 'roles' => $managerUp],
            ['key' => 'transfer', 'can' => 'Send gold to another branch, and accept gold sent', 'roles' => $managerUp],
            ['key' => 'reverse', 'can' => 'Reverse a transaction', 'roles' => $managerUp],
            ['key' => 'toggle', 'can' => 'Switch ledgers off and on', 'roles' => $managerUp],
            ['key' => 'all', 'can' => 'Look at all branches at once', 'roles' => $admins],
            ['key' => 'users', 'can' => 'Add people and choose their roles', 'roles' => $admins, 'note' => 'An admin cannot change an owner or another admin.'],
            ['key' => 'activity', 'can' => 'See who did what (Activity)', 'roles' => $admins],
            ['key' => 'branches', 'can' => 'Add and edit branches', 'roles' => ['owner'], 'note' => 'An admin can, once the owner allows it.'],
            ['key' => 'admins', 'can' => 'Make someone an owner or admin', 'roles' => ['owner']],
        ];
    }

    /** One line each, for the role choices on a form. */
    public static function summaries(): array
    {
        return [
            'viewer' => 'Can look, cannot change anything',
            'clerk' => 'Records purchases, sales, receipts and payments',
            'manager' => 'Also sends and accepts gold, and reverses',
            'admin' => 'Every branch, and manages people',
            'owner' => 'Everything, in every branch',
        ];
    }
}
