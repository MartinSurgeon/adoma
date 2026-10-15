<?php

namespace App\Http\Controllers;

use App\Domain\Documents\Document;
use App\Domain\Stock\StockLot;
use App\Domain\Support\Money;
use App\Domain\Tenancy\Role;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Transfers\PendingTransfers;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The gold a branch holds, lot by lot, oldest first: what each parcel is, where it came from and
 * how much is left. This is the list a sale draws from. What the gold cost is shown to managers and
 * above only, because cost reveals the margin.
 */
class StockController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $this->authorize('viewAny', Document::class);

        $user = $request->user();
        $context = app(TenantContext::class);

        $query = StockLot::query()->where('remaining_g', '>', 0);

        $totals = (clone $query)->selectRaw('SUM(remaining_g) as grams, SUM(remaining_cost) as cost, COUNT(*) as lots')->first();

        $lots = $query
            ->with(['branch', 'goldLine.document.ledger', 'document.ledger'])
            ->orderBy('date')->orderBy('id')
            ->paginate(25)
            ->through(function (StockLot $lot) {
                // A lot comes from a purchase, or from another branch's transfer.
                $origin = $lot->document ?? $lot->goldLine?->document;

                return [
                    'id' => $lot->id,
                    'date' => $lot->date->toDateString(),
                    'office' => $lot->branch?->name,
                    'origin_id' => $origin?->id,
                    'origin_number' => $origin?->number,
                    'origin_kind' => $lot->document ? 'Transfer from' : 'Bought from',
                    'origin_name' => $origin?->ledger?->name,
                    'form' => ucfirst($lot->form),
                    'karat' => Money::format(Money::fromDb($lot->karat)),
                    'weight' => Money::format(Money::fromDb($lot->weight_g)),
                    'remaining' => Money::format(Money::fromDb($lot->remaining_g)),
                    'cost' => Money::cedis(Money::fromDb($lot->remaining_cost)),
                ];
            });

        $canSeeCost = $user->isTenantAdmin()
            || ($context->branchId() !== null && ($user->roleIn($context->branchId())?->atLeast(Role::Manager) ?? false));

        // Gold sent and not yet accepted is in neither branch's stock. Show it, so it is never "lost" on screen.
        $office = $context->viewingAllBranches() ? null : $context->branch();
        $sent = $office ? PendingTransfers::summary(PendingTransfers::sentBy($office)) : null;
        $incoming = $office && $canSeeCost ? PendingTransfers::summary(PendingTransfers::addressedTo($office)) : null;

        return Inertia::render('Stock/Index', [
            'onTheWay' => [
                'sent' => $sent && $sent['count'] ? ['count' => $sent['count'], 'grams' => Money::format($sent['weightH'])] : null,
                'incoming' => $incoming && $incoming['count'] ? ['count' => $incoming['count'], 'grams' => Money::format($incoming['weightH'])] : null,
            ],
            'where' => $context->viewingAllBranches() ? 'All branches' : $context->branch()?->name,
            'showOffice' => $context->viewingAllBranches(),
            'lots' => $lots->through(function (array $row) use ($canSeeCost) {
                return $canSeeCost ? $row : array_merge($row, ['cost' => null]);
            }),
            'totals' => [
                'grams' => Money::format(Money::fromDb($totals->grams ?? 0)),
                'cost' => $canSeeCost ? Money::cedis(Money::fromDb($totals->cost ?? 0)) : null,
                'lots' => (int) ($totals->lots ?? 0),
            ],
            'canSeeCost' => $canSeeCost,
            'canTransfer' => $user->can('create', [Document::class, 'transfer']),
        ]);
    }
}
