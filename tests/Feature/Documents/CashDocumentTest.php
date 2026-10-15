<?php

use App\Domain\Audit\AuditLog;
use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentType;
use App\Domain\Documents\JournalLine;
use App\Domain\Documents\PostingService;
use App\Domain\Ledgers\ChartOfAccounts;
use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use App\Domain\Support\Money;
use App\Domain\Tenancy\Branch;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\World;

beforeEach(function () {
    $this->w = World::build();

    $this->customer = httpLedger($this->w->kumasi, 'Passion Gold', LedgerKind::Customer);
    $this->supplier = httpLedger($this->w->kumasi, 'Black', LedgerKind::Supplier);
    $this->cash = httpLedger($this->w->kumasi, 'Cash Kumasi', LedgerKind::Cash);
    $this->bank = httpLedger($this->w->kumasi, 'Kumasi Bank', LedgerKind::Bank);
    $this->centralCustomer = httpLedger($this->w->central, 'Central Customer', LedgerKind::Customer);
    $this->centralCash = httpLedger($this->w->central, 'Cash HQ', LedgerKind::Cash);
    $this->otherCustomer = httpLedger($this->w->otherBranch, 'Other Co Customer', LedgerKind::Customer);
});

function httpLedger(Branch $branch, string $name, LedgerKind $kind, bool $active = true): Ledger
{
    $ledger = Ledger::create([
        'tenant_id' => $branch->tenant_id, 'branch_id' => $branch->id, 'is_shared' => false,
        'group_id' => ChartOfAccounts::groupFor($branch->tenant_id, $kind)->id, 'kind' => $kind, 'name' => $name,
    ]);

    if (! $active) {
        $ledger->forceFill(['is_active' => false])->save();
    }

    return $ledger;
}

/** The form fields for a receipt or payment, valid unless overridden. */
function cashForm(object $t, array $overrides = []): array
{
    return array_merge([
        'date' => now()->toDateString(),
        'ledger_id' => $t->customer->id,
        'cash_ledger_id' => $t->cash->id,
        'amount' => '1500.50',
        'note' => 'Part payment',
        'idempotency_key' => (string) Str::uuid(),
    ], $overrides);
}

/** Post a document straight through the service, as the Kumasi clerk. */
function postedReceipt(object $t, int $cents = 100000, string $type = 'receipt'): Document
{
    $context = app(TenantContext::class);
    $previous = [$context->tenant(), $context->branch(), $context->viewingAllBranches()];
    $context->set($t->w->adoma, $t->w->kumasi);

    try {
        return app(PostingService::class)->postCash(
            DocumentType::from($type), $t->w->kumasi, now()->toDateString(),
            $type === 'receipt' ? $t->customer : $t->supplier, $t->cash, $cents, 'Part payment', null, $t->w->kumasiClerk,
        );
    } finally {
        $previous[0] ? $context->set($previous[0], $previous[1], $previous[2]) : $context->clear();
    }
}

function pageProps($response): array
{
    return $response->viewData('page')['props'];
}

// ------------------------------------------------------------- the form

it('shows the receipt form with only the cash and bank accounts this branch can use', function () {
    httpLedger($this->w->kumasi, 'Old Cash', LedgerKind::Cash, active: false);

    $props = pageProps($this->actingAs($this->w->kumasiClerk)->get(route('receipts.create'))->assertOk());

    expect(collect($props['cashLedgers'])->pluck('name')->sort()->values()->all())->toBe(['Cash Kumasi', 'Kumasi Bank'])
        ->and($props['type'])->toBe('receipt')
        ->and($props['words']['party'])->toBe('Received from')
        ->and($props['office'])->toBe('Kumasi')
        ->and($props['today'])->toBe(now()->toDateString())
        ->and($props['needsOffice'])->toBeFalse()
        ->and(Str::isUuid($props['idempotencyKey']))->toBeTrue();
});

it('shows the payment form with its own wording', function () {
    $props = pageProps($this->actingAs($this->w->kumasiClerk)->get(route('payments.create'))->assertOk());

    expect($props['words']['party'])->toBe('Paid to')->and($props['words']['cash'])->toBe('Paid from');
});

