<?php

namespace Database\Seeders;

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

        $this->user($tenant, 'Owner', 'owner@adoma.test', Role::Owner);
        $this->user($tenant, 'Admin', 'admin@adoma.test', Role::Admin);

        $this->user($tenant, 'Central Manager', 'manager.central@adoma.test')
            ->branches()->syncWithoutDetaching([$central->id => ['role' => Role::Manager->value]]);

        $this->user($tenant, 'Kumasi Clerk', 'clerk.kumasi@adoma.test')
            ->branches()->syncWithoutDetaching([$kumasi->id => ['role' => Role::Clerk->value]]);

        $this->user($tenant, 'Kumasi Viewer', 'viewer.kumasi@adoma.test')
            ->branches()->syncWithoutDetaching([$kumasi->id => ['role' => Role::Viewer->value]]);
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
