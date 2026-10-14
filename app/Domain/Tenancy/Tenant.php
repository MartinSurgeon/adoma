<?php

namespace App\Domain\Tenancy;

use App\Domain\Audit\Auditable;
use App\Domain\Gold\FormulaSet;
use App\Domain\Ledgers\ChartOfAccounts;
use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    use Auditable;

    protected $fillable = ['name', 'plan', 'base_currency', 'status'];

    protected static function booted(): void
    {
        // Every business starts with the standard chart of accounts.
        static::created(function (Tenant $tenant) {
            ChartOfAccounts::seedFor($tenant);
            FormulaSet::createDefaultFor($tenant);
        });
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
