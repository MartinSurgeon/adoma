<?php

namespace App\Domain\Transfers;

use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentType;
use App\Domain\Ledgers\Ledger;
use App\Domain\Stock\StockMovement;
use App\Domain\Support\Money;
use App\Domain\Tenancy\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Transfers that have been sent and not yet answered: not accepted, not declined, not cancelled.
 * The gold is on its way, in neither office's stock.
 *
 * A transfer out lives in the sending office's books, which the receiving office cannot normally read.
 * These queries step outside that, on purpose and only for this one purpose, so the receiving office
 * can see what is waiting for it. Each is tied to one business and one office, and callers show only
 * what the office needs to decide.
 */
final class PendingTransfers
{
    /** Every transfer out, in any office of the business, still waiting for an answer. */
    private static function waiting(int $tenantId): Builder
    {
        return Document::withoutGlobalScopes()
            ->where('documents.tenant_id', $tenantId)
            ->where('documents.type', DocumentType::Transfer->value)
            ->where('documents.status', Document::POSTED)
            ->whereNull('documents.reverses_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('documents as accepted')->whereColumn('accepted.paired_id', 'documents.id'));
    }

    /** Waiting for this office to accept or decline. */
    public static function addressedTo(Branch $office): Builder
    {
        return self::waiting($office->tenant_id)
            ->whereIn('documents.ledger_id', Ledger::withoutGlobalScopes()->where('linked_branch_id', $office->id)->select('id'));
    }

    /** Sent by this office and waiting for the other office to answer. */
    public static function sentBy(Branch $office): Builder
    {
        return self::waiting($office->tenant_id)->where('documents.branch_id', $office->id);
    }

    /**
     * Weight and cost of each transfer, from the gold that left the sending office.
     *
     * @param  Collection<int, Document>|list<int>  $documents
     * @return array<int, array{weightH: int, costCents: int, lots: int}>  by document id
     */
    public static function figures(Collection|array $documents): array
    {
        $ids = collect($documents)->map(fn ($d) => $d instanceof Document ? $d->id : (int) $d)->values()->all();

        if ($ids === []) {
            return [];
        }

        $figures = array_fill_keys($ids, ['weightH' => 0, 'costCents' => 0, 'lots' => 0]);

        StockMovement::withoutGlobalScopes()->whereIn('document_id', $ids)->get()->each(function ($move) use (&$figures) {
            $figures[$move->document_id]['weightH'] += abs(Money::fromDb($move->weight_g));
            $figures[$move->document_id]['costCents'] += abs(Money::fromDb($move->cost));
            $figures[$move->document_id]['lots']++;
        });

        return $figures;
    }

    /** How many are waiting, and how much gold. */
    public static function summary(Builder $waiting): array
    {
        $documents = $waiting->get(['documents.id']);
        $figures = self::figures($documents);

        return [
            'count' => $documents->count(),
            'weightH' => array_sum(array_column($figures, 'weightH')),
            'costCents' => array_sum(array_column($figures, 'costCents')),
        ];
    }
}
