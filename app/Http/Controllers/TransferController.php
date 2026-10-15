<?php

namespace App\Http\Controllers;

use App\Domain\Documents\Document;
use App\Domain\Documents\PostingError;
use App\Domain\Documents\PostingService;
use App\Domain\Gold\FormulaSet;
use App\Domain\Gold\GoldCalculator;
use App\Domain\Stock\LotAllocator;
use App\Domain\Support\Money;
use App\Domain\Tenancy\Branch;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Transfers\PendingTransfers;
use App\Domain\Transfers\TransferPricing;
use App\Http\Requests\SaveTransferRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Inertia\Inertia;
use Inertia\Response;

/** Sending gold from the branch in view to another branch. Managers and above only. */
class TransferController extends Controller
{
    public function create(Request $request): Response
    {
        $context = app(TenantContext::class);

        // Sending belongs to one branch. An owner or admin viewing all branches chooses one first.
        if ($context->viewingAllBranches() && $request->user()->isTenantAdmin()) {
            return Inertia::render('Transfers/Create', ['needsOffice' => true]);
        }

        $this->authorize('create', [Document::class, 'transfer']);

        $from = $context->branch();

        return Inertia::render('Transfers/Create', [
            'needsOffice' => false,
            'office' => $from->name,
            'today' => now()->toDateString(),
            'idempotencyKey' => (string) Str::uuid(),
            'inStock' => Money::format(LotAllocator::availableH($from)),
            'baseKarat' => FormulaSet::currentFor($from->tenant_id)->karat_base,
            'offices' => Branch::query()->where('id', '!=', $from->id)->orderBy('name')->get()
                ->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->name])->values(),
        ]);
    }

    /** What the gold would cost and whether there is enough of it. Reads only, saves nothing. */
    public function quote(Request $request): JsonResponse
    {
        $this->authorize('create', [Document::class, 'transfer']);

        $from = app(TenantContext::class)->branch();
        $available = LotAllocator::availableH($from);

        try {
            $weightH = Money::cents($request->input('weight'));
        } catch (InvalidArgumentException) {
            return response()->json(['ok' => false, 'message' => 'Enter the weight in grams as a number, for example 250 or 250.50.']);
        }

        if ($weightH <= 0) {
            return response()->json(['ok' => false, 'message' => 'The weight must be more than zero.']);
        }

        try {
            $taken = app(LotAllocator::class)->allocate($from, $weightH, lock: false);
        } catch (PostingError $error) {
            return response()->json(['ok' => false, 'enough' => false, 'in_stock' => Money::format($available), 'message' => $error->getMessage()]);
        }

        $costCents = array_sum(array_column($taken, 'costCents'));
        $response = [
            'ok' => true,
            'enough' => true,
            'weight' => Money::format($weightH),
            'cost' => Money::cedis($costCents),
            'lots' => count($taken),
            'in_stock' => Money::format($available),
            'amount' => null,
            'profit' => null,
        ];

        // With a price, the amount it comes to and what the sending branch makes on it: the same rule that saving uses.
        $price = trim((string) $request->input('price'));

        if ($price !== '') {
            try {
                $priceH = Money::cents($price);
            } catch (InvalidArgumentException) {
                return response()->json(['ok' => false, 'field' => 'price', 'message' => 'Enter the price as a number, for example 10600.']);
            }

            if ($priceH <= 0 || $priceH > GoldCalculator::MAX_PRICE * 100) {
                return response()->json(['ok' => false, 'field' => 'price', 'message' => 'The price must be more than zero and not a typing mistake.']);
            }

            $amountCents = array_sum(TransferPricing::values($taken, FormulaSet::currentFor($from->tenant_id), $priceH));
            $response['amount'] = Money::cedis($amountCents);
            $response['amount_cents'] = $amountCents;
            $response['profit'] = Money::cedis($amountCents - $costCents);
            $response['is_loss'] = $amountCents < $costCents;
        }

        return response()->json($response);
    }

    public function store(SaveTransferRequest $request, PostingService $posting): RedirectResponse
    {
        try {
            $document = $posting->postTransfer(
                app(TenantContext::class)->branch(),
                $request->toBranch,
                $request->validated('date'),
                $request->weightH,
                $request->priceH,
                $request->validated('note'),
                $request->validated('idempotency_key'),
                $request->user(),
            );
        } catch (PostingError $error) {
            return back()->withInput()->withErrors(['weight' => $error->getMessage()]);
        }

        return redirect()->route('documents.show', $document)
            ->with('success', sprintf(
                '%s was sent. %s g for %s is waiting for %s to accept it.',
                $document->number, Money::format($request->weightH), Money::cedis(Money::cents($document->total)), $request->toBranch->name,
            ));
    }

    /** Gold sent to this branch and waiting for it to accept or decline. */
    public function incoming(Request $request): Response
    {
        $this->authorize('receiveTransfer', Document::class);

        $office = app(TenantContext::class)->branch();
        $waiting = PendingTransfers::addressedTo($office)->orderBy('documents.date')->orderBy('documents.id')->get();
        $figures = PendingTransfers::figures($waiting);
        $senders = Branch::withoutGlobalScopes()->whereIn('id', $waiting->pluck('branch_id')->unique())->pluck('name', 'id');

        return Inertia::render('Transfers/Incoming', [
            'office' => $office->name,
            'transfers' => $waiting->map(fn (Document $d) => [
                'id' => $d->id,
                'number' => $d->number,
                'date' => $d->date->toDateString(),
                'from' => $senders[$d->branch_id] ?? '',
                'note' => $d->note,
                'weight' => Money::format($figures[$d->id]['weightH']),
                // What this branch will owe. The sending branch's own cost is not for this branch to see.
                'amount' => Money::cedis($figures[$d->id]['valueCents']),
                'lots' => $figures[$d->id]['lots'],
            ])->values(),
        ]);
    }

    public function accept(Request $request, int $transfer, PostingService $posting): RedirectResponse
    {
        $this->authorize('receiveTransfer', Document::class);

        $office = app(TenantContext::class)->branch();
        $document = PendingTransfers::addressedTo($office)->whereKey($transfer)->first();

        // Another branch's transfer, or one already answered, is simply not there for this branch.
        abort_if($document === null, 404);

        try {
            $received = $posting->acceptTransfer($document, $office, $request->user());
        } catch (PostingError $error) {
            return redirect()->route('transfers.incoming')->withErrors(['transfer' => $error->getMessage()]);
        }

        $weight = Money::format(PendingTransfers::figures([$document])[$document->id]['weightH']);

        return redirect()->route('documents.show', $received)
            ->with('success', sprintf('Accepted. %s g from %s is now in %s\'s stock.', $weight, Branch::withoutGlobalScopes()->find($document->branch_id)?->name, $office->name));
    }

    public function decline(Request $request, int $transfer, PostingService $posting): RedirectResponse
    {
        $this->authorize('receiveTransfer', Document::class);

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']], [
            'reason.required' => 'Say why you are declining it, so the sending branch knows.',
            'reason.min' => 'Say why you are declining it, so the sending branch knows.',
        ]);

        $office = app(TenantContext::class)->branch();
        $document = PendingTransfers::addressedTo($office)->whereKey($transfer)->first();

        abort_if($document === null, 404);

        try {
            $posting->declineTransfer($document, $office, $data['reason'], $request->user());
        } catch (PostingError $error) {
            return redirect()->route('transfers.incoming')->withErrors(['transfer' => $error->getMessage()]);
        }

        return redirect()->route('transfers.incoming')
            ->with('success', "{$document->number} was declined. The gold goes back to the sending branch.");
    }
}
