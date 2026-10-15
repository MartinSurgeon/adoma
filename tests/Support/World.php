<?php

namespace Tests\Support;

use App\Domain\Tenancy\Branch;
use App\Domain\Tenancy\Role;
use App\Domain\Tenancy\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A small two-business world used by the tenancy tests.
 *
 *  Adoma   : Central (head office) and Kumasi, an owner, a Kumasi clerk, a Central manager
 *  Other Co: one branch and one admin, who must never see anything of Adoma's
 */
final class World
{
    public Tenant $adoma;
    public Tenant $other;
    public Branch $central;
    public Branch $kumasi;
    public Branch $otherBranch;
    public User $owner;
    public User $admin;
    public User $kumasiClerk;
    public User $kumasiManager;
    public User $kumasiViewer;
    public User $centralManager;
    public User $otherAdmin;

    public static function build(): self
    {
        $w = new self();

        $w->adoma = Tenant::create(['name' => 'Adoma']);
        $w->other = Tenant::create(['name' => 'Other Co']);

        $w->central = Branch::create(['tenant_id' => $w->adoma->id, 'code' => 'CEN', 'name' => 'Central', 'is_head_office' => true]);
        $w->kumasi = Branch::create(['tenant_id' => $w->adoma->id, 'code' => 'KUM', 'name' => 'Kumasi']);
        $w->otherBranch = Branch::create(['tenant_id' => $w->other->id, 'code' => 'MAIN', 'name' => 'Main', 'is_head_office' => true]);

        $w->owner = self::user($w->adoma, Role::Owner);
        $w->admin = self::user($w->adoma, Role::Admin);
        $w->otherAdmin = self::user($w->other, Role::Admin);

        $w->kumasiClerk = self::user($w->adoma);
        $w->kumasiClerk->branches()->attach($w->kumasi->id, ['role' => Role::Clerk->value]);

        $w->kumasiManager = self::user($w->adoma);
        $w->kumasiManager->branches()->attach($w->kumasi->id, ['role' => Role::Manager->value]);

        $w->kumasiViewer = self::user($w->adoma);
        $w->kumasiViewer->branches()->attach($w->kumasi->id, ['role' => Role::Viewer->value]);

        $w->centralManager = self::user($w->adoma);
        $w->centralManager->branches()->attach($w->central->id, ['role' => Role::Manager->value]);

        return $w;
    }

    private static function user(Tenant $tenant, ?Role $tenantRole = null): User
    {
        // forceFill: tenant_id and tenant_role are not mass assignable on purpose.
        $user = User::factory()->make();
        $user->forceFill(['tenant_id' => $tenant->id, 'tenant_role' => $tenantRole?->value])->save();

        return $user;
    }

    /** Rows written straight to the table so no scope or trait is involved. */
    public function seedProbes(): void
    {
        $rows = [
            [$this->adoma, $this->central, 'central-a'],
            [$this->adoma, $this->central, 'central-b'],
            [$this->adoma, $this->kumasi, 'kumasi-a'],
            [$this->adoma, $this->kumasi, 'kumasi-b'],
            [$this->other, $this->otherBranch, 'other-a'],
        ];

        foreach ($rows as [$tenant, $branch, $name]) {
            DB::table('scope_probes')->insert([
                'tenant_id' => $tenant->id,
                'branch_id' => $branch->id,
                'name' => $name,
            ]);
        }
    }
}
