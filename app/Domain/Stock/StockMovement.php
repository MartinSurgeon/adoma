<?php

namespace App\Domain\Stock;

use App\Domain\Tenancy\BelongsToBranch;
use App\Domain\Tenancy\BelongsToTenant;
use App\Domain\Tenancy\TenancyViolation;
use Illuminate\Database\Eloquent\Model;

/** Gold going into or out of a lot, with the cost that goes with it. Never changed, never deleted. */
class StockMovement extends Model
{
    use BelongsToBranch, BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'branch_id', 'lot_id', 'document_id', 'weight_g', 'cost', 'value', 'date'];

    protected function casts(): array
    {
        return ['date' => 'date', 'weight_g' => 'decimal:2', 'cost' => 'decimal:2', 'value' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new TenancyViolation('Stock movements cannot be changed. Post a reversal.'));
        static::deleting(fn () => throw new TenancyViolation('Stock movements cannot be deleted. Post a reversal.'));
    }
}
