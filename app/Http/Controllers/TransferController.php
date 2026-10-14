<?php

namespace App\Http\Controllers;

use App\Domain\Documents\Document;
use App\Domain\Documents\PostingError;
use App\Domain\Documents\PostingService;
use App\Domain\Stock\LotAllocator;
use App\Domain\Support\Money;
use App\Domain\Tenancy\Branch;
use App\Domain\Tenancy\TenantContext;
use App\Http\Requests\SaveTransferRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Inertia\Inertia;
use Inertia\Response;

/** Sending gold from the office in view to another office. Managers and above only. */
class TransferController extends Controller
{
    public function create(Request $request): Response
    {
        $context = app(TenantContext::class);

        // Sending belongs to one office. An owner or admin viewing all offices chooses one first.
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

        return response()->json([
            'ok' => true,
            'enough' => true,
            'weight' => Money::format($weightH),
            'cost' => Money::cedis(array_sum(array_column($taken, 'costCents'))),
            'lots' => count($taken),
            'in_stock' => Money::format($available),
        ]);
    }

    public function store(SaveTransferRequest $request, PostingService $posting): RedirectResponse
    {
        try {
            $document = $posting->postTransfer(
                app(TenantContext::class)->branch(),
                $request->toBranch,
                $request->validated('date'),
                $request->weightH,
                $request->validated('note'),
                $request->validated('idempotency_key'),
                $request->user(),
            );
        } catch (PostingError $error) {
            return back()->withInput()->withErrors(['weight' => $error->getMessage()]);
        }

        return redirect()->route('documents.show', $document)
            ->with('success', sprintf('%s was saved. %s g is now at %s.', $document->number, Money::format($request->weightH), $request->toBranch->name));
    }
}
