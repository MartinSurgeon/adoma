<?php

namespace App\Domain\Documents;

use App\Domain\Gold\GoldFigures;
use App\Domain\Gold\GoldLine;
use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use App\Domain\Stock\LotAllocator;
use App\Domain\Stock\StockLot;
use App\Domain\Stock\StockMovement;
use App\Domain\Support\Money;
use App\Domain\Tenancy\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Turns a transaction into a document with balanced double-entry lines.
 *
 * Each posting runs in one database transaction: the voucher number, the document, every line,
 * and for gold the stock too, are saved together or not at all. Debits always equal credits.
 * A posted document is never edited. A mistake is undone by reversing it, which posts the
 * opposite entry and puts any gold back.
 *
 *   Receipt   Dr cash or bank          Cr the other party
 *   Payment   Dr the other party       Cr cash or bank
 *   Purchase  Dr Gold Stock            Cr the supplier          and a new lot of gold in stock
 *   Sale      Dr the customer          Cr Sales
 *             Dr Cost of Sales         Cr Gold Stock            and gold leaves the oldest lots
 */
final class PostingService
{
    /** Who can be on the other side of a receipt or a payment. */
    private const PARTY_KINDS = [
        LedgerKind::Customer, LedgerKind::Supplier, LedgerKind::Bank,
        LedgerKind::Cash, LedgerKind::Expense, LedgerKind::Branch,
    ];

    /** Who gold can be bought from or sold to. */
    private const GOLD_PARTY_KINDS = [LedgerKind::Customer, LedgerKind::Supplier];

    public function __construct(private readonly LotAllocator $lots)
    {
    }

    /**
     * A receipt: money comes in. Cash or bank is debited and the other party is credited,
     * which lowers what they owe us (or raises what we owe them). A payment is the opposite.
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

        return $this->once($branch, $idempotencyKey, $by, function () use ($type, $branch, $date, $party, $cash, $cents, $note, $idempotencyKey, $by) {
            $this->assertCashPosting($branch, $date, $party, $cash, $cents);

            $lines = $type === DocumentType::Receipt
                ? [[$cash, $cents, 0], [$party, 0, $cents]]
                : [[$party, $cents, 0], [$cash, 0, $cents]];

            return $this->write($type, $branch, $date, $party, $cash, $cents, $note, $idempotencyKey, $by, $lines);
        });
    }

    /** Gold bought. The supplier is owed the amount, and the office holds a new lot. */
    public function postPurchase(Branch $branch, string $date, Ledger $party, GoldFigures $figures, ?string $note, ?string $idempotencyKey, User $by): Document
    {
        return $this->once($branch, $idempotencyKey, $by, function () use ($branch, $date, $party, $figures, $note, $idempotencyKey, $by) {
            $this->assertGoldPosting($branch, $date, $party, $figures);

            $cents = $figures->amountCents();
            $stock = $this->systemLedger($branch, 'gold_stock');

            $document = $this->write(
                DocumentType::Purchase, $branch, $date, $party, null, $cents, $note, $idempotencyKey, $by,
                [[$stock, $cents, 0], [$party, 0, $cents]],
            );

            $goldLine = $this->writeGoldLine($document, $figures);

            $lot = StockLot::create([
                'tenant_id' => $branch->tenant_id,
                'branch_id' => $branch->id,
                'gold_line_id' => $goldLine->id,
                'date' => $date,
                'form' => $figures->form,
                'karat' => Money::decimal($figures->karatH),
                'weight_g' => Money::decimal($figures->weightH),
                'remaining_g' => Money::decimal($figures->weightH),
                'cost' => Money::decimal($cents),
                'remaining_cost' => Money::decimal($cents),
            ]);

            $this->writeMovement($lot, $document, $date, $figures->weightH, $cents);

            return $document;
        });
    }

