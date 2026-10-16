<?php

namespace App\Http\Controllers;

use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentRows;
use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use App\Domain\Stock\BranchStock;
use App\Domain\Stock\StockLot;
use App\Domain\Support\Money;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Transfers\PendingTransfers;
use App\Domain\Transfers\TransitList;
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
    /** How many of the latest transactions the home page lists. The rest are one click away, on Transactions. */
    private const LATEST = 8;

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

        // The latest transactions the signed-in person may read, in the order they were recorded, for the branch in context.
        $recent = $user->can('viewAny', Document::class)
            ? DocumentRows::from(Document::query()
                ->with(['ledger', 'branch', 'goldLine'])
                ->orderByDesc('id')
                ->limit(self::LATEST)
                ->get())
            : [];

        // Gold in stock, for the branches in context: the number an owner looks for first.
        $goldInStock = StockLot::query()->where('remaining_g', '>', 0)->sum('remaining_g');

        // The same gold split by kind, kept separate in the stock and shown here under the total. After a sale or a
        // purchase these change with the total, because they are worked out from the same lots each time.
        $byForm = StockLot::query()->where('remaining_g', '>', 0)
            ->selectRaw('form, SUM(remaining_g) as grams')->groupBy('form')->pluck('grams', 'form');
        $goldByForm = collect(['raw' => 'Raw', 'refined' => 'Refined'])
            ->map(fn (string $label, string $form) => ['label' => $label, 'grams' => Money::format(Money::fromDb($byForm[$form] ?? 0))])
            ->values();

        // An owner or admin looking at every branch: each branch's gold, and everything on the road between them.
        // Anyone working in one branch: the gold on its way to that branch, and what it sent that is not yet accepted.
        $branches = null;
        $transit = [];

        if ($context->viewingAllBranches() && $user->isTenantAdmin()) {
            $waiting = PendingTransfers::inBusiness($user->tenant_id)->orderByDesc('documents.id')->get();
            $branches = BranchStock::overview($user->accessibleBranches(), $waiting);
            $transit = TransitList::rows($waiting, null, fn (Document $d) => route('documents.show', $d->id));
        } elseif ($context->branch() !== null && $user->can('viewAny', Document::class)) {
            $office = $context->branch();
            $waiting = PendingTransfers::addressedTo($office)->get()
                ->merge(PendingTransfers::sentBy($office)->get())
                ->sortByDesc('id')->values();
            $canReceive = $user->can('receiveTransfer', Document::class);

            // Sent from here: open the transfer. Coming here: the page where a manager accepts it.
            $transit = TransitList::rows($waiting, $office, fn (Document $d, ?string $way) => $way === 'out'
                ? route('documents.show', $d->id)
                : ($canReceive ? route('transfers.incoming') : null));
        }

        return Inertia::render('Dashboard', [
            'branches' => $branches,
            'transit' => $transit,
            'canTransfer' => $user->can('create', [Document::class, 'transfer']),
            'where' => $context->viewingAllBranches() ? 'All branches' : $context->branch()?->name,
            'goldInStock' => Money::format(Money::fromDb($goldInStock)),
            'goldByForm' => $goldByForm,
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
