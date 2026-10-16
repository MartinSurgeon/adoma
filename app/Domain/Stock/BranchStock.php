<?php

namespace App\Domain\Stock;

use App\Domain\Documents\Document;
use App\Domain\Ledgers\Ledger;
use App\Domain\Support\Money;
use App\Domain\Tenancy\Branch;
use App\Domain\Transfers\PendingTransfers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The gold each branch holds right now, for the owner's "all branches" home page: the total, how much is raw and how
 * much refined, when it last changed, and what is on its way to the branch (counted once it is accepted).
 *
 * Three queries for any number of branches: stock by branch and kind, the last stock change per branch, and the
 * transfers still waiting, which the caller already has.
 */
final class BranchStock
{
    /**
     * @param  Collection<int, Branch>  $branches  the branches the viewer may see, head office first
     * @param  Collection<int, Document>  $waiting  every transfer in the business still waiting to be accepted
     * @return list<array<string, mixed>>
     */
    public static function overview(Collection $branches, Collection $waiting): array
    {
        if ($branches->isEmpty()) {
            return [];
        }

        $ids = $branches->pluck('id')->all();
        $tenantId = $branches->first()->tenant_id;

        $held = StockLot::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->whereIn('branch_id', $ids)->where('remaining_g', '>', 0)
            ->selectRaw('branch_id, form, SUM(remaining_g) as grams')
            ->groupBy('branch_id', 'form')
            ->get()
            ->groupBy('branch_id');

        $updated = StockMovement::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->whereIn('branch_id', $ids)
            ->selectRaw('branch_id, MAX(created_at) as last_at')
            ->groupBy('branch_id')
            ->pluck('last_at', 'branch_id');

        $arriving = self::arriving($waiting);

        return $branches->map(function (Branch $branch) use ($held, $updated, $arriving) {
            $forms = ($held[$branch->id] ?? collect())->mapWithKeys(fn ($row) => [$row->form => Money::fromDb($row->grams)]);
            $raw = (int) ($forms['raw'] ?? 0);
            $refined = (int) ($forms['refined'] ?? 0);
            $total = $raw + $refined;
            $last = $updated[$branch->id] ?? null;

            return [
                'id' => $branch->id,
                'name' => $branch->name,
                'is_head_office' => (bool) $branch->is_head_office,
                'grams' => Money::format($total),
                'raw' => Money::format($raw),
                'refined' => Money::format($refined),
                // How much of the bar is raw gold, as a whole percentage. An empty branch has no bar to split.
                'raw_share' => $total > 0 ? (int) round($raw * 100 / $total) : null,
                'updated_at' => $last ? Carbon::parse($last)->toIso8601String() : null,
                'arriving' => isset($arriving[$branch->id])
                    ? ['count' => $arriving[$branch->id]['count'], 'grams' => Money::format($arriving[$branch->id]['weightH'])]
                    : null,
                'href' => route('stock.index', ['branch' => $branch->id]),
            ];
        })->values()->all();
    }

    /**
     * What is on its way to each branch: how many transfers, and how much gold.
     *
     * @param  Collection<int, Document>  $waiting
     * @return array<int, array{count: int, weightH: int}>  by receiving branch id
     */
    private static function arriving(Collection $waiting): array
    {
        if ($waiting->isEmpty()) {
            return [];
        }

        $figures = PendingTransfers::figures($waiting);
        $receivers = Ledger::withoutGlobalScopes()->whereIn('id', $waiting->pluck('ledger_id')->unique())->pluck('linked_branch_id', 'id');
        $result = [];

        foreach ($waiting as $transfer) {
            $to = $receivers[$transfer->ledger_id] ?? null;

            if ($to === null) {
                continue;
            }

            $result[$to] ??= ['count' => 0, 'weightH' => 0];
            $result[$to]['count']++;
            $result[$to]['weightH'] += $figures[$transfer->id]['weightH'] ?? 0;
        }

        return $result;
    }
}