    /**
     * Gold sold. The customer owes the amount and the sale is income. The gold leaves the oldest
     * lots, and what that gold had cost goes from Gold Stock to Cost of Sales, so the sale's
     * profit is the amount less that cost.
     */
    public function postSale(Branch $branch, string $date, Ledger $party, GoldFigures $figures, ?string $note, ?string $idempotencyKey, User $by): Document
    {
        return $this->once($branch, $idempotencyKey, $by, function () use ($branch, $date, $party, $figures, $note, $idempotencyKey, $by) {
            $this->assertGoldPosting($branch, $date, $party, $figures);

            $cents = $figures->amountCents();
            $taken = $this->lots->allocate($branch, $figures->weightH);   // locks the lots until we finish
            $costCents = array_sum(array_column($taken, 'costCents'));

            $lines = [[$party, $cents, 0], [$this->systemLedger($branch, 'sales'), 0, $cents]];

            if ($costCents > 0) {
                $lines[] = [$this->systemLedger($branch, 'cost_of_sales'), $costCents, 0];
                $lines[] = [$this->systemLedger($branch, 'gold_stock'), 0, $costCents];
            }

            $document = $this->write(DocumentType::Sale, $branch, $date, $party, null, $cents, $note, $idempotencyKey, $by, $lines);

            $this->writeGoldLine($document, $figures);

            foreach ($taken as ['lot' => $lot, 'weightH' => $weightH, 'costCents' => $lotCost]) {
                $this->writeMovement($lot, $document, $date, -$weightH, -$lotCost);

                $lot->forceFill([
                    'remaining_g' => Money::decimal(Money::fromDb($lot->remaining_g) - $weightH),
                    'remaining_cost' => Money::decimal(Money::fromDb($lot->remaining_cost) - $lotCost),
                ])->save();
            }

            return $document;
        });
    }

