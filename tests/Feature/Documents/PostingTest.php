<?php

use App\Domain\Audit\AuditLog;
use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentType;
use App\Domain\Documents\JournalLine;
use App\Domain\Documents\LedgerBalances;
use App\Domain\Documents\PostingError;
use App\Domain\Documents\PostingService;
use App\Domain\Ledgers\ChartOfAccounts;
use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use App\Domain\Support\Money;
use App\Domain\Tenancy\Branch;
use App\Domain\Tenancy\TenancyViolation;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\World;

beforeEach(function () {
    $this->w = World::build();

    // Ledgers first, with no context, then work as the Kumasi clerk in Kumasi.
    $this->customer = postLedger($this->w->kumasi, 'Passion Gold', LedgerKind::Customer);
    $this->supplier = postLedger($this->w->kumasi, 'Black', LedgerKind::Supplier);
    $this->cash = postLedger($this->w->kumasi, 'Cash Kumasi', LedgerKind::Cash);
    $this->bank = postLedger($this->w->kumasi, 'Kumasi Bank', LedgerKind::Bank);
    $this->centralCustomer = postLedger($this->w->central, 'Central Customer', LedgerKind::Customer);
    $this->otherCustomer = postLedger($this->w->otherBranch, 'Other Co Customer', LedgerKind::Customer);

    $this->ctx = app(TenantContext::class);
    $this->ctx->set($this->w->adoma, $this->w->kumasi);
    $this->clerk = $this->w->kumasiClerk;
    $this->posting = app(PostingService::class);
});

function postLedger(Branch $branch, string $name, LedgerKind $kind): Ledger
{
    return Ledger::create([
        'tenant_id' => $branch->tenant_id, 'branch_id' => $branch->id, 'is_shared' => false,
        'group_id' => ChartOfAccounts::groupFor($branch->tenant_id, $kind)->id, 'kind' => $kind, 'name' => $name,
    ]);
}

function receipt(object $t, int $cents = 100000, ?Ledger $party = null, ?Ledger $cash = null, ?string $key = null, ?string $date = null): Document
{
    return $t->posting->postCash(DocumentType::Receipt, $t->w->kumasi, $date ?? now()->toDateString(), $party ?? $t->customer, $cash ?? $t->cash, $cents, 'Part payment', $key, $t->clerk);
}

function payment(object $t, int $cents = 50000, ?Ledger $party = null, ?Ledger $cash = null): Document
{
    return $t->posting->postCash(DocumentType::Payment, $t->w->kumasi, now()->toDateString(), $party ?? $t->supplier, $cash ?? $t->cash, $cents, null, null, $t->clerk);
}

function balanceOf(Ledger $ledger): int
{
    return LedgerBalances::cents([$ledger->id])[$ledger->id];
}

function lineSummary(Document $document): array
{
    return JournalLine::withoutGlobalScopes()->where('document_id', $document->id)->orderBy('id')->get()
        ->map(fn ($l) => [$l->ledger->name, Money::fromDb($l->debit), Money::fromDb($l->credit)])->all();
}

// -------------------------------------------------------------------- receipts

it('posts a receipt: cash is debited and the customer is credited', function () {
    $document = receipt($this);

    expect(lineSummary($document))->toBe([['Cash Kumasi', 100000, 0], ['Passion Gold', 0, 100000]])
        ->and(balanceOf($this->cash))->toBe(100000)
        ->and(balanceOf($this->customer))->toBe(-100000);
});

it('posts a payment: the supplier is debited and cash is credited', function () {
    $document = payment($this);

    expect(lineSummary($document))->toBe([['Black', 50000, 0], ['Cash Kumasi', 0, 50000]])
        ->and(balanceOf($this->supplier))->toBe(50000)
        ->and(balanceOf($this->cash))->toBe(-50000);
});

it('saves the document with its number, date, total, parties, note and who posted it', function () {
    $document = receipt($this, 123456, date: '2026-03-15');

    expect($document->number)->toBe('REC-KUM-000001')
        ->and($document->type)->toBe(DocumentType::Receipt)
        ->and($document->date->toDateString())->toBe('2026-03-15')
        ->and($document->total)->toBe('1234.56')
        ->and($document->ledger_id)->toBe($this->customer->id)
        ->and($document->cash_ledger_id)->toBe($this->cash->id)
        ->and($document->note)->toBe('Part payment')
        ->and($document->status)->toBe(Document::POSTED)
        ->and($document->created_by)->toBe($this->clerk->id)
        ->and($document->branch_id)->toBe($this->w->kumasi->id)
        ->and($document->tenant_id)->toBe($this->w->adoma->id);
});

