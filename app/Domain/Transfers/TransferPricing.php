<?php

namespace App\Domain\Transfers;

use App\Domain\Gold\FormulaSet;
use App\Domain\Gold\GoldCalculator;
use App\Domain\Support\Money;

/**
 * Prices the gold of a transfer. A transfer between branches is a sale: the sending branch sells the gold
 * to the receiving branch at a price per pound, and the receiving branch owes it.
 *
 * Each slice taken from a lot is priced on its own, by the same rule a sale uses, from that lot's karat and
 * the slice's weight. The slice then becomes a lot in the receiving branch whose cost is that price, so
 * what the receiving branch later sells is measured against what it paid.
 */
final class TransferPricing
{
    /**
     * @param  list<array{lot: \App\Domain\Stock\StockLot, weightH: int, costCents: int}>  $taken  what the allocator took
     * @return list<int>  the price of each slice, in pesewas, in the same order
     */
    public static function values(array $taken, FormulaSet $formula, int $priceH): array
    {
        return array_map(
            fn (array $slice) => GoldCalculator::amountFor($formula, $slice['weightH'], Money::fromDb($slice['lot']->karat), $priceH) * 100,
            $taken,
        );
    }
}