it('gives each visit to the form its own key', function () {
    $this->actingAs($this->w->kumasiClerk);

    expect(pageProps($this->get(route('receipts.create')))['idempotencyKey'])
        ->not->toBe(pageProps($this->get(route('receipts.create')))['idempotencyKey']);
});

it('keeps viewers off the forms', function () {
    $this->actingAs($this->w->kumasiViewer);

    $this->get(route('receipts.create'))->assertForbidden();
    $this->get(route('payments.create'))->assertForbidden();
    $this->post(route('receipts.store'), cashForm($this))->assertForbidden();
    expect(Document::withoutGlobalScopes()->count())->toBe(0);
});

it('asks an admin viewing all branches to choose one first', function () {
    $this->actingAs($this->w->owner)->withSession(['active_branch_id' => 'all']);

    $this->get(route('receipts.create'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('needsOffice', true)->missing('cashLedgers'));

    $this->post(route('receipts.store'), cashForm($this))->assertForbidden();
    expect(Document::withoutGlobalScopes()->count())->toBe(0);
});

it('lets an owner record in the branch they have chosen', function () {
    $this->actingAs($this->w->owner)->withSession(['active_branch_id' => $this->w->kumasi->id])
        ->post(route('receipts.store'), cashForm($this))->assertSessionHasNoErrors();

    expect(Document::withoutGlobalScopes()->firstOrFail()->branch_id)->toBe($this->w->kumasi->id);
});

// ----------------------------------------------------------- recording

it('records a receipt and goes to the saved page', function () {
    $response = $this->actingAs($this->w->kumasiClerk)->post(route('receipts.store'), cashForm($this));

    $document = Document::withoutGlobalScopes()->firstOrFail();

    $response->assertSessionHasNoErrors()->assertRedirect(route('documents.show', $document))
        ->assertSessionHas('success', 'Receipt REC-KUM-000001 was saved.');

    expect($document->total)->toBe('1500.50')
        ->and($document->type)->toBe(DocumentType::Receipt)
        ->and($document->branch_id)->toBe($this->w->kumasi->id)
        ->and($document->created_by)->toBe($this->w->kumasiClerk->id)
        ->and($document->note)->toBe('Part payment')
        ->and(JournalLine::withoutGlobalScopes()->count())->toBe(2);
});

it('records a payment', function () {
    $this->actingAs($this->w->kumasiClerk)->post(route('payments.store'), cashForm($this, ['ledger_id' => $this->supplier->id, 'amount' => '250']))
        ->assertSessionHasNoErrors();

    $document = Document::withoutGlobalScopes()->firstOrFail();

    expect($document->type)->toBe(DocumentType::Payment)->and($document->number)->toBe('PV-KUM-000001')->and($document->total)->toBe('250.00');
});

it('accepts amounts typed the way people type them', function (string $typed, string $stored) {
    $this->actingAs($this->w->kumasiClerk)->post(route('receipts.store'), cashForm($this, ['amount' => $typed]))->assertSessionHasNoErrors();

    expect(Document::withoutGlobalScopes()->firstOrFail()->total)->toBe($stored);
})->with([['1,500.50', '1500.50'], ['1500', '1500.00'], ['0.05', '0.05'], [' 99.9 ', '99.90']]);

it('refuses amounts that are not plain numbers above zero', function (string $typed) {
    $this->actingAs($this->w->kumasiClerk)->post(route('receipts.store'), cashForm($this, ['amount' => $typed]))
        ->assertSessionHasErrors('amount');

    expect(Document::withoutGlobalScopes()->count())->toBe(0);
})->with(['abc', '-50', '0', '0.00', '12.345', '1e3', '', '14 cedis']);

it('refuses a date in the future or in the wrong format', function (string $date) {
    $this->actingAs($this->w->kumasiClerk)->post(route('receipts.store'), cashForm($this, ['date' => $date]))->assertSessionHasErrors('date');
})->with([
    'future' => fn () => now()->addDay()->toDateString(),
    'wrong format' => '13/10/2026',
    'nonsense' => 'yesterday',
    'too old' => '1999-12-31',
]);

it('accepts a past date', function () {
    $this->actingAs($this->w->kumasiClerk)->post(route('receipts.store'), cashForm($this, ['date' => '2026-01-05']))->assertSessionHasNoErrors();

    expect(Document::withoutGlobalScopes()->firstOrFail()->date->toDateString())->toBe('2026-01-05');
});

it('needs every field that matters', function (string $field) {
    $this->actingAs($this->w->kumasiClerk)->post(route('receipts.store'), array_diff_key(cashForm($this), [$field => 1]))
        ->assertSessionHasErrors($field);
})->with(['date', 'ledger_id', 'cash_ledger_id', 'amount', 'idempotency_key']);

it('does not need a note', function () {
    $this->actingAs($this->w->kumasiClerk)->post(route('receipts.store'), cashForm($this, ['note' => '']))->assertSessionHasNoErrors();

    expect(Document::withoutGlobalScopes()->firstOrFail()->note)->toBeNull();
});

it('keeps a note to 500 characters', function () {
    $this->actingAs($this->w->kumasiClerk)->post(route('receipts.store'), cashForm($this, ['note' => str_repeat('a', 501)]))->assertSessionHasErrors('note');
});

it('refuses ledgers this branch cannot use', function (string $which, string $field) {
    $ids = [
        'central customer' => $this->centralCustomer->id,
        'other business customer' => $this->otherCustomer->id,
        'central cash' => $this->centralCash->id,
        'no such ledger' => 999999,
        'a system account' => Ledger::withoutGlobalScopes()->where('tenant_id', $this->w->adoma->id)->where('system_key', 'sales')->value('id'),
    ];

    $this->actingAs($this->w->kumasiClerk)->post(route('receipts.store'), cashForm($this, [$field => $ids[$which]]))
        ->assertSessionHasErrors($field);

    expect(Document::withoutGlobalScopes()->count())->toBe(0);
})->with([
    ['central customer', 'ledger_id'],
    ['other business customer', 'ledger_id'],
    ['no such ledger', 'ledger_id'],
    ['a system account', 'ledger_id'],
    ['central cash', 'cash_ledger_id'],
    ['other business customer', 'cash_ledger_id'],
    ['no such ledger', 'cash_ledger_id'],
]);

it('refuses a cash side that is not cash or a bank, and a switched-off ledger', function () {
    $off = httpLedger($this->w->kumasi, 'Closed Customer', LedgerKind::Customer, active: false);
    $this->actingAs($this->w->kumasiClerk);

    $this->post(route('receipts.store'), cashForm($this, ['cash_ledger_id' => $this->supplier->id]))->assertSessionHasErrors('cash_ledger_id');
    $this->post(route('receipts.store'), cashForm($this, ['ledger_id' => $off->id]))->assertSessionHasErrors('ledger_id');
    $this->post(route('receipts.store'), cashForm($this, ['ledger_id' => $this->cash->id]))->assertSessionHasErrors('cash_ledger_id');
});

it('allows a bank or cash account as the other party', function () {
    $this->actingAs($this->w->kumasiClerk)
        ->post(route('receipts.store'), cashForm($this, ['ledger_id' => $this->bank->id, 'cash_ledger_id' => $this->cash->id]))
        ->assertSessionHasNoErrors();

    expect(Document::withoutGlobalScopes()->count())->toBe(1);
});

it('ignores anything extra sent with the form', function () {
    $this->actingAs($this->w->kumasiClerk)->post(route('receipts.store'), cashForm($this, [
        'tenant_id' => $this->w->other->id, 'branch_id' => $this->w->central->id, 'status' => 'reversed',
        'created_by' => $this->w->owner->id, 'number' => 'HACK-1', 'total' => '1.00',
    ]))->assertSessionHasNoErrors();

    $document = Document::withoutGlobalScopes()->firstOrFail();

    expect($document->tenant_id)->toBe($this->w->adoma->id)
        ->and($document->branch_id)->toBe($this->w->kumasi->id)
        ->and($document->status)->toBe(Document::POSTED)
        ->and($document->created_by)->toBe($this->w->kumasiClerk->id)
        ->and($document->number)->toBe('REC-KUM-000001')
        ->and($document->total)->toBe('1500.50');
});

it('posts once when the same form is sent twice', function () {
    $data = cashForm($this);
    $this->actingAs($this->w->kumasiClerk);

    $first = $this->post(route('receipts.store'), $data);
    $second = $this->post(route('receipts.store'), $data);

    $document = Document::withoutGlobalScopes()->firstOrFail();

    $first->assertRedirect(route('documents.show', $document));
    $second->assertRedirect(route('documents.show', $document));
    expect(Document::withoutGlobalScopes()->count())->toBe(1)->and(JournalLine::withoutGlobalScopes()->count())->toBe(2);
});

it('shows the new balance of the other party on the saved page', function () {
    $this->actingAs($this->w->kumasiClerk)->post(route('receipts.store'), cashForm($this, ['amount' => '1000']));
    $document = Document::withoutGlobalScopes()->firstOrFail();

    $props = pageProps($this->get(route('documents.show', $document)));

    expect($props['party_balance'])->toBe('GH₵ 1,000.00 · you owe them');
});

// ----------------------------------------------------------- reading one

it('shows a document with its amount in words, parties and who recorded it', function () {
    $document = postedReceipt($this, 2159800);

    $props = pageProps($this->actingAs($this->w->kumasiClerk)->get(route('documents.show', $document))->assertOk());

    expect($props['document']['number'])->toBe('REC-KUM-000001')
        ->and($props['document']['total'])->toBe('GH₵ 21,598.00')
        ->and($props['document']['total_in_words'])->toBe('Twenty One Thousand Five Hundred Ninety Eight Ghana Cedis, No Pesewas')
        ->and($props['document']['party']['name'])->toBe('Passion Gold')
        ->and($props['document']['cash']['name'])->toBe('Cash Kumasi')
        ->and($props['document']['office'])->toBe('Kumasi')
        ->and($props['document']['recorded_by'])->toBe($this->w->kumasiClerk->name)
        ->and($props['lines'])->toHaveCount(2);
});

it('shows the entries as debit and credit', function () {
    $document = postedReceipt($this, 100000);

    $lines = collect(pageProps($this->actingAs($this->w->kumasiClerk)->get(route('documents.show', $document)))['lines']);

    expect($lines->firstWhere('ledger', 'Cash Kumasi')['debit'])->toBe('1,000.00')
        ->and($lines->firstWhere('ledger', 'Cash Kumasi')['credit'])->toBe('')
        ->and($lines->firstWhere('ledger', 'Passion Gold')['credit'])->toBe('1,000.00');
});

it('hides another branch\'s documents completely', function () {
    $document = postedReceipt($this);
    $this->actingAs($this->w->centralManager);

    $this->get(route('documents.show', $document))->assertNotFound();
    $this->get(route('documents.print', $document))->assertNotFound();
    $this->post(route('documents.reverse', $document), ['reason' => 'Mistake'])->assertNotFound();
});

it('hides another business\'s documents completely', function () {
    $document = postedReceipt($this);

    $this->actingAs($this->w->otherAdmin)->withSession(['active_branch_id' => 'all'])->get(route('documents.show', $document))->assertNotFound();
});

it('lets an owner viewing all branches read any branch\'s document', function () {
    $document = postedReceipt($this);

    $this->actingAs($this->w->owner)->withSession(['active_branch_id' => 'all'])->get(route('documents.show', $document))->assertOk();
});

it('shows the history of a document to admins only', function () {
    $document = postedReceipt($this);

    expect(pageProps($this->actingAs($this->w->kumasiManager)->get(route('documents.show', $document)))['history'])->toBeNull();

    $history = pageProps($this->actingAs($this->w->admin)->withSession(['active_branch_id' => $this->w->kumasi->id])->get(route('documents.show', $document)))['history'];

    expect($history)->toHaveCount(1)->and($history[0]['description'])->toBe('added document “REC-KUM-000001”');
});

// ------------------------------------------------------------- reversing

it('lets a manager reverse a document', function () {
    $document = postedReceipt($this, 100000);

    $response = $this->actingAs($this->w->kumasiManager)->post(route('documents.reverse', $document), ['reason' => 'Wrong customer']);

    $reversal = Document::withoutGlobalScopes()->where('reverses_id', $document->id)->firstOrFail();

    $response->assertSessionHasNoErrors()->assertRedirect(route('documents.show', $reversal));
    expect($document->fresh()->status)->toBe(Document::REVERSED)
        ->and($reversal->note)->toBe('Reversal of REC-KUM-000001: Wrong customer')
        ->and($reversal->created_by)->toBe($this->w->kumasiManager->id);
});

it('keeps clerks and viewers from reversing', function (string $who) {
    $document = postedReceipt($this);

    $this->actingAs($this->w->{$who})->post(route('documents.reverse', $document), ['reason' => 'Mistake'])->assertForbidden();

    expect($document->fresh()->status)->toBe(Document::POSTED);
})->with(['kumasiClerk', 'kumasiViewer']);

it('asks why before reversing', function (string $reason) {
    $document = postedReceipt($this);

    $this->actingAs($this->w->kumasiManager)->post(route('documents.reverse', $document), ['reason' => $reason])->assertSessionHasErrors('reason');

    expect($document->fresh()->status)->toBe(Document::POSTED);
})->with(['', 'x', '  ']);

it('does not offer or allow a second reversal, or reversing a reversal', function () {
    $document = postedReceipt($this);
    $this->actingAs($this->w->kumasiManager)->post(route('documents.reverse', $document), ['reason' => 'Mistake']);
    $reversal = Document::withoutGlobalScopes()->where('reverses_id', $document->id)->firstOrFail();

    $this->post(route('documents.reverse', $document), ['reason' => 'Again'])->assertForbidden();
    $this->post(route('documents.reverse', $reversal), ['reason' => 'Undo'])->assertForbidden();

    expect(pageProps($this->get(route('documents.show', $document)))['canReverse'])->toBeFalse()
        ->and(pageProps($this->get(route('documents.show', $reversal)))['canReverse'])->toBeFalse()
        ->and(Document::withoutGlobalScopes()->count())->toBe(2);
});

it('links a reversed document and its reversal to each other', function () {
    $document = postedReceipt($this);
    $this->actingAs($this->w->kumasiManager)->post(route('documents.reverse', $document), ['reason' => 'Mistake']);
    $reversal = Document::withoutGlobalScopes()->where('reverses_id', $document->id)->firstOrFail();

    $original = pageProps($this->get(route('documents.show', $document)))['document'];
    $undo = pageProps($this->get(route('documents.show', $reversal)))['document'];

    expect($original['status'])->toBe('reversed')->and($original['reversed_by']['number'])->toBe($reversal->number)
        ->and($undo['reverses']['number'])->toBe($document->number);
});

it('offers the right buttons to the right people', function () {
    $document = postedReceipt($this);

    $can = fn ($who) => collect(pageProps($this->actingAs($this->w->{$who})->get(route('documents.show', $document))))
        ->only(['canReverse', 'canRecordAnother'])->all();

    expect($can('kumasiViewer'))->toBe(['canReverse' => false, 'canRecordAnother' => false])
        ->and($can('kumasiClerk'))->toBe(['canReverse' => false, 'canRecordAnother' => true])
        ->and($can('kumasiManager'))->toBe(['canReverse' => true, 'canRecordAnother' => true]);
});

// --------------------------------------------------------------- printing

it('prints in A4 or small receipt layout, with the right title', function () {
    $receipt = postedReceipt($this, 150000);
    $payment = postedReceipt($this, 20000, 'payment');
    $this->actingAs($this->w->kumasiClerk);

    expect(pageProps($this->get(route('documents.print', $receipt)))['layout'])->toBe('a4')
        ->and(pageProps($this->get(route('documents.print', [$receipt, 'layout' => 'thermal'])))['layout'])->toBe('thermal')
        ->and(pageProps($this->get(route('documents.print', [$receipt, 'layout' => 'garbage'])))['layout'])->toBe('a4')
        ->and(pageProps($this->get(route('documents.print', $payment)))['document']['type'])->toBe('payment')
        ->and(pageProps($this->get(route('documents.print', $receipt)))['business'])->toBe('Adoma');
});

it('lets viewers print what they can read', function () {
    $document = postedReceipt($this);

    $this->actingAs($this->w->kumasiViewer)->get(route('documents.print', $document))->assertOk();
});

// ------------------------------------------------------------------ finding

it('lists a branch\'s transactions, newest first, and nobody else\'s', function () {
    $first = postedReceipt($this, 1000);
    $second = postedReceipt($this, 2000);
    DB::table('documents')->insert([
        'tenant_id' => $this->w->other->id, 'branch_id' => $this->w->otherBranch->id, 'type' => 'receipt', 'number' => 'REC-MAIN-000001',
        'date' => now()->toDateString(), 'total' => '5.00', 'status' => 'posted', 'posted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $rows = collect(pageProps($this->actingAs($this->w->kumasiClerk)->get(route('documents.index')))['documents']['data']);

    expect($rows->pluck('number')->all())->toBe([$second->number, $first->number]);

    expect(collect(pageProps($this->actingAs($this->w->centralManager)->get(route('documents.index')))['documents']['data']))->toHaveCount(0);
});

it('filters transactions by type, status, number, name and dates', function () {
    postedReceipt($this, 1000);
    $payment = postedReceipt($this, 2000, 'payment');
    $old = postedReceipt($this, 3000);
    DB::table('documents')->where('id', $old->id)->update(['date' => '2026-01-10']);
    $reversed = postedReceipt($this, 4000);
    $this->actingAs($this->w->kumasiManager)->post(route('documents.reverse', $reversed), ['reason' => 'Mistake']);

    $numbers = fn (array $q) => collect(pageProps($this->actingAs($this->w->kumasiManager)->get(route('documents.index', $q)))['documents']['data'])->pluck('number')->all();

    expect($numbers(['type' => 'payment']))->toBe([$payment->number])
        ->and($numbers(['q' => $payment->number]))->toBe([$payment->number])
        ->and($numbers(['q' => 'Black']))->toBe([$payment->number])
        ->and($numbers(['status' => 'reversed']))->toBe([$reversed->number])
        ->and($numbers(['from' => '2026-01-01', 'to' => '2026-01-31']))->toBe([$old->number])
        ->and($numbers(['from' => 'not-a-date']))->toHaveCount(5)
        ->and($numbers(['type' => 'nonsense']))->toHaveCount(5);
});

it('pages transactions 25 at a time', function () {
    foreach (range(1, 30) as $i) {
        postedReceipt($this, 100 + $i);
    }

    $this->actingAs($this->w->kumasiClerk)->get(route('documents.index'))
        ->assertInertia(fn ($page) => $page->has('documents.data', 25)->where('documents.total', 30)->where('documents.last_page', 2));
});

it('lets viewers find transactions but not record one', function () {
    $props = pageProps($this->actingAs($this->w->kumasiViewer)->get(route('documents.index'))->assertOk());

    expect($props['canRecord'])->toBeFalse();
});

it('keeps people with no business out of the list', function () {
    $this->actingAs(User::factory()->create())->get(route('documents.index'))->assertForbidden();
});

// -------------------------------------------------------------- audit

it('writes who recorded and who reversed into the audit log', function () {
    $this->actingAs($this->w->kumasiClerk)->post(route('receipts.store'), cashForm($this));
    $document = Document::withoutGlobalScopes()->firstOrFail();
    $this->actingAs($this->w->kumasiManager)->post(route('documents.reverse', $document), ['reason' => 'Mistake']);

    $descriptions = AuditLog::withoutGlobalScopes()->where('auditable_type', Document::class)->orderBy('id')->get()
        ->map(fn ($l) => $l->actor_name.' '.$l->describe())->all();

    expect($descriptions)->toContain(
        $this->w->kumasiClerk->name.' added document “REC-KUM-000001”',
        $this->w->kumasiManager->name.' added document “REC-KUM-000002”',
        $this->w->kumasiManager->name.' marked document “REC-KUM-000001” as reversed',
    );
});
