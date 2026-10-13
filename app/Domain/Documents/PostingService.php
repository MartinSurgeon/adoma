<?php

namespace App\Domain\Documents;

use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use App\Domain\Support\Money;
use App\Domain\Tenancy\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Turns a transaction into a document with balanced double-entry lines.
 *
 * Each posting runs in one database transaction: the voucher number, the document and every
 * line are saved together, or none are. Debits always equal credits. A posted document is never
 * edited. A mistake is undone by reversing it, which posts the opposite entry.
 *
 * Purchases, sales and transfers will post through here too, once their gold figures are known.
 */
final class PostingService
{
    /** Who can be on the other side of a receipt or a payment. */
    private const PARTY_KINDS = [
        LedgerKind::Customer, LedgerKind::Supplier, LedgerKind::Bank,
        LedgerKind::Cash, LedgerKind::Expense, LedgerKind::Branch,
    ];

    /**
     * A receipt: money comes in. Cash or bank is debited and the other party is credited,
     * which lowers what they owe us (or raises what we owe them).
     * A payment is the opposite.
     */
    public function postCash(
        DocumentType $type,
        Branch $branch,
        string $date,
        Ledger $party,
        Ledger $cash,
        int $cents,
        ?string $note,
        ?string $idempotencyKey,
        User $by,
    ): Document {
        if (! $type->isCash()) {
            throw new PostingError('Only receipts and payments can be posted this way.');
        }

        try {
            // Three attempts: if the database cancels one of two clashing postings, it runs again.
            return DB::transaction(
                fn () => $this->postCashNow($type, $branch, $date, $party, $cash, $cents, $note, $idempotencyKey, $by),
                3,
            );
        } catch (UniqueConstraintViolationException $e) {
            // Two identical requests at once: the loser hits the unique key and gets the winner's document.
            $existing = $idempotencyKey ? $this->findByKey($branch->tenant_id, $idempotencyKey) : null;

            if ($existing === null) {
                throw $e;
            }

            return $this->sameRequestOrFail($existing, $branch, $by);
        }
    }

    /**
     * Undo a document by posting the opposite entry. The original stays, marked reversed,
     * and the two together show what happened and that it was put right.
     */
    public function reverse(Document $document, string $reason, User $by): Document
    {
        return DB::transaction(function () use ($document, $reason, $by) {
            // Lock the original, so two people reversing it at once cannot both succeed.
            $original = Document::withoutGlobalScopes()->whereKey($document->id)->lockForUpdate()->firstOrFail();

            if ($original->status !== Document::POSTED || $original->isReversal()) {
                throw new PostingError($original->isReversal()
                    ? 'A reversal cannot be reversed. Post a new document instead.'
                    : "{$original->number} has already been reversed.");
            }

            $branch = Branch::withoutGlobalScopes()->findOrFail($original->branch_id);
            $date = now()->toDateString();

            $reversal = Document::create([
                'tenant_id' => $original->tenant_id,
                'branch_id' => $original->branch_id,
                'type' => $original->type,
                'number' => VoucherNumbers::next($branch, $original->type),
                'date' => $date,
                'ledger_id' => $original->ledger_id,
                'cash_ledger_id' => $original->cash_ledger_id,
                'total' => $original->getRawOriginal('total'),
                'note' => "Reversal of {$original->number}: {$reason}",
                'status' => Document::POSTED,
                'reverses_id' => $original->id,
                'created_by' => $by->id,
            ]);

            $lines = JournalLine::withoutGlobalScopes()->where('document_id', $original->id)->orderBy('id')->get();

            // The same lines with debit and credit swapped.
            foreach ($lines as $line) {
                JournalLine::create([
                    'tenant_id' => $original->tenant_id,
                    'branch_id' => $original->branch_id,
                    'document_id' => $reversal->id,
                    'ledger_id' => $line->ledger_id,
                    'date' => $date,
                    'debit' => $line->getRawOriginal('credit'),
                    'credit' => $line->getRawOriginal('debit'),
                ]);
            }

            $original->forceFill(['status' => Document::REVERSED])->save();

            return $reversal;
        }, 3);
    }

