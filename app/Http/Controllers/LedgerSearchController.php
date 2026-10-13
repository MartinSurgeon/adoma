<?php

namespace App\Http\Controllers;

use App\Domain\Documents\LedgerBalances;
use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The type-ahead behind "Received from" and "Paid to": the first 20 active ledgers that match
 * what was typed, each with its balance so the person sees where they stand before saving.
 * It reads through the same scopes as everything else, so it finds only this office's ledgers
 * and the shared ones.
 */
class LedgerSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Ledger::class);

        $kinds = collect(explode(',', (string) $request->query('kinds')))
            ->map(fn ($k) => LedgerKind::tryFrom(trim($k)))->filter()->map->value->values()->all();

        $term = trim((string) $request->query('q', ''));

        $ledgers = Ledger::query()
            ->where('is_active', true)
            ->when($kinds !== [], fn ($q) => $q->whereIn('kind', $kinds))
            ->when($term !== '', fn ($q) => $q->where('name', 'like', '%'.$term.'%'))
            ->orderBy('name')
            ->limit(20)
            ->get();

        $balances = LedgerBalances::cents($ledgers->pluck('id')->all());

        return response()->json($ledgers->map(fn (Ledger $l) => [
            'id' => $l->id,
            'name' => $l->name,
            'kind_label' => $l->kind->label(),
            'balance' => LedgerBalances::describe($l->kind, $balances[$l->id]),
        ])->values());
    }
}
