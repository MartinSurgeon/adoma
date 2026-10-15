<?php

namespace App\Http\Controllers;

use App\Domain\Documents\Document;
use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use App\Domain\Stock\StockLot;
use App\Domain\Support\Money;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The home page. Shows what the signed-in person's office has, and the one thing
 * they are most likely to do next. Every number respects the same branch scoping
 * as the rest of the app, so a branch never sees another branch's figures.
 */
class DashboardController extends Controller
{
    private const STATS = [
        ['kind' => LedgerKind::Customer, 'label' => 'Customers'],
        ['kind' => LedgerKind::Supplier, 'label' => 'Suppliers'],
        ['kind' => LedgerKind::Bank, 'label' => 'Banks'],
        ['kind' => LedgerKind::Cash, 'label' => 'Cash accounts'],
    ];

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $context = app(TenantContext::class);

        $counts = Ledger::query()
            ->where('is_active', true)
            ->selectRaw('kind, count(*) as total')
            ->groupBy('kind')
            ->pluck('total', 'kind');

        // The latest transactions the signed-in person may read, newest first, for the branch in context.
        $recent = $user->can('viewAny', Document::class)
            ? Document::query()
                ->with(['ledger', 'branch'])
                ->orderByDesc('date')->orderByDesc('id')
                ->limit(5)
                ->get()
                ->map(fn (Document $d) => [
                    'id' => $d->id,
                    'number' => $d->number,
                    'type' => $d->type->value,
                    'type_label' => $d->type->label(),
                    'date' => $d->date->toDateString(),
                    'party' => $d->ledger?->name,
                    'office' => $d->branch?->name,
                    'total' => Money::format(Money::cents($d->total)),
                    'status' => $d->status,
                    'is_reversal' => $d->isReversal(),
                ])->values()
            : collect();

        // Gold in stock, for the branches in context: the number an owner looks for first.
        $goldInStock = StockLot::query()->where('remaining_g', '>', 0)->sum('remaining_g');

        // For an owner or admin looking at every branch: how much gold each one holds.
        $stockByOffice = null;

        if ($context->viewingAllBranches() && $user->isTenantAdmin()) {
            $held = StockLot::query()->where('remaining_g', '>', 0)
                ->selectRaw('branch_id, SUM(remaining_g) as grams')->groupBy('branch_id')->pluck('grams', 'branch_id');

            $stockByOffice = $user->accessibleBranches()
                ->map(fn ($branch) => ['name' => $branch->name, 'grams' => Money::format(Money::fromDb($held[$branch->id] ?? 0))])
                ->values();
        }

        return Inertia::render('Dashboard', [
            'stockByOffice' => $stockByOffice,
            'canTransfer' => $user->can('create', [Document::class, 'transfer']),
            'where' => $context->viewingAllBranches() ? 'All branches' : $context->branch()?->name,
            'goldInStock' => Money::format(Money::fromDb($goldInStock)),
            'stats' => collect(self::STATS)->map(fn (array $stat) => [
                'label' => $stat['label'],
                'count' => (int) ($counts[$stat['kind']->value] ?? 0),
                'href' => route('ledgers.index', ['kind' => $stat['kind']->value]),
            ])->values(),
            'recent' => $recent,
            'canCreate' => $user->can('createAny', Ledger::class),
            'canRecord' => $user->can('create', [Document::class, 'receipt']),
        ]);
    }
}