    /**
     * Undo a document by posting the opposite entry. The original stays, marked reversed, and the
     * two together show what happened and that it was put right. Gold goes back where it came from.
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

            if ($original->type->isGold()) {
                $this->reverseStock($original, $reversal, $date);
            }

            $original->forceFill(['status' => Document::REVERSED])->save();

            return $reversal;
        }, 3);
    }

    // ------------------------------------------------------------------ writing

    /** The shared part: number it, save the document and its balanced lines. */
    private function write(
        DocumentType $type,
        Branch $branch,
        string $date,
        Ledger $party,
        ?Ledger $cash,
        int $cents,
        ?string $note,
        ?string $idempotencyKey,
        User $by,
        array $lines,
    ): Document {
        $this->assertBalanced($lines);

        $document = Document::create([
            'tenant_id' => $branch->tenant_id,
            'branch_id' => $branch->id,
            'type' => $type,
            'number' => VoucherNumbers::next($branch, $type),
            'date' => $date,
            'ledger_id' => $party->id,
            'cash_ledger_id' => $cash?->id,
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

    private function writeGoldLine(Document $document, GoldFigures $figures): GoldLine
    {
        return GoldLine::create([
            'tenant_id' => $document->tenant_id,
            'branch_id' => $document->branch_id,
            'document_id' => $document->id,
            'form' => $figures->form,
            'weight_g' => Money::decimal($figures->weightH),
            'water_weight_g' => $figures->waterH === null ? null : Money::decimal($figures->waterH),
            'pounds' => Money::decimal($figures->poundsH),
            'density' => $figures->densityH === null ? null : Money::decimal($figures->densityH),
            'karat' => Money::decimal($figures->karatH),
            'price' => Money::decimal($figures->priceH),
            'amount' => Money::decimal($figures->amountCents()),
            'formula_set_id' => $figures->formula->id,
        ]);
    }

    private function writeMovement(StockLot $lot, Document $document, string $date, int $weightH, int $costCents): void
    {
        StockMovement::create([
            'tenant_id' => $document->tenant_id,
            'branch_id' => $document->branch_id,
            'lot_id' => $lot->id,
            'document_id' => $document->id,
            'weight_g' => Money::decimal($weightH),
            'cost' => Money::decimal($costCents),
            'date' => $date,
        ]);
    }

    /** Put the gold back the way the original document moved it, with the opposite sign. */
    private function reverseStock(Document $original, Document $reversal, string $date): void
    {
        $movements = StockMovement::withoutGlobalScopes()->where('document_id', $original->id)->orderBy('id')->get();

        foreach ($movements as $movement) {
            $lot = StockLot::withoutGlobalScopes()->whereKey($movement->lot_id)->lockForUpdate()->firstOrFail();

            $weightH = Money::fromDb($movement->weight_g);
            $costCents = Money::fromDb($movement->cost);
            $remainingH = Money::fromDb($lot->remaining_g) - $weightH;
            $remainingCost = Money::fromDb($lot->remaining_cost) - $costCents;

            // Reversing a purchase takes its gold back out of the lot. If some was sold, the lot is short.
            if ($remainingH < 0 || $remainingCost < 0) {
                throw new PostingError('Some of this gold has already been sold, so this purchase cannot be reversed. Reverse those sales first.');
            }

            if ($remainingH > Money::fromDb($lot->weight_g)) {
                throw new PostingError('This gold cannot be put back, because the lot would hold more than it ever received.');
            }

            $this->writeMovement($lot, $reversal, $date, -$weightH, -$costCents);

            $lot->forceFill([
                'remaining_g' => Money::decimal($remainingH),
                'remaining_cost' => Money::decimal($remainingCost),
            ])->save();
        }
    }

    // --------------------------------------------------------------- idempotency

    /**
     * Run a posting once per request key. A second request with the same key gets the document
     * the first one made. Two arriving at once are sorted out by the unique key in the database.
     */
    private function once(Branch $branch, ?string $key, User $by, Closure $work): Document
    {
        try {
            // Three attempts: if the database cancels one of two clashing postings, it runs again.
            return DB::transaction(function () use ($branch, $key, $by, $work) {
                if ($key !== null && ($existing = $this->findByKey($branch->tenant_id, $key))) {
                    return $this->sameRequestOrFail($existing, $branch, $by);
                }

                return $work();
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            $existing = $key ? $this->findByKey($branch->tenant_id, $key) : null;

            if ($existing === null) {
                throw $e;
            }

            return $this->sameRequestOrFail($existing, $branch, $by);
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

    // ---------------------------------------------------------------- the rules

    private function assertCashPosting(Branch $branch, string $date, Ledger $party, Ledger $cash, int $cents): void
    {
        if ($cents <= 0) {
            throw new PostingError('The amount must be more than zero.');
        }

        $this->assertDate($date);

        if ($party->is($cash)) {
            throw new PostingError('The other party and the cash or bank account must be different.');
        }

        if (! in_array($cash->kind, [LedgerKind::Cash, LedgerKind::Bank], true)) {
            throw new PostingError("{$cash->name} is not a cash or bank account.");
        }

        if (! in_array($party->kind, self::PARTY_KINDS, true)) {
            throw new PostingError("{$party->name} cannot be used here.");
        }

        $this->assertUsable($party, $branch);
        $this->assertUsable($cash, $branch);
    }

    private function assertGoldPosting(Branch $branch, string $date, Ledger $party, GoldFigures $figures): void
    {
        if ($figures->amountCedis <= 0) {
            throw new PostingError('The amount must be more than zero.');
        }

        if ($figures->formula->tenant_id !== $branch->tenant_id) {
            throw new PostingError('These figures were worked out with another business\'s formulas.');
        }

        $this->assertDate($date);

        if (! in_array($party->kind, self::GOLD_PARTY_KINDS, true)) {
            throw new PostingError("{$party->name} is not a customer or supplier, so gold cannot be bought from or sold to it.");
        }

        $this->assertUsable($party, $branch);
    }

    private function assertDate(string $date): void
    {
        if (CarbonImmutable::parse($date)->isAfter(now()->endOfDay())) {
            throw new PostingError('The date cannot be in the future.');
        }
    }

    private function assertUsable(Ledger $ledger, Branch $branch): void
    {
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

    /** Debits must equal credits, to the pesewa, on every document. */
    private function assertBalanced(array $lines): void
    {
        $debits = array_sum(array_column($lines, 1));
        $credits = array_sum(array_column($lines, 2));

        if ($debits !== $credits || $debits <= 0) {
            throw new PostingError('The entry does not balance, so it was not saved.');
        }
    }

    /** Gold Stock, Sales or Cost of Sales: the shared system accounts every business has. */
    private function systemLedger(Branch $branch, string $key): Ledger
    {
        return Ledger::withoutGlobalScopes()
            ->where('tenant_id', $branch->tenant_id)
            ->where('system_key', $key)
            ->firstOrFail();
    }
}
