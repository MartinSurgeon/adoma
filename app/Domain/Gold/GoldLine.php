<?php

namespace App\Domain\Gold;

use App\Domain\Documents\Document;
use App\Domain\Tenancy\BelongsToBranch;
use App\Domain\Tenancy\BelongsToTenant;
use App\Domain\Tenancy\TenancyViolation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The gold on one purchase or sale: what was weighed and what the formulas worked out.
 * Written once with its document. Never changed, never deleted.
 */
class GoldLine extends Model
{
    use BelongsToBranch, BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'branch_id', 'document_id', 'form', 'weight_g', 'big_weight_g', 'water_weight_g', 'pounds',
        'density', 'karat', 'price', 'amount', 'formula_set_id',
    ];

    protected function casts(): array
    {
        return [
            'weight_g' => 'decimal:2', 'big_weight_g' => 'decimal:2', 'water_weight_g' => 'decimal:2', 'pounds' => 'decimal:2',
            'density' => 'decimal:2', 'karat' => 'decimal:2', 'price' => 'decimal:2', 'amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new TenancyViolation('Gold figures cannot be changed. Reverse the document and post a new one.'));
        static::deleting(fn () => throw new TenancyViolation('Gold figures cannot be deleted. Reverse the document instead.'));
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function formulaSet(): BelongsTo
    {
        return $this->belongsTo(FormulaSet::class);
    }
}
