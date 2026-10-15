<?php

namespace App\Domain\Documents;

use App\Domain\Gold\FormulaSet;
use App\Domain\Gold\GoldFigures;
use App\Domain\Gold\GoldLine;
use App\Domain\Ledgers\ChartOfAccounts;
use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use App\Domain\Stock\LotAllocator;
use App\Domain\Stock\StockLot;
use App\Domain\Stock\StockMovement;
use App\Domain\Support\Money;
use App\Domain\Tenancy\Branch;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Transfers\TransferPricing;
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
 *   Transfer  a sale from one branch to another, in two documents, one in each branch's books, so each
 *             branch's books balance by themselves.
 *     Sent, in the sending branch:       Dr the receiving branch's account   Cr Sales          (the price)
 *                                        Dr Cost of Sales                    Cr Gold Stock      (what the gold cost)
 *                                        and the gold leaves the oldest lots
 *     Accepted, in the receiving branch: Dr Gold Stock   Cr the sending branch's account       (the price)
 *                                        and the gold becomes new lots that cost that price
 *     Nothing changes in the receiving branch until it accepts. If it declines, or the sender cancels, the
 *     sender's document is reversed and the gold goes back.
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

    /** Gold bought. The supplier is owed the amount, and the branch holds a new lot. */
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
     * Gold sold by one branch to another, at a price per pound. It leaves the sending branch's oldest lots and
     * is then on its way: the receiving branch's books and stock do not change until it accepts
     * (acceptTransfer). Until then the sender can cancel it, or the receiver can decline it, and the gold
     * goes back. Returns the sending branch's document.
     *
     * The sending branch books it as a sale (the receiving branch owes the price, the price is income, and the gold's
     * cost leaves Gold Stock for Cost of Sales), priced slice by slice with the same rule as any sale. The
     * receiving branch owes that price, through the branch accounts, until it is settled with a payment.
     */
    public function postTransfer(Branch $from, Branch $to, string $date, int $weightH, int $priceH, ?string $note, ?string $idempotencyKey, User $by): Document
    {
        return $this->once($from, $idempotencyKey, $by, function () use ($from, $to, $date, $weightH, $priceH, $note, $idempotencyKey, $by) {
            $this->assertTransfer($from, $to, $date, $weightH);

            if ($priceH <= 0) {
                throw new PostingError('Enter the price per pound the gold is being sold to the other branch at.');
            }

            // The sending branch's gold is locked while it is taken out, so two sales or transfers cannot take the same gold.
            $this->lots->lockOffices([$from]);

            $taken = $this->lots->allocate($from, $weightH);   // locks the lots until we finish
            $costCents = array_sum(array_column($taken, 'costCents'));
            $values = TransferPricing::values($taken, FormulaSet::currentFor($from->tenant_id), $priceH);
            $amountCents = array_sum($values);

            if ($amountCents <= 0) {
                throw new PostingError('The amount works out to nothing. Check the weight and the price.');
            }

            $toAccount = ChartOfAccounts::ensureBranchLedger($to);

            // Booked like a sale: the other branch owes the price and the price is income, and what the gold had
            // cost moves from Gold Stock to Cost of Sales, so the sale's profit is the price less that cost.
            $lines = [[$toAccount, $amountCents, 0], [$this->systemLedger($from, 'sales'), 0, $amountCents]];

            if ($costCents > 0) {
                $lines[] = [$this->systemLedger($from, 'cost_of_sales'), $costCents, 0];
                $lines[] = [$this->systemLedger($from, 'gold_stock'), 0, $costCents];
            }

            $out = $this->write(DocumentType::Transfer, $from, $date, $toAccount, null, $amountCents, $note, $idempotencyKey, $by, $lines);

            // The sending branch's side: the gold leaves its oldest lots, each slice carrying the price it was sold at.
            foreach ($taken as $index => ['lot' => $lot, 'weightH' => $takenH, 'costCents' => $lotCost]) {
                $this->writeMovement($lot, $out, $date, -$takenH, -$lotCost, $values[$index]);

                $lot->forceFill([
                    'remaining_g' => Money::decimal(Money::fromDb($lot->remaining_g) - $takenH),
                    'remaining_cost' => Money::decimal(Money::fromDb($lot->remaining_cost) - $lotCost),
                ])->save();
            }

            // The gold is now on its way. It is in neither branch's stock until the receiving branch accepts it.
            return $out;
        });
    }

    /**
     * The receiving branch accepts gold that was sent to it. Only now does the gold arrive: the receiving
     * branch's own document is written, and the gold becomes new lots in its stock. They keep the date the
     * gold was first bought, so the oldest still sells first, and the weight, karat and cost it left with.
     * Returns the receiving branch's document.
     */
    public function acceptTransfer(Document $transfer, Branch $receiver, User $by): Document
    {
        return DB::transaction(function () use ($transfer, $receiver, $by) {
            // Lock the transfer, so two people accepting it, or one accepting while the sender cancels, cannot both win.
            $out = $this->lockedWaitingTransfer($transfer, $receiver, 'accepted');

            $from = Branch::withoutGlobalScopes()->findOrFail($out->branch_id);
            $this->lots->lockOffices([$from, $receiver]);

            $date = now()->toDateString();
            $fromAccount = ChartOfAccounts::ensureBranchLedger($from);
            $stock = $this->systemLedger($receiver, 'gold_stock');

            $moves = StockMovement::withoutGlobalScopes()->where('document_id', $out->id)->orderBy('id')->get();

            // What the receiving branch pays, and so what the gold costs it: the price each slice was sold at. A
            // transfer made before transfers were priced moved at cost, and has no price on its slices.
            $priceOf = fn ($move) => $move->value !== null ? Money::fromDb($move->value) : abs(Money::fromDb($move->cost));
            $cents = $moves->sum($priceOf);

            // Written as the receiving branch: its document, its lines and its new lots.
            return $this->asOffice($receiver, function () use ($receiver, $date, $fromAccount, $stock, $cents, $out, $by, $moves, $priceOf) {
                $in = $this->write(
                    DocumentType::TransferIn, $receiver, $date, $fromAccount, null, $cents, $out->note, null, $by,
                    [[$stock, $cents, 0], [$fromAccount, 0, $cents]],
                    $out->id,
                );

                foreach ($moves as $move) {
                    $source = StockLot::withoutGlobalScopes()->findOrFail($move->lot_id);
                    $weightH = abs(Money::fromDb($move->weight_g));
                    $cost = $priceOf($move);

                    $arrived = StockLot::create([
                        'tenant_id' => $receiver->tenant_id,
                        'branch_id' => $receiver->id,
                        'gold_line_id' => null,
                        'source_lot_id' => $source->id,
                        'document_id' => $in->id,
                        'date' => $source->date->toDateString(),
                        'form' => $source->form,
                        'karat' => $source->getRawOriginal('karat'),
                        'weight_g' => Money::decimal($weightH),
                        'remaining_g' => Money::decimal($weightH),
                        'cost' => Money::decimal($cost),
                        'remaining_cost' => Money::decimal($cost),
                    ]);

                    $this->writeMovement($arrived, $in, $date, $weightH, $cost);
                }

                return $in;
            });
        }, 3);
    }

    /**
     * The receiving branch turns gold away. The sending branch's transfer is reversed, so the gold goes back
     * into its lots, with the receiving branch's reason on the record. Returns the sending branch's reversal.
     */
    public function declineTransfer(Document $transfer, Branch $receiver, string $reason, User $by): Document
    {
        return DB::transaction(function () use ($transfer, $receiver, $reason, $by) {
            $out = $this->lockedWaitingTransfer($transfer, $receiver, 'declined');

            $from = Branch::withoutGlobalScopes()->findOrFail($out->branch_id);
            $this->lots->lockOffices([$from]);

            return $this->asOffice($from, fn () => $this->reverseOne($out, "declined by {$receiver->name}: {$reason}", $by, now()->toDateString(), null));
        }, 3);
    }

    /** A transfer out that is still waiting for this branch to answer, locked. Anything else is refused in plain words. */
    private function lockedWaitingTransfer(Document $transfer, Branch $receiver, string $verb): Document
    {
        $out = Document::withoutGlobalScopes()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();

        $toAccount = Ledger::withoutGlobalScopes()->find($out->ledger_id);

        if ($out->type !== DocumentType::Transfer || $out->isReversal()
            || $out->tenant_id !== $receiver->tenant_id || $toAccount?->linked_branch_id !== $receiver->id) {
            throw new PostingError('This transfer is not addressed to this branch.');
        }

        if ($out->status !== Document::POSTED) {
            throw new PostingError("{$out->number} was cancelled by the sending branch, so it cannot be {$verb}.");
        }

        if (Document::withoutGlobalScopes()->where('paired_id', $out->id)->lockForUpdate()->exists()) {
            throw new PostingError("{$out->number} has already been accepted.");
        }

        return $out;
    }

    /**
     * Undo a document by posting the opposite entry. The original stays, marked reversed, and the
     * two together show what happened and that it was put right. Gold goes back where it came from.
     * A transfer is two documents, so both are reversed together, or neither is.
     */
    public function reverse(Document $document, string $reason, User $by): Document
    {
        return DB::transaction(function () use ($document, $reason, $by) {
            // Lock the original, so two people reversing it at once cannot both succeed.
            $original = Document::withoutGlobalScopes()->whereKey($document->id)->lockForUpdate()->firstOrFail();

            if ($original->type === DocumentType::TransferIn) {
                throw new PostingError('A transfer is put right by the branch that sent it. Reverse the transfer out there.');
            }

            if ($original->status !== Document::POSTED || $original->isReversal()) {
                throw new PostingError($original->isReversal()
                    ? 'A reversal cannot be reversed. Post a new document instead.'
                    : "{$original->number} has already been reversed.");
            }

            $date = now()->toDateString();
            $other = null;

            if ($original->type === DocumentType::Transfer) {
                // Still waiting to be accepted: there is no other half, and cancelling undoes just this one.
                // Accepted: both halves are undone together.
                $other = Document::withoutGlobalScopes()->where('paired_id', $original->id)->lockForUpdate()->first();

                if ($other !== null && $other->status !== Document::POSTED) {
                    throw new PostingError("{$original->number} cannot be reversed, because the receiving branch's half is not in order.");
                }
            }

            $otherBranch = $other === null ? null : Branch::withoutGlobalScopes()->findOrFail($other->branch_id);

            // Putting gold back changes lots, so take the same stock lock a sale or a transfer takes, in the same order.
            if ($original->type->movesStock()) {
                $this->lots->lockOffices(array_filter([Branch::withoutGlobalScopes()->findOrFail($original->branch_id), $otherBranch]));
            }

            $reversal = $this->reverseOne($original, $reason, $by, $date, null);

            if ($other !== null) {
                $this->asOffice($otherBranch, fn () => $this->reverseOne($other, $reason, $by, $date, $reversal->id));
            }

            return $reversal;
        }, 3);
    }

    /**
     * Write as another branch of the same business, then go back to the one in view. A transfer is the one
     * posting that must write into two branches, and this keeps that deliberate: the guard that stops an
     * office writing into another's books stays on, and is told which branch the work is for.
     */
    private function asOffice(Branch $branch, Closure $work): mixed
    {
        $context = app(TenantContext::class);

        return $context->run(Tenant::findOrFail($branch->tenant_id), $branch, $work);
    }

    /** The opposite of one document: the same lines with debit and credit swapped, and any gold put back. */
    private function reverseOne(Document $original, string $reason, User $by, string $date, ?int $pairedId): Document
    {
        $branch = Branch::withoutGlobalScopes()->findOrFail($original->branch_id);

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
            'paired_id' => $pairedId,
            'created_by' => $by->id,
        ]);

        $lines = JournalLine::withoutGlobalScopes()->where('document_id', $original->id)->orderBy('id')->get();

        foreach ($lines as $line) {
            JournalLine::create([
                'tenant_id' => $original->tenant_id,
                'branch_id' => $line->branch_id,
                'document_id' => $reversal->id,
                'ledger_id' => $line->ledger_id,
                'date' => $date,
                'debit' => $line->getRawOriginal('credit'),
                'credit' => $line->getRawOriginal('debit'),
            ]);
        }

        if ($original->type->movesStock()) {
            $this->reverseStock($original, $reversal, $date);
        }

        $original->forceFill(['status' => Document::REVERSED])->save();

        return $reversal;
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
        ?int $pairedId = null,
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
            'paired_id' => $pairedId,
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
            'big_weight_g' => $figures->bigH === null ? null : Money::decimal($figures->bigH),
            'water_weight_g' => $figures->waterH === null ? null : Money::decimal($figures->waterH),
            'pounds' => Money::decimal($figures->poundsH),
            'density' => $figures->densityH === null ? null : Money::decimal($figures->densityH),
            'karat' => Money::decimal($figures->karatH),
            'price' => Money::decimal($figures->priceH),
            'amount' => Money::decimal($figures->amountCents()),
            'formula_set_id' => $figures->formula->id,
        ]);
    }

    /** $valueCents is only for the gold leaving on a transfer: the price that slice was sold at. */
    private function writeMovement(StockLot $lot, Document $document, string $date, int $weightH, int $costCents, ?int $valueCents = null): void
    {
        StockMovement::create([
            'tenant_id' => $document->tenant_id,
            // The branch that holds the lot, which for a transfer is not always the document's own branch.
            'branch_id' => $lot->branch_id,
            'lot_id' => $lot->id,
            'document_id' => $document->id,
            'weight_g' => Money::decimal($weightH),
            'cost' => Money::decimal($costCents),
            'value' => $valueCents === null ? null : Money::decimal($valueCents),
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

            // Reversing a purchase or a transfer in takes its gold back out of the lot. If some has gone since, the lot is short.
            if ($remainingH < 0 || $remainingCost < 0) {
                throw new PostingError(sprintf(
                    'Some of this gold has already been sold or sent to another branch, so this %s cannot be reversed. Reverse those first.',
                    $original->type->isTransfer() ? 'transfer' : 'purchase',
                ));
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

    private function assertTransfer(Branch $from, Branch $to, string $date, int $weightH): void
    {
        if ($from->is($to)) {
            throw new PostingError('Choose a different branch to send the gold to.');
        }

        if ($from->tenant_id !== $to->tenant_id) {
            throw new PostingError('Gold can only be sent to another branch of the same business.');
        }

        if ($weightH <= 0) {
            throw new PostingError('The weight must be more than zero.');
        }

        $this->assertDate($date);
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

        // A branch ledger belongs to its own branch only. Shared ledgers are open to all.
        if (! $ledger->is_shared && $ledger->branch_id !== $branch->id) {
            throw new PostingError("{$ledger->name} belongs to another branch.");
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