it('always balances: debits equal credits on every document', function () {
    foreach ([1, 99, 100, 12345, 999999999] as $cents) {
        $document = receipt($this, $cents);
        $lines = JournalLine::withoutGlobalScopes()->where('document_id', $document->id)->get();

        expect($lines->sum(fn ($l) => Money::fromDb($l->debit)))->toBe($lines->sum(fn ($l) => Money::fromDb($l->credit)))
            ->and($lines->sum(fn ($l) => Money::fromDb($l->debit)))->toBe($cents);
    }
});

it('keeps balances exact to the pesewa across many small postings', function () {
    foreach (range(1, 50) as $i) {
        receipt($this, 10); // GH₵ 0.10 fifty times
    }
    receipt($this, 20);

    expect(balanceOf($this->customer))->toBe(-(50 * 10 + 20))->and(balanceOf($this->cash))->toBe(520);
});

it('numbers each type separately and one after another', function () {
    expect(receipt($this)->number)->toBe('REC-KUM-000001')
        ->and(receipt($this)->number)->toBe('REC-KUM-000002')
        ->and(payment($this)->number)->toBe('PV-KUM-000001')
        ->and(receipt($this)->number)->toBe('REC-KUM-000003');
});

it('can pay into a bank as well as cash', function () {
    receipt($this, 100000, cash: $this->bank);

    expect(balanceOf($this->bank))->toBe(100000)->and(balanceOf($this->cash))->toBe(0);
});

it('lets a customer end up owing nothing, then being owed, after receipts and payments', function () {
    // A payment to a customer raises what they owe us; a receipt lowers it.
    payment($this, 30000, $this->customer);
    expect(balanceOf($this->customer))->toBe(30000);

    receipt($this, 30000, $this->customer);
    expect(balanceOf($this->customer))->toBe(0);
});

// ---------------------------------------------------------------- idempotency

it('posts once however many times the same request arrives', function () {
    $key = (string) Str::uuid();

    $first = receipt($this, key: $key);
    $second = receipt($this, key: $key);
    $third = receipt($this, key: $key);

    expect($second->id)->toBe($first->id)->and($third->id)->toBe($first->id)
        ->and(Document::count())->toBe(1)
        ->and(JournalLine::count())->toBe(2)
        ->and(balanceOf($this->cash))->toBe(100000);
});

it('does not use up a voucher number when a request is repeated', function () {
    $key = (string) Str::uuid();
    receipt($this, key: $key);
    receipt($this, key: $key);

    expect(receipt($this)->number)->toBe('REC-KUM-000002');
});

it('refuses a request key that someone else already used', function () {
    $key = (string) Str::uuid();
    receipt($this, key: $key);

    expect(fn () => $this->posting->postCash(
        DocumentType::Receipt, $this->w->kumasi, now()->toDateString(), $this->customer, $this->cash, 5000, null, $key, $this->w->kumasiManager,
    ))->toThrow(PostingError::class, 'already used');
});

it('lets two different requests post separately', function () {
    receipt($this, key: (string) Str::uuid());
    receipt($this, key: (string) Str::uuid());

    expect(Document::count())->toBe(2);
});

// ----------------------------------------------------------------- refusals

it('refuses an amount that is not more than zero', function (int $cents) {
    expect(fn () => receipt($this, $cents))->toThrow(PostingError::class, 'more than zero');
    expect(Document::count())->toBe(0);
})->with([0, -100]);

it('refuses a date in the future', function () {
    expect(fn () => receipt($this, date: now()->addDays(3)->toDateString()))->toThrow(PostingError::class, 'future');
});

it('accepts today and past dates', function () {
    expect(receipt($this, date: now()->toDateString()))->toBeInstanceOf(Document::class)
        ->and(receipt($this, date: '2020-01-31')->date->toDateString())->toBe('2020-01-31');
});

it('refuses the same ledger on both sides', function () {
    expect(fn () => receipt($this, party: $this->cash, cash: $this->cash))->toThrow(PostingError::class, 'different');
});

it('refuses a cash side that is not a cash or bank account', function () {
    expect(fn () => receipt($this, cash: $this->supplier))->toThrow(PostingError::class, 'not a cash or bank account');
});

it('refuses system accounts as the other party', function () {
    $sales = Ledger::withoutGlobalScopes()->where('tenant_id', $this->w->adoma->id)->where('system_key', 'sales')->firstOrFail();

    expect(fn () => receipt($this, party: $sales))->toThrow(PostingError::class, 'cannot be used');
});

