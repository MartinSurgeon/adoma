<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentType;
use App\Domain\Documents\LedgerBalances;
use App\Domain\Documents\PostingError;
use App\Domain\Documents\PostingService;
use App\Domain\Stock\StockMovement;
use App\Domain\Support\AmountInWords;
use App\Domain\Support\Money;
use App\Domain\Tenancy\Branch;
use App\Domain\Tenancy\Role;
use App\Models\User;
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

        $page = Document::query()
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
            ->withQueryString();

        // A transfer out is "waiting" until the receiving branch accepts it, which writes its other half.
        $sent = $page->getCollection()
            ->filter(fn (Document $d) => $d->type === DocumentType::Transfer && $d->status === Document::POSTED && ! $d->isReversal())
            ->pluck('id')->all();
        $accepted = $sent === [] ? [] : Document::withoutGlobalScopes()->whereIn('paired_id', $sent)->pluck('paired_id')->all();
        $waitingIds = array_values(array_diff($sent, $accepted));

        $documents = $page
            ->through(fn (Document $d) => [
                'id' => $d->id,
                'waiting' => in_array($d->id, $waitingIds, true),
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
            'types' => collect(DocumentType::cases())->filter(fn ($t) => $t->isListed())
                ->map(fn ($t) => ['value' => $t->value, 'label' => $t->pluralLabel()])->values(),
            'canRecord' => $request->user()->can('create', [Document::class, 'receipt']),
        ]);
    }

    public function show(Request $request, Document $document): Response
    {
        $this->authorize('view', $document);

        $user = $request->user();

        return Inertia::render('Documents/Show', [
            ...$this->details($document, $user),
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
    private function details(Document $document, ?User $viewer = null): array
    {
        $document->load(['ledger', 'cashLedger', 'branch', 'creator', 'lines.ledger', 'reverses', 'reversal', 'goldLine.formulaSet']);

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
            'gold' => $this->gold($document),
            'transfer' => $this->transfer($document, $viewer),
            'profit' => $this->profit($document, $viewer),
            'lines' => $document->lines->map(fn ($line) => [
                'id' => $line->id,
                'ledger' => $line->ledger?->name,
                'debit' => Money::fromDb($line->debit) > 0 ? Money::format(Money::fromDb($line->debit)) : '',
                'credit' => Money::fromDb($line->credit) > 0 ? Money::format(Money::fromDb($line->credit)) : '',
            ]),
        ];
    }

    /**
     * What moved on a transfer, and the other half of it in the other branch's books. The other half is
     * linked only when this person may open it, so reading one branch's transfer never opens another's books.
     */
    private function transfer(Document $document, ?User $viewer): ?array
    {
        if (! $document->type->isTransfer()) {
            return null;
        }

        $weightH = StockMovement::withoutGlobalScopes()->where('document_id', $document->id)->get()
            ->sum(fn ($m) => abs(Money::fromDb($m->weight_g)));

        $other = $document->otherHalf();
        $mayOpen = $other !== null && $viewer !== null && $viewer->can('view', $other);

        // Where it stands. A transfer out is waiting until the receiving branch accepts it, which writes its other half.
        $state = match (true) {
            $document->isReversal() => 'reversal',
            $document->status === Document::REVERSED => 'cancelled',
            $document->type === DocumentType::TransferIn || $other !== null => 'accepted',
            default => 'waiting',
        };

        return [
            'direction' => $document->type === DocumentType::Transfer ? 'out' : 'in',
            'state' => $state,
            'weight' => Money::format($weightH),
            // The party on a transfer is the other branch's account, which is named after the branch.
            'other_office' => $document->ledger?->name,
            'other_id' => $mayOpen ? $other->id : null,
            'other_number' => $other?->number,
        ];
    }

    /** The weighing on a purchase or a sale, as text. */
    private function gold(Document $document): ?array
    {
        $line = $document->goldLine;

        if ($line === null) {
            return null;
        }

        $format = fn ($value) => $value === null ? null : Money::format(Money::fromDb($value));

        return [
            'form' => $line->form,
            'weight' => $format($line->weight_g),
            'big_weight' => $format($line->big_weight_g),
            'water_weight' => $format($line->water_weight_g),
            'pounds' => $format($line->pounds),
            'density' => $format($line->density),
            'karat' => $format($line->karat),
            // A karat above 24 that an admin allowed: who, and why.
            'karat_override' => $line->karat_overridden_by === null ? null : [
                'by' => $line->overriddenBy?->name,
                'reason' => $line->karat_override_reason,
            ],
            'price' => Money::cedis(Money::fromDb($line->price)),
            'base_karat' => $line->formulaSet->karat_base,
            'formula_version' => $line->formulaSet->version,
        ];
    }

    /**
     * What a sale earned: the amount less what its gold had cost. A transfer out is a sale to another branch, so it
     * has one too. A margin is sensitive, so only managers, admins and owners of the branch see it.
     */
    private function profit(Document $document, ?User $viewer): ?array
    {
        if ($viewer === null || ! in_array($document->type, [DocumentType::Sale, DocumentType::Transfer], true) || $document->isReversal()) {
            return null;
        }

        if (! ($viewer->roleIn($document->branch_id)?->atLeast(Role::Manager) ?? false)) {
            return null;
        }

        $cost = $document->lines
            ->filter(fn ($line) => $line->ledger?->system_key === 'cost_of_sales')
            ->sum(fn ($line) => Money::fromDb($line->debit));

        $amount = Money::cents($document->total);

        return ['cost' => Money::cedis($cost), 'profit' => Money::cedis($amount - $cost), 'is_loss' => $amount < $cost];
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
