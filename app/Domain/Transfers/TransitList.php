<?php

namespace App\Domain\Transfers;

use App\Domain\Documents\Document;
use App\Domain\Ledgers\Ledger;
use App\Domain\Stock\StockMovement;
use App\Domain\Support\Money;
use App\Domain\Tenancy\Branch;
use App\Models\User;
use Closure;
use Illuminate\Support\Collection;

/**
 * Gold on the road: transfers sent and not yet accepted, as cards for the home page. Each says where it is going,
 * how much gold and of what kind, what it was sold for, and who sent it when.
 *
 * The names are looked up directly, not through the usual branch-scoped relations, because a transfer out lives in the
 * sending branch's books and the receiving branch must still see what is coming. Only what both branches already know
 * is shown: never the sending branch's cost.
 */
final class TransitList
{
    /**
     * @param  Collection<int, Document>  $waiting  transfers from PendingTransfers
     * @param  Branch|null  $viewer  the branch the person is working in; null for an owner looking at all branches
     * @param  Closure(Document, ?string): ?string  $link  where a card leads, given the transfer and its direction
     * @return list<array<string, mixed>>
     */
    public static function rows(Collection $waiting, ?Branch $viewer, Closure $link): array
    {
        if ($waiting->isEmpty()) {
            return [];
        }

        $ids = $waiting->pluck('id')->all();
        $figures = PendingTransfers::figures($waiting);
        $receivers = Ledger::withoutGlobalScopes()->whereIn('id', $waiting->pluck('ledger_id')->unique())->pluck('linked_branch_id', 'id');
        $names = Branch::withoutGlobalScopes()
            ->whereIn('id', $waiting->pluck('branch_id')->merge($receivers->values())->filter()->unique())
            ->pluck('name', 'id');
        $senders = User::query()->whereIn('id', $waiting->pluck('created_by')->filter()->unique())->pluck('name', 'id');

        // The kinds of gold each transfer took from the lots: raw, refined, or both (mixed).
        $forms = StockMovement::withoutGlobalScopes()
            ->join('stock_lots', 'stock_lots.id', '=', 'stock_movements.lot_id')
            ->whereIn('stock_movements.document_id', $ids)
            ->select('stock_movements.document_id', 'stock_lots.form')
            ->distinct()
            ->get()
            ->groupBy('document_id');

        return $waiting->map(function (Document $d) use ($viewer, $link, $figures, $receivers, $names, $senders, $forms) {
            $direction = $viewer === null ? null : ($d->branch_id === $viewer->id ? 'out' : 'in');
            $kinds = ($forms[$d->id] ?? collect())->pluck('form')->unique()->values();
            $figure = $figures[$d->id] ?? ['weightH' => 0, 'valueCents' => 0, 'lots' => 0];

            return [
                'id' => $d->id,
                'number' => $d->number,
                'from' => $names[$d->branch_id] ?? '',
                'to' => $names[$receivers[$d->ledger_id] ?? 0] ?? '',
                'direction' => $direction,
                'lots' => $figure['lots'],
                'weight' => Money::format($figure['weightH']),
                'form' => match ($kinds->count()) {
                    0 => null,
                    1 => ucfirst($kinds->first()),
                    default => 'Mixed',
                },
                // The price the receiving branch will owe: known to both branches. The sender's cost is not shown.
                'value' => Money::cedis($figure['valueCents']),
                'sent_by' => $senders[$d->created_by] ?? null,
                'sent_at' => $d->created_at?->toIso8601String(),
                'href' => $link($d, $direction),
            ];
        })->values()->all();
    }
}