    private function postCashNow(
        DocumentType $type,
        Branch $branch,
        string $date,
        Ledger $party,
        Ledger $cash,
        int $cents,
        ?string $note,
        ?string $idempotencyKey,
        User $by,
    ): Document {
        if ($idempotencyKey !== null && ($existing = $this->findByKey($branch->tenant_id, $idempotencyKey))) {
            return $this->sameRequestOrFail($existing, $branch, $by);
        }

        $this->assertCashPosting($branch, $date, $party, $cash, $cents);

        // [ledger, debit, credit]. The cash side and the party side are always equal and opposite.
        $lines = $type === DocumentType::Receipt
            ? [[$cash, $cents, 0], [$party, 0, $cents]]
            : [[$party, $cents, 0], [$cash, 0, $cents]];

        $this->assertBalanced($lines);

        $document = Document::create([
            'tenant_id' => $branch->tenant_id,
            'branch_id' => $branch->id,
            'type' => $type,
            'number' => VoucherNumbers::next($branch, $type),
            'date' => $date,
            'ledger_id' => $party->id,
            'cash_ledger_id' => $cash->id,
            'total' => Money::decimal($cents),
            'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
            'status' => Document::POSTED,
            'idempotency_key' => $idempotencyKey,
            'created_by' => $by->id,
        ]);

        foreach ($lines as [$ledger, $debit, $credit]) {
            JournalLine::create([
                'tenant_id' => $branch->tenant_id,
                'branch_id' => $branch->id,
                'document_id' => $document->id,
                'ledger_id' => $ledger->id,
                'date' => $date,
                'debit' => Money::decimal($debit),
                'credit' => Money::decimal($credit),
            ]);
        }

        return $document;
    }

    private function assertCashPosting(Branch $branch, string $date, Ledger $party, Ledger $cash, int $cents): void
    {
        if ($cents <= 0) {
            throw new PostingError('The amount must be more than zero.');
        }

        if (CarbonImmutable::parse($date)->isAfter(now()->endOfDay())) {
            throw new PostingError('The date cannot be in the future.');
        }

        if ($party->is($cash)) {
            throw new PostingError('The other party and the cash or bank account must be different.');
        }

        if (! in_array($cash->kind, [LedgerKind::Cash, LedgerKind::Bank], true)) {
            throw new PostingError("{$cash->name} is not a cash or bank account.");
        }

        if (! in_array($party->kind, self::PARTY_KINDS, true)) {
            throw new PostingError("{$party->name} cannot be used here.");
        }

        foreach ([$party, $cash] as $ledger) {
            if ($ledger->tenant_id !== $branch->tenant_id) {
                throw new PostingError("{$ledger->name} does not belong to this business.");
            }

            if (! $ledger->is_active) {
                throw new PostingError("{$ledger->name} is switched off.");
            }

            // An office ledger belongs to its own office only. Shared ledgers are open to all.
            if (! $ledger->is_shared && $ledger->branch_id !== $branch->id) {
                throw new PostingError("{$ledger->name} belongs to another office.");
            }
        }
    }

    /** Debits must equal credits, to the pesewa, on every document. */
    private function assertBalanced(array $lines): void
    {
        $debits = array_sum(array_column($lines, 1));
        $credits = array_sum(array_column($lines, 2));

        if ($debits !== $credits || $debits <= 0) {
            throw new PostingError('The entry does not balance, so it was not saved.');
        }
    }

    private function findByKey(int $tenantId, string $key): ?Document
    {
        return Document::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('idempotency_key', $key)->first();
    }

    /** The same person pressing Save again gets the document they already posted. Anyone else is refused. */
    private function sameRequestOrFail(Document $existing, Branch $branch, User $by): Document
    {
        if ($existing->branch_id !== $branch->id || $existing->created_by !== $by->id) {
            throw new PostingError('This request was already used. Reload the page and try again.');
        }

        return $existing;
    }
}