it('refuses a switched-off ledger', function () {
    $this->customer->forceFill(['is_active' => false])->save();

    expect(fn () => receipt($this))->toThrow(PostingError::class, 'switched off');
});

it('refuses another office\'s ledger', function () {
    expect(fn () => receipt($this, party: $this->centralCustomer))->toThrow(PostingError::class, 'another office');
});

it('refuses another business\'s ledger', function () {
    expect(fn () => receipt($this, party: $this->otherCustomer))->toThrow(PostingError::class, 'does not belong');
});

it('lets an office use a shared ledger', function () {
    $shared = Ledger::create([
        'tenant_id' => $this->w->adoma->id, 'is_shared' => true,
        'group_id' => ChartOfAccounts::groupFor($this->w->adoma->id, LedgerKind::Bank)->id, 'kind' => LedgerKind::Bank, 'name' => 'Group Bank',
    ]);

    receipt($this, 70000, cash: $shared);

    expect(balanceOf($shared))->toBe(70000);
});

it('refuses to post for an office the person is not working in', function () {
    expect(fn () => $this->posting->postCash(
        DocumentType::Receipt, $this->w->central, now()->toDateString(), $this->centralCustomer, $this->cash, 1000, null, null, $this->clerk,
    ))->toThrow(PostingError::class, 'another office');

    expect(Document::withoutGlobalScopes()->count())->toBe(0);
});

it('only posts receipts and payments this way', function () {
    expect(fn () => $this->posting->postCash(DocumentType::Sale, $this->w->kumasi, now()->toDateString(), $this->customer, $this->cash, 1000, null, null, $this->clerk))
        ->toThrow(PostingError::class);
});

// ------------------------------------------------------ all or nothing

it('saves nothing, and gives the number back, when anything fails part way', function () {
    JournalLine::creating(function () {
        throw new RuntimeException('the database failed');
    });

    try {
        receipt($this);
    } catch (RuntimeException) {
    }

    expect(Document::withoutGlobalScopes()->count())->toBe(0)
        ->and(JournalLine::withoutGlobalScopes()->count())->toBe(0)
        ->and(AuditLog::withoutGlobalScopes()->where('auditable_type', Document::class)->count())->toBe(0);

    JournalLine::flushEventListeners();
    JournalLine::clearBootedModels();

    expect(receipt($this)->number)->toBe('REC-KUM-000001');
});

it('records the posting in the audit log', function () {
    $this->actingAs($this->clerk);
    $document = receipt($this);

    $log = AuditLog::withoutGlobalScopes()->where('auditable_type', Document::class)->where('auditable_id', $document->id)->firstOrFail();

    expect($log->event)->toBe('created')->and($log->label)->toBe('REC-KUM-000001')
        ->and($log->actor_name)->toBe($this->clerk->name)->and($log->tenant_id)->toBe($this->w->adoma->id);
});

// ---------------------------------------------------------------- immutability

it('never lets a posted document be edited or deleted', function () {
    $document = receipt($this);

    expect(fn () => $document->forceFill(['total' => '1.00'])->save())->toThrow(TenancyViolation::class)
        ->and(fn () => $document->forceFill(['ledger_id' => $this->supplier->id])->save())->toThrow(TenancyViolation::class)
        ->and(fn () => $document->forceFill(['note' => 'changed'])->save())->toThrow(TenancyViolation::class)
        ->and(fn () => $document->delete())->toThrow(TenancyViolation::class)
        ->and($document->fresh()->total)->toBe('1000.00');
});

it('never lets a journal line be edited or deleted', function () {
    receipt($this);
    $line = JournalLine::query()->firstOrFail();

    expect(fn () => $line->forceFill(['debit' => '5.00'])->save())->toThrow(TenancyViolation::class)
        ->and(fn () => $line->delete())->toThrow(TenancyViolation::class);
});

it('allows one change only: posted to reversed', function () {
    $document = receipt($this);

    expect(fn () => $document->forceFill(['status' => 'something-else'])->save())->toThrow(TenancyViolation::class);

    $document->forceFill(['status' => Document::REVERSED])->save();
    expect($document->fresh()->status)->toBe(Document::REVERSED);

    expect(fn () => $document->forceFill(['status' => Document::POSTED])->save())->toThrow(TenancyViolation::class);
});

// -------------------------------------------------------------------- reversal

