<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentType;
use App\Domain\Documents\LedgerBalances;
use App\Domain\Documents\PostingError;
use App\Domain\Documents\PostingService;
use App\Domain\Support\AmountInWords;
use App\Domain\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** Finding, reading, printing and reversing transactions. */
class DocumentController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Document::class);

        $filters = [
            'type' => DocumentType::tryFrom((string) $request->query('type'))?->value ?? '',
            'status' => in_array($request->query('status'), [Document::POSTED, Document::REVERSED], true) ? $request->query('status') : '',
            'from' => $this->date($request->query('from')),
            'to' => $this->date($request->query('to')),
            'q' => trim((string) $request->query('q', '')),
        ];

        $documents = Document::query()
            ->with(['ledger', 'branch'])
            ->when($filters['type'], fn ($q, $v) => $q->where('type', $v))
            ->when($filters['status'], fn ($q, $v) => $q->where('status', $v))
            ->when($filters['from'], fn ($q, $v) => $q->where('date', '>=', $v))
            ->when($filters['to'], fn ($q, $v) => $q->where('date', '<=', $v))
            ->when($filters['q'] !== '', fn ($q) => $q->where(
                fn ($w) => $w->where('number', 'like', '%'.$filters['q'].'%')
                    ->orWhereHas('ledger', fn ($l) => $l->where('name', 'like', '%'.$filters['q'].'%'))
            ))
            ->orderByDesc('date')->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Document $d) => [
                'id' => $d->id,
                'number' => $d->number,
                'type_label' => $d->type->label(),
                'date' => $d->date->toDateString(),
                'party' => $d->ledger?->name,
                'office' => $d->branch?->name,
                'total' => Money::format(Money::cents($d->total)),
                'status' => $d->status,
                'is_reversal' => $d->isReversal(),
            ]);

        return Inertia::render('Documents/Index', [
            'documents' => $documents,
            'filters' => $filters,
            'types' => collect(DocumentType::cases())->filter(fn ($t) => $t->isCash())
                ->map(fn ($t) => ['value' => $t->value, 'label' => $t->label().'s'])->values(),
            'canRecord' => $request->user()->can('create', [Document::class, 'receipt']),
        ]);
    }

    public function show(Request $request, Document $document): Response
    {
        $this->authorize('view', $document);

        $user = $request->user();

        return Inertia::render('Documents/Show', [
            ...$this->details($document),
            'canReverse' => $user->can('reverse', $document),
            'canRecordAnother' => $user->can('create', [Document::class, $document->type->value]),
            'history' => $user->can('viewAny', AuditLog::class)
                ? AuditLog::query()->where('auditable_type', Document::class)->where('auditable_id', $document->id)
                    ->orderBy('id')->get()->map(fn (AuditLog $log) => [
                        'id' => $log->id,
                        'actor' => $log->actor_name ?? 'The system',
                        'description' => $log->describe(),
                        'when' => $log->created_at->toIso8601String(),
                    ])
                : null,
        ]);
    }

    public function print(Request $request, Document $document): Response
    {
        $this->authorize('view', $document);

        return Inertia::render('Documents/Print', [
            ...$this->details($document),
            'layout' => $request->query('layout') === 'thermal' ? 'thermal' : 'a4',
            'business' => $document->tenant?->name,
        ]);
    }

    public function reverse(Request $request, Document $document, PostingService $posting): RedirectResponse
    {
        $this->authorize('reverse', $document);

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']], [
            'reason.required' => 'Say why you are reversing it, so the record makes sense later.',
            'reason.min' => 'Say why you are reversing it, so the record makes sense later.',
        ]);

        try {
            $reversal = $posting->reverse($document, $data['reason'], $request->user());
        } catch (PostingError $error) {
            return back()->withErrors(['reason' => $error->getMessage()]);
        }

        return redirect()->route('documents.show', $reversal)
            ->with('success', "{$document->number} was reversed. This is the reversing entry, {$reversal->number}.");
    }

    /** Everything a screen needs to show one document, with amounts already formatted. */
    private function details(Document $document): array
    {
        $document->load(['ledger', 'cashLedger', 'branch', 'creator', 'lines.ledger', 'reverses', 'reversal']);

        $cents = Money::cents($document->total);
        $balance = $document->ledger
            ? LedgerBalances::describe($document->ledger->kind, LedgerBalances::cents([$document->ledger->id])[$document->ledger->id])
            : null;

        return [
            'document' => [
                'id' => $document->id,
                'type' => $document->type->value,
                'type_label' => $document->type->label(),
                'number' => $document->number,
                'date' => $document->date->toDateString(),
                'office' => $document->branch?->name,
                'status' => $document->status,
                'total' => Money::cedis($cents),
                'total_in_words' => AmountInWords::cedis($cents),
                'note' => $document->note,
                'recorded_by' => $document->creator?->name,
                'party' => $document->ledger ? ['id' => $document->ledger->id, 'name' => $document->ledger->name, 'kind_label' => $document->ledger->kind->label()] : null,
                'cash' => $document->cashLedger ? ['id' => $document->cashLedger->id, 'name' => $document->cashLedger->name] : null,
                'reverses' => $document->reverses ? ['id' => $document->reverses->id, 'number' => $document->reverses->number] : null,
                'reversed_by' => $document->reversal ? ['id' => $document->reversal->id, 'number' => $document->reversal->number] : null,
            ],
            'party_balance' => $balance,
            'lines' => $document->lines->map(fn ($line) => [
                'id' => $line->id,
                'ledger' => $line->ledger?->name,
                'debit' => Money::fromDb($line->debit) > 0 ? Money::format(Money::fromDb($line->debit)) : '',
                'credit' => Money::fromDb($line->credit) > 0 ? Money::format(Money::fromDb($line->credit)) : '',
            ]),
        ];
    }

    private function date(mixed $value): string
    {
        try {
            return $value ? CarbonImmutable::createFromFormat('Y-m-d', (string) $value)->toDateString() : '';
        } catch (Throwable) {
            return '';
        }
    }
}
