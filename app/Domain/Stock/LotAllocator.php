<?php

namespace App\Domain\Stock;

use App\Domain\Documents\PostingError;
use App\Domain\Support\Money;
use App\Domain\Tenancy\Branch;

/**
 * Decides which lots a sale takes its gold from, oldest first, and what that gold had cost.
 *
 * It locks the lots it reads, so two sales at the same moment cannot take the same gold.
 * Call it inside the posting transaction, so the lock lasts until the sale is saved.
 *
 * Cost moves with weight. Taking part of a lot takes the same share of its remaining cost,
 * cut down to a whole pesewa. Taking the rest of a lot takes whatever cost is left, so across all
 * the sales from a lot the cost taken adds up to exactly what the lot cost, with nothing lost.
 */
final class LotAllocator
{
    /**
     * @return list<array{lot: StockLot, weightH: int, costCents: int}>
     */
    public function allocate(Branch $branch, int $weightH): array
    {
        $lots = StockLot::withoutGlobalScopes()
            ->where('tenant_id', $branch->tenant_id)
            ->where('branch_id', $branch->id)
            ->where('remaining_g', '>', 0)
            ->orderBy('date')->orderBy('id')
            ->lockForUpdate()
            ->get();

        $available = $lots->sum(fn (StockLot $lot) => Money::fromDb($lot->remaining_g));

        if ($available < $weightH) {
            throw new PostingError(sprintf(
                'Not enough gold in stock. %s has %s g and this sale is %s g.',
                $branch->name,
                Money::format($available),
                Money::format($weightH),
            ));
        }

        $left = $weightH;
        $taken = [];

        foreach ($lots as $lot) {
            if ($left === 0) {
                break;
            }

            $remainingH = Money::fromDb($lot->remaining_g);
            $remainingCost = Money::fromDb($lot->remaining_cost);
            $takeH = min($left, $remainingH);

            // All that is left of the lot takes all that is left of its cost. A part takes its share.
            $costCents = $takeH === $remainingH
                ? $remainingCost
                : intdiv($remainingCost * $takeH, $remainingH);

            $taken[] = ['lot' => $lot, 'weightH' => $takeH, 'costCents' => $costCents];
            $left -= $takeH;
        }

        return $taken;
    }

    /** What is in stock for an office, in hundredths of a gram. */
    public static function availableH(Branch $branch): int
    {
        return Money::fromDb(
            StockLot::withoutGlobalScopes()
                ->where('tenant_id', $branch->tenant_id)->where('branch_id', $branch->id)
                ->sum('remaining_g')
        );
    }
}
