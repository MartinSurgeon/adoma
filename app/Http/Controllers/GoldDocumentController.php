<?php

namespace App\Http\Controllers;

use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentType;
use App\Domain\Documents\PostingError;
use App\Domain\Documents\PostingService;
use App\Domain\Documents\RepeatOf;
use App\Domain\Gold\FormulaSet;
use App\Domain\Stock\LotAllocator;
use App\Domain\Stock\StockLot;
use App\Domain\Support\Money;
use App\Domain\Tenancy\TenantContext;
use App\Http\Requests\SaveGoldDocumentRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/** Recording a purchase or a sale of gold. The route says which. */
class GoldDocumentController extends Controller
{
    private const WORDS = [
        'purchase' => ['title' => 'Record a purchase', 'party' => 'Bought from', 'button' => 'Save purchase', 'owes' => 'You will owe them'],
        'sale' => ['title' => 'Record a sale', 'party' => 'Sold to', 'button' => 'Save sale', 'owes' => 'They will owe you'],
    ];

    public function create(Request $request, string $type): Response
    {
        $context = app(TenantContext::class);
        $words = self::WORDS[$type];

        // Recording belongs to one branch. An owner or admin viewing all branches chooses one first.
        if ($context->viewingAllBranches() && $request->user()->isTenantAdmin()) {
            return Inertia::render('Documents/CreateGold', ['type' => $type, 'words' => $words, 'needsOffice' => true]);
        }

        $this->authorize('create', [Document::class, $type]);

        $formula = FormulaSet::currentFor($request->user()->tenant_id);

        return Inertia::render('Documents/CreateGold', [
            'type' => $type,
            'words' => $words,
            'needsOffice' => false,
            'office' => $context->branch()->name,
            'today' => now()->toDateString(),
            'idempotencyKey' => (string) Str::uuid(),
            'baseKarat' => $formula->karat_base,
            'prefill' => RepeatOf::from($request, DocumentType::from($type)),
            'inStock' => $type === 'sale' ? Money::format(LotAllocator::availableH($context->branch())) : null,
            // A sale can take the oldest gold, or the exact purchases the seller picks from this list.
            'lots' => $type === 'sale' ? $this->lotsToChooseFrom() : [],
        ]);
    }

    /** What the branch holds, oldest first: each purchase with what is left of it. Cost is not shown here. */
    private function lotsToChooseFrom(): array
    {
        return StockLot::query()->with(['goldLine.document.ledger', 'document.ledger'])
            ->where('remaining_g', '>', 0)->orderBy('date')->orderBy('id')->limit(300)->get()
            ->map(function (StockLot $lot) {
                $origin = $lot->document ?? $lot->goldLine?->document;

                return [
                    'id' => $lot->id,
                    'date' => $lot->date->format('j M Y'),
                    'number' => $origin?->number,
                    'from' => $origin?->ledger?->name,
                    'form' => ucfirst($lot->form),
                    'karat' => Money::format(Money::fromDb($lot->karat)),
                    'remaining' => Money::decimal(Money::fromDb($lot->remaining_g)),
                    'remaining_text' => Money::format(Money::fromDb($lot->remaining_g)),
                ];
            })->all();
    }

    public function store(SaveGoldDocumentRequest $request, string $type, PostingService $posting): RedirectResponse
    {
        $branch = app(TenantContext::class)->branch();

        try {
            $document = $type === 'sale'
                ? $posting->postSale($branch, $request->validated('date'), $request->party, $request->figures, $request->validated('note'), $request->validated('idempotency_key'), $request->user(), $request->picks)
                : $posting->postPurchase($branch, $request->validated('date'), $request->party, $request->figures, $request->validated('note'), $request->validated('idempotency_key'), $request->user());
        } catch (PostingError $error) {
            return back()->withInput()->withErrors([$request->choosesLots() ? 'lots' : 'weight' => $error->getMessage()]);
        }

        return redirect()->route('documents.show', $document)
            ->with('success', DocumentType::from($type)->label()." {$document->number} was saved.");
    }
}
