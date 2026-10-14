<?php

namespace App\Http\Controllers;

use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentType;
use App\Domain\Documents\PostingError;
use App\Domain\Documents\PostingService;
use App\Domain\Gold\FormulaSet;
use App\Domain\Stock\LotAllocator;
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

        // Recording belongs to one office. An owner or admin viewing all offices chooses one first.
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
            'inStock' => $type === 'sale' ? Money::format(LotAllocator::availableH($context->branch())) : null,
        ]);
    }

    public function store(SaveGoldDocumentRequest $request, string $type, PostingService $posting): RedirectResponse
    {
        $branch = app(TenantContext::class)->branch();

        try {
            $document = $type === 'sale'
                ? $posting->postSale($branch, $request->validated('date'), $request->party, $request->figures, $request->validated('note'), $request->validated('idempotency_key'), $request->user())
                : $posting->postPurchase($branch, $request->validated('date'), $request->party, $request->figures, $request->validated('note'), $request->validated('idempotency_key'), $request->user());
        } catch (PostingError $error) {
            return back()->withInput()->withErrors(['weight' => $error->getMessage()]);
        }

        return redirect()->route('documents.show', $document)
            ->with('success', DocumentType::from($type)->label()." {$document->number} was saved.");
    }
}
