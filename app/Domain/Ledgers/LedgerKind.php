<?php

namespace App\Domain\Ledgers;

use App\Domain\Tenancy\Role;

enum LedgerKind: string
{
    case Customer = 'customer';
    case Supplier = 'supplier';
    case Bank = 'bank';
    case Cash = 'cash';
    case Expense = 'expense';
    case Branch = 'branch';   // created automatically, one per office
    case Account = 'account'; // system accounts such as Sales and Gold Stock

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Customer',
            self::Supplier => 'Supplier',
            self::Bank => 'Bank',
            self::Cash => 'Cash account',
            self::Expense => 'Expense',
            self::Branch => 'Office account',
            self::Account => 'System account',
        };
    }

    public function help(): string
    {
        return match ($this) {
            self::Customer => 'Someone you sell to. They owe you after a sale.',
            self::Supplier => 'Someone you buy from. You owe them after a purchase.',
            self::Bank => 'A bank account the business holds.',
            self::Cash => 'Cash kept in the office.',
            self::Expense => 'A cost such as rent, transport or salaries.',
            self::Branch => 'Stands for another office, used when goods are sold between offices.',
            self::Account => 'Used by the system when it posts transactions.',
        };
    }

    /** The chart group this kind of ledger sits in. Null means the admin picks it. */
    public function groupKey(): ?string
    {
        return match ($this) {
            self::Customer => 'debtors',
            self::Supplier => 'creditors',
            self::Bank, self::Cash => 'cash_bank',
            self::Expense => 'operating_expenses',
            self::Branch => 'inter_office',
            self::Account => null,
        };
    }

    /** Kinds a person can add on the New ledger screen. */
    public function userCreatable(): bool
    {
        return in_array($this, [self::Customer, self::Supplier, self::Bank, self::Cash, self::Expense], true);
    }

    /** The least role that may add or edit this kind in an office. */
    public function minimumRole(): Role
    {
        return match ($this) {
            self::Customer, self::Supplier => Role::Clerk,
            default => Role::Manager,
        };
    }

    /** @return list<self> */
    public static function creatable(): array
    {
        return array_values(array_filter(self::cases(), fn (self $k) => $k->userCreatable()));
    }
}
