<?php

namespace App\Domain\Ledgers;

use App\Domain\Tenancy\Branch;
use App\Domain\Tenancy\Tenant;

/**
 * Builds the default chart of accounts for a business, and the account that
 * stands for each office. Every method is safe to run again: it only adds what is missing.
 */
final class ChartOfAccounts
{
    private const TREE = [
        ['key' => 'assets', 'name' => 'Assets', 'nature' => 'asset', 'children' => [
            ['key' => 'current_assets', 'name' => 'Current Assets', 'children' => [
                ['key' => 'cash_bank', 'name' => 'Cash/Bank'],
                ['key' => 'debtors', 'name' => 'Debtors'],
                ['key' => 'gold_stock', 'name' => 'Gold Stock'],
                ['key' => 'prepaid', 'name' => 'Prepaid'],
                ['key' => 'inter_office', 'name' => 'Inter-office'],
            ]],
        ]],
        ['key' => 'liabilities', 'name' => 'Liabilities', 'nature' => 'liability', 'children' => [
            ['key' => 'current_liabilities', 'name' => 'Current Liabilities', 'children' => [
                ['key' => 'creditors', 'name' => 'Creditors'],
                ['key' => 'accruals', 'name' => 'Accruals'],
            ]],
        ]],
        ['key' => 'income', 'name' => 'Income', 'nature' => 'income', 'children' => [
            ['key' => 'sales', 'name' => 'Sales'],
        ]],
        ['key' => 'expenses', 'name' => 'Expenses', 'nature' => 'expense', 'children' => [
            ['key' => 'cost_of_sales', 'name' => 'Cost of Sales'],
            ['key' => 'operating_expenses', 'name' => 'Operating Expenses'],
        ]],
        ['key' => 'equity', 'name' => 'Equity', 'nature' => 'equity', 'children' => [
            ['key' => 'capital', 'name' => 'Capital'],
        ]],
    ];

    /** System accounts the posting code will use: key, name, group. */
    private const SYSTEM_LEDGERS = [
        ['system_key' => 'gold_stock', 'name' => 'Gold Stock', 'group' => 'gold_stock'],
        ['system_key' => 'sales', 'name' => 'Sales', 'group' => 'sales'],
        ['system_key' => 'cost_of_sales', 'name' => 'Cost of Sales', 'group' => 'cost_of_sales'],
    ];

    public static function seedFor(Tenant $tenant): void
    {
        self::seedGroups($tenant, self::TREE, null, null);

        foreach (self::SYSTEM_LEDGERS as $account) {
            $group = self::group($tenant->id, $account['group']);

            Ledger::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'system_key' => $account['system_key']],
                [
                    'is_shared' => true,
                    'group_id' => $group->id,
                    'kind' => LedgerKind::Account,
                    'name' => $account['name'],
                    'is_system' => true,
                ],
            );
        }

        Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->get()
            ->each(fn (Branch $branch) => self::ensureBranchLedger($branch));
    }

    /** The account other offices use to buy from or sell to this office. */
    public static function ensureBranchLedger(Branch $branch): Ledger
    {
        $group = self::group($branch->tenant_id, LedgerKind::Branch->groupKey());

        return Ledger::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $branch->tenant_id, 'linked_branch_id' => $branch->id],
            [
                'is_shared' => true,
                'group_id' => $group->id,
                'kind' => LedgerKind::Branch,
                'name' => $branch->name,
                'is_system' => true,
            ],
        );
    }

    public static function groupFor(int $tenantId, LedgerKind $kind): ?LedgerGroup
    {
        $key = $kind->groupKey();

        return $key === null ? null : self::group($tenantId, $key);
    }

    /** Find a group by its fixed key, building the chart first if this business has none yet. */
    private static function group(int $tenantId, string $key): LedgerGroup
    {
        $group = LedgerGroup::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('key', $key)->first();

        if ($group === null) {
            self::seedGroups(Tenant::findOrFail($tenantId), self::TREE, null, null);
            $group = LedgerGroup::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('key', $key)->firstOrFail();
        }

        return $group;
    }

    private static function seedGroups(Tenant $tenant, array $nodes, ?LedgerGroup $parent, ?string $nature): void
    {
        foreach ($nodes as $order => $node) {
            $nodeNature = $node['nature'] ?? $nature;

            $group = LedgerGroup::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'key' => $node['key']],
                [
                    'parent_id' => $parent?->id,
                    'name' => $node['name'],
                    'nature' => $nodeNature,
                    'sort_order' => $order,
                    'is_system' => true,
                ],
            );

            self::seedGroups($tenant, $node['children'] ?? [], $group, $nodeNature);
        }
    }
}
