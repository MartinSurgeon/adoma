<?php

namespace App\Domain\Documents;

use App\Domain\Stock\StockMovement;
use App\Domain\Support\Money;
use Illuminate\Support\Collection;

/**
 * Transactions as rows for the lists people read: the latest on the home page, and the Transactions page.
 * Both show the same things in the same words, so a row reads the same wherever it appears.
 *
 * Load the documents with ledger, branch and goldLine. The gold a transfer or a reversal moved has no gold line of its
 * own, so it is added up from the stock movements, in one query for the whole list.
 */
final class DocumentRows
{
    /**
     * @param  Collection<int, Document>  $documents
     * @return list<array<string, mixed>>
     */
    public static function from(Collection $documents): array
    {
        if ($documents->isEmpty()) {
            return [];
        }

        $ids = $documents->pluck('id')->all();

        $moved = StockMovement::withoutGlobalScopes()
            ->whereIn('document_id', $ids)
            ->selectRaw('document_id, SUM(ABS(weight_g)) as grams')
            ->groupBy('document_id')
            ->pluck('grams', 'document_id');

        // A transfer out is "waiting" until the receiving branch accepts it, which writes its other half.
        $sent = $documents
            ->filter(fn (Document $d) => $d->type === DocumentType::Transfer && $d->status === Document::POSTED && ! $d->isReversal())
            ->pluck('id')->all();
        $accepted = $sent === [] ? [] : Document::withoutGlobalScopes()->whereIn('paired_id', $sent)->pluck('paired_id')->all();
        $waiting = array_values(array_diff($sent, $accepted));

        return $documents->map(function (Document $d) use ($moved, $waiting) {
            $gold = $d->goldLine?->weight_g ?? $moved[$d->id] ?? null;

            return [
                'id' => $d->id,
                'number' => $d->number,
                'type' => $d->type->value,
                'type_label' => $d->type->label(),
                'date' => $d->date->toDateString(),
                'recorded_at' => $d->created_at?->toIso8601String(),
                'party' => self::party($d),
                'office' => $d->branch?->name,
                'weight' => $gold === null ? null : Money::format(Money::fromDb($gold)),
                'total' => Money::format(Money::cents($d->total)),
                'status' => $d->status,
                'is_reversal' => $d->isReversal(),
                'waiting' => in_array($d->id, $waiting, true),
            ];
        })->values()->all();
    }

    /** Who the transaction was with. A transfer names the other branch and the way the gold went. */
    private static function party(Document $d): ?string
    {
        $name = $d->ledger?->name;

        if ($name === null) {
            return null;
        }

        return match ($d->type) {
            DocumentType::Transfer => "To {$name}",
            DocumentType::TransferIn => "From {$name}",
            default => $name,
        };
    }
}