it('reverses a document by posting the opposite entry', function () {
    $original = receipt($this, 100000);
    $reversal = $this->posting->reverse($original, 'Wrong customer', $this->w->kumasiManager);

    expect(lineSummary($reversal))->toBe([['Cash Kumasi', 0, 100000], ['Passion Gold', 100000, 0]])
        ->and($reversal->number)->toBe('REC-KUM-000002')
        ->and($reversal->reverses_id)->toBe($original->id)
        ->and($reversal->isReversal())->toBeTrue()
        ->and($reversal->note)->toBe('Reversal of REC-KUM-000001: Wrong customer')
        ->and($reversal->total)->toBe('1000.00')
        ->and($reversal->date->toDateString())->toBe(now()->toDateString())
        ->and($reversal->created_by)->toBe($this->w->kumasiManager->id)
        ->and($original->fresh()->status)->toBe(Document::REVERSED)
        ->and($reversal->status)->toBe(Document::POSTED);
});

it('puts every balance back where it was', function () {
    payment($this, 25000, $this->customer);
    $before = [balanceOf($this->cash), balanceOf($this->customer)];

    $original = receipt($this, 100000);
    $this->posting->reverse($original, 'Mistake', $this->w->kumasiManager);

    expect([balanceOf($this->cash), balanceOf($this->customer)])->toBe($before);
});

it('keeps both documents on record, so the history is honest', function () {
    $original = receipt($this);
    $reversal = $this->posting->reverse($original, 'Mistake', $this->w->kumasiManager);

    expect(Document::count())->toBe(2)
        ->and($original->fresh()->reversal->id)->toBe($reversal->id)
        ->and($reversal->reverses->id)->toBe($original->id)
        ->and(JournalLine::count())->toBe(4);
});

it('reverses a document only once', function () {
    $original = receipt($this);
    $this->posting->reverse($original, 'Mistake', $this->w->kumasiManager);

    expect(fn () => $this->posting->reverse($original, 'Again', $this->w->kumasiManager))->toThrow(PostingError::class, 'already been reversed');
    expect(Document::count())->toBe(2);
});

it('does not let a reversal be reversed', function () {
    $reversal = $this->posting->reverse(receipt($this), 'Mistake', $this->w->kumasiManager);

    expect(fn () => $this->posting->reverse($reversal, 'Undo the undo', $this->w->kumasiManager))
        ->toThrow(PostingError::class, 'cannot be reversed');
});

it('lets the money be recorded again correctly after a reversal', function () {
    $this->posting->reverse(receipt($this, 100000), 'Wrong amount', $this->w->kumasiManager);
    receipt($this, 150000);

    expect(balanceOf($this->customer))->toBe(-150000)->and(balanceOf($this->cash))->toBe(150000);
});

it('leaves nothing half-done when a reversal fails', function () {
    $original = receipt($this);
    JournalLine::creating(function () {
        throw new RuntimeException('failed');
    });

    try {
        $this->posting->reverse($original, 'Mistake', $this->w->kumasiManager);
    } catch (RuntimeException) {
    }

    expect($original->fresh()->status)->toBe(Document::POSTED)
        ->and(Document::count())->toBe(1);
});

// --------------------------------------------------------------- scoping

it('shows an office only its own documents and lines', function () {
    receipt($this);
    $this->ctx->set($this->w->adoma, $this->w->central);

    expect(Document::count())->toBe(0)->and(JournalLine::count())->toBe(0);
});

it('shows nothing when no business is in context', function () {
    receipt($this);
    $this->ctx->clear();

    expect(Document::count())->toBe(0)->and(JournalLine::count())->toBe(0);
});

it('lets all-offices view see every office of the business, never another business', function () {
    receipt($this);
    $this->ctx->set($this->w->adoma, null, allBranches: true);

    expect(Document::count())->toBe(1);

    DB::table('documents')->insert([
        'tenant_id' => $this->w->other->id, 'branch_id' => $this->w->otherBranch->id, 'type' => 'receipt', 'number' => 'REC-MAIN-000001',
        'date' => now()->toDateString(), 'total' => '5.00', 'status' => 'posted', 'posted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(Document::count())->toBe(1);
});

it('gives each office its own view of a shared ledger', function () {
    $shared = Ledger::create([
        'tenant_id' => $this->w->adoma->id, 'is_shared' => true,
        'group_id' => ChartOfAccounts::groupFor($this->w->adoma->id, LedgerKind::Bank)->id, 'kind' => LedgerKind::Bank, 'name' => 'Group Bank',
    ]);
    receipt($this, 100000, cash: $shared);

    $this->ctx->set($this->w->adoma, $this->w->central);
    expect(balanceOf($shared))->toBe(0);

    $this->ctx->set($this->w->adoma, null, allBranches: true);
    expect(balanceOf($shared))->toBe(100000);
});
