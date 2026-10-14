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
    public function allocate(Branch $branch, int $weightH, bool $lock = true): array
    {
        // $lock is false only for a quote shown before saving, which reads and changes nothing.
        if ($lock) {
            $this->lockOffices([$branch]);
        }

        $query = StockLot::withoutGlobalScopes()
            ->where('tenant_id', $branch->tenant_id)
            ->where('branch_id', $branch->id)
            ->where('remaining_g', '>', 0)
            ->orderBy('date')->orderBy('id');

        $lots = ($lock ? $query->lockForUpdate() : $query)->get();

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

    /**
     * Take the stock lock of one or more offices, lowest office first, every time.
     *
     * Anything that moves gold (a sale, a transfer, a reversal) takes this first, so only one of them
     * works on an office's gold at a time and the rest wait their turn. The lock is on the office's own
     * row, not on a range of lots: locking ranges of lots let two offices sending to each other, or a
     * reversal alongside a sale, each hold a range the other needed, and the database had to cancel one.
     * One lock per office, always in the same order, cannot do that. Call it first, inside the posting
     * transaction, so the lock lasts until the posting is saved.
     *
     * @param  list<Branch>  $branches
     */
    public function lockOffices(array $branches): void
    {
        $ids = collect($branches)->map(fn (Branch $branch) => $branch->id)->unique()->sort()->values()->all();

        Branch::withoutGlobalScopes()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
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
