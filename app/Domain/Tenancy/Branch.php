<?php

namespace App\Domain\Tenancy;

use App\Domain\Ledgers\ChartOfAccounts;
use App\Domain\Ledgers\Ledger;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Branch extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'code', 'name', 'region', 'is_head_office'];

    protected static function booted(): void
    {
        // Each office gets an account that other offices use to trade with it.
        static::created(fn (Branch $branch) => ChartOfAccounts::ensureBranchLedger($branch));

        // Renaming an office renames its account, so the two never disagree.
        static::updated(function (Branch $branch) {
            if ($branch->wasChanged('name')) {
                Ledger::withoutGlobalScopes()
                    ->where('tenant_id', $branch->tenant_id)
                    ->where('linked_branch_id', $branch->id)
                    ->update(['name' => $branch->name]);
            }
        });
    }

    protected function casts(): array
    {
        return ['is_head_office' => 'boolean'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }
}
