<?php

namespace App\Http\Controllers;

use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentType;
use App\Domain\Documents\PostingError;
use App\Domain\Documents\PostingService;
use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use App\Domain\Tenancy\TenantContext;
use App\Http\Requests\SaveCashDocumentRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/** Recording a receipt (money in) or a payment (money out). The route says which. */
class CashDocumentController extends Controller
{
    private const WORDS = [
        'receipt' => ['title' => 'Record a receipt', 'party' => 'Received from', 'cash' => 'Paid into', 'button' => 'Save receipt'],
        'payment' => ['title' => 'Record a payment', 'party' => 'Paid to', 'cash' => 'Paid from', 'button' => 'Save payment'],
    ];

    public function create(Request $request, string $type): Response
    {
        $context = app(TenantContext::class);
        $words = self::WORDS[$type];

        // Recording happens in one office. An owner or admin viewing all offices chooses one first.
        if ($context->viewingAllBranches() && $request->user()->isTenantAdmin()) {
            return Inertia::render('Documents/Create', ['type' => $type, 'words' => $words, 'needsOffice' => true]);
        }

        $this->authorize('create', [Document::class, $type]);

        return Inertia::render('Documents/Create', [
            'type' => $type,
            'words' => $words,
            'needsOffice' => false,
            'office' => $context->branch()->name,
            'today' => now()->toDateString(),
            'idempotencyKey' => (string) Str::uuid(),
            'cashLedgers' => Ledger::query()
                ->whereIn('kind', [LedgerKind::Cash->value, LedgerKind::Bank->value])
                ->where('is_active', true)
                ->orderBy('name')->get()
                ->map(fn (Ledger $l) => ['id' => $l->id, 'name' => $l->name, 'kind_label' => $l->kind->label()])
                ->values(),
        ]);
    }

    public function store(SaveCashDocumentRequest $request, string $type, PostingService $posting): RedirectResponse
    {
        try {
            $document = $posting->postCash(
                DocumentType::from($type),
                app(TenantContext::class)->branch(),
                $request->validated('date'),
                $request->party,
                $request->cash,
                $request->cents,
                $request->validated('note'),
                $request->validated('idempotency_key'),
                $request->user(),
            );
        } catch (PostingError $error) {
            return back()->withInput()->withErrors(['amount' => $error->getMessage()]);
        }

        return redirect()->route('documents.show', $document)
            ->with('success', "{$document->type->label()} {$document->number} was saved.");
    }
}
