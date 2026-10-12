<?php

namespace Database\Seeders;

use App\Domain\Ledgers\ChartOfAccounts;
use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use App\Domain\Tenancy\Branch;
use App\Domain\Tenancy\Role;
use App\Domain\Tenancy\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local demo data: the Adoma business with a Central and a Kumasi office.
 * Every account uses the password "password". Never run this in production.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::firstOrCreate(['name' => 'Adoma']);

        $central = Branch::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'CEN'],
            ['name' => 'Central', 'region' => 'Ashanti', 'is_head_office' => true],
        );
        $kumasi = Branch::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'KUM'],
            ['name' => 'Kumasi', 'region' => 'Ashanti'],
        );

        // New businesses get this when they are created. Running it again fills in
        // anything an older business is missing, and does nothing if it is all there.
        ChartOfAccounts::seedFor($tenant);

        $this->ledgers($tenant, $central, $kumasi);

        $this->user($tenant, 'Owner', 'owner@adoma.test', Role::Owner);
        $this->user($tenant, 'Admin', 'admin@adoma.test', Role::Admin);

        $this->user($tenant, 'Central Manager', 'manager.central@adoma.test')
            ->branches()->syncWithoutDetaching([$central->id => ['role' => Role::Manager->value]]);

        $this->user($tenant, 'Kumasi Clerk', 'clerk.kumasi@adoma.test')
            ->branches()->syncWithoutDetaching([$kumasi->id => ['role' => Role::Clerk->value]]);

        $this->user($tenant, 'Kumasi Viewer', 'viewer.kumasi@adoma.test')
            ->branches()->syncWithoutDetaching([$kumasi->id => ['role' => Role::Viewer->value]]);
    }

    /** A few sample ledgers, using names from the FileMaker file. */
    private function ledgers(Tenant $tenant, Branch $central, Branch $kumasi): void
    {
        $samples = [
            [$kumasi, LedgerKind::Customer, ['Passion Gold', 'Gold Mart', 'Mr Baffour', 'Bro Collins', 'Local Sales']],
            [$kumasi, LedgerKind::Supplier, ['Black', 'Bro Foster', 'Bro Kojo', 'Huseini Goods', 'Bright Ofori']],
            [$kumasi, LedgerKind::Cash, ['Cash Kumasi']],
            [$kumasi, LedgerKind::Expense, ['Transport', 'Salaries']],
            [$central, LedgerKind::Supplier, ['Nana Nkawkaw', 'Alpha Julia']],
            [$central, LedgerKind::Cash, ['Cash HQ']],
        ];

        foreach ($samples as [$branch, $kind, $names]) {
            $group = ChartOfAccounts::groupFor($tenant->id, $kind);

            foreach ($names as $name) {
                Ledger::withoutGlobalScopes()->firstOrCreate(
                    ['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => $name],
                    ['is_shared' => false, 'group_id' => $group->id, 'kind' => $kind],
                );
            }
        }
    }

    private function user(Tenant $tenant, string $name, string $email, ?Role $tenantRole = null): User
    {
        $user = User::firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $name,
            'password' => 'password',
            'email_verified_at' => now(),
            'tenant_id' => $tenant->id,
            'tenant_role' => $tenantRole?->value,
            'is_active' => true,
        ])->save();

        return $user;
    }
}
