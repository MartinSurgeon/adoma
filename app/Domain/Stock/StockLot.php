<?php

namespace App\Domain\Stock;

use App\Domain\Gold\GoldLine;
use App\Domain\Tenancy\BelongsToBranch;
use App\Domain\Tenancy\BelongsToTenant;
use App\Domain\Tenancy\TenancyViolation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A parcel of gold an office holds, from one purchase. It keeps its own weight, karat and cost,
 * so a sale can say exactly what the gold it took had cost.
 *
 * Only the weight and cost that remain can change, and only through StockMovement lines.
 * Seen only by its own office.
 */
class StockLot extends Model
{
    use BelongsToBranch, BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'branch_id', 'gold_line_id', 'date', 'form', 'karat',
        'weight_g', 'remaining_g', 'cost', 'remaining_cost',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date', 'karat' => 'decimal:2', 'weight_g' => 'decimal:2', 'remaining_g' => 'decimal:2',
            'cost' => 'decimal:2', 'remaining_cost' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (StockLot $lot) {
            if (array_diff(array_keys($lot->getDirty()), ['remaining_g', 'remaining_cost', 'updated_at']) !== []) {
                throw new TenancyViolation('A stock lot can only lose or regain gold. Nothing else about it can change.');
            }
        });

        static::deleting(fn () => throw new TenancyViolation('A stock lot cannot be deleted.'));
    }

    public function goldLine(): BelongsTo
    {
        return $this->belongsTo(GoldLine::class);
    }
}
