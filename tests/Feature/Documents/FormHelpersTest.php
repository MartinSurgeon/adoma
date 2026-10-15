<?php

use App\Domain\Documents\Document;
use App\Domain\Ledgers\ChartOfAccounts;
use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use App\Domain\Tenancy\Branch;
use Illuminate\Support\Str;
use Tests\Support\World;

// Small helpers that make the forms quicker: adding a ledger from inside a form, and
// "record another like this". Each is checked for who may use it and what it may carry over.

beforeEach(function () {
    $this->w = World::build();

    $this->supplier = fhLedger($this->w->kumasi, 'Bright', LedgerKind::Supplier);
    $this->customer = fhLedger($this->w->kumasi, 'Passion Gold', LedgerKind::Customer);
    $this->cash = fhLedger($this->w->kumasi, 'Cash Kumasi', LedgerKind::Cash);
    $this->centralCustomer = fhLedger($this->w->central, 'Central Customer', LedgerKind::Customer);
    $this->centralCash = fhLedger($this->w->central, 'Cash HQ', LedgerKind::Cash);
});

function fhLedger(Branch $branch, string $name, LedgerKind $kind, bool $active = true): Ledger
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

function fhProps($response): array
{
    return $response->viewData('page')['props'];
}

function fhReceipt(object $t, $as, Ledger $party, Ledger $cash, string $note = 'Part payment'): Document
{
    $t->actingAs($as)->post(route('receipts.store'), [
        'date' => now()->toDateString(), 'ledger_id' => $party->id, 'cash_ledger_id' => $cash->id,
        'amount' => '500.00', 'note' => $note, 'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();

    return Document::withoutGlobalScopes()->where('type', 'receipt')->latest('id')->firstOrFail();
}

function fhPurchase(object $t, array $overrides = []): Document
{
    $t->actingAs($t->w->kumasiClerk)->post(route('purchases.store'), array_merge([
        'date' => now()->toDateString(), 'ledger_id' => $t->supplier->id, 'form' => 'raw', 'weight' => '2094.25',
        'water_weight' => '114.56', 'karat' => '', 'price' => '10600', 'note' => 'first lot', 'idempotency_key' => (string) Str::uuid(),
    ], $overrides))->assertSessionHasNoErrors();

    return Document::withoutGlobalScopes()->where('type', 'purchase')->latest('id')->firstOrFail();
}

// ----------------------------------------------------- adding a ledger from inside a form

it('adds a ledger from a form and hands it straight back', function () {
    $response = $this->actingAs($this->w->kumasiClerk)
        ->postJson(route('ledgers.store'), ['kind' => 'customer', 'name' => 'New Buyer', 'phone' => '0244000000'])
        ->assertCreated();

    $ledger = Ledger::where('name', 'New Buyer')->firstOrFail();

    expect($response->json())->toBe([
        'id' => $ledger->id, 'name' => 'New Buyer', 'kind_label' => 'Customer', 'balance' => 'Settled',
    ])->and($ledger->branch_id)->toBe($this->w->kumasi->id)->and($ledger->is_shared)->toBeFalse();
});

it('explains a refused ledger in words, and does not add it', function () {
    $this->actingAs($this->w->kumasiClerk)
        ->postJson(route('ledgers.store'), ['kind' => 'customer', 'name' => 'Passion Gold'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'This branch already has a ledger with that name.');

    expect(Ledger::where('name', 'Passion Gold')->count())->toBe(1);
});

it('keeps the usual rules when adding from a form', function () {
    // A clerk may add customers and suppliers but not a bank, and a viewer adds nothing.
    $this->actingAs($this->w->kumasiClerk)->postJson(route('ledgers.store'), ['kind' => 'bank', 'name' => 'Sneaky Bank'])->assertForbidden();
    $this->actingAs($this->w->kumasiViewer)->postJson(route('ledgers.store'), ['kind' => 'customer', 'name' => 'Sneaky'])->assertForbidden();

    expect(Ledger::whereIn('name', ['Sneaky Bank', 'Sneaky'])->count())->toBe(0);
});

it('tells each form which kinds of ledger the person may add', function (string $who, array $expected) {
    $kinds = collect(fhProps($this->actingAs($this->w->{$who})->get(route('receipts.create')))['tenancy']['addable_ledger_kinds'] ?? [])
        ->pluck('value')->all();

    expect($kinds)->toBe($expected);
})->with([
    'clerk' => ['kumasiClerk', ['customer', 'supplier']],
    'manager' => ['kumasiManager', ['customer', 'supplier', 'bank', 'cash', 'expense']],
]);

// -------------------------------------------------------------- record another like this

it('starts a receipt with the same person, account and note, and nothing else', function () {
    $receipt = fhReceipt($this, $this->w->kumasiClerk, $this->customer, $this->cash, 'Weekly payment');

    $props = fhProps($this->actingAs($this->w->kumasiClerk)->get(route('receipts.create', ['like' => $receipt->id]))->assertOk());

    expect($props['prefill']['ledger']['id'])->toBe($this->customer->id)
        ->and($props['prefill']['ledger']['name'])->toBe('Passion Gold')
        ->and($props['prefill']['cash_ledger_id'])->toBe($this->cash->id)
        ->and($props['prefill']['note'])->toBe('Weekly payment')
        ->and($props['prefill'])->not->toHaveKey('amount')
        ->and($props['prefill'])->not->toHaveKey('date');
});

it('starts a purchase with the same supplier, form of gold and price, but no weights', function () {
    $purchase = fhPurchase($this);

    $props = fhProps($this->actingAs($this->w->kumasiClerk)->get(route('purchases.create', ['like' => $purchase->id]))->assertOk());

    expect($props['prefill']['ledger']['id'])->toBe($this->supplier->id)
        ->and($props['prefill']['form'])->toBe('raw')
        ->and($props['prefill']['price'])->toBe('10600.00')
        ->and($props['prefill']['note'])->toBe('first lot')
        ->and(array_keys($props['prefill']))->not->toContain('weight', 'water_weight', 'karat');
});

it('starts blank without a "like", or with a nonsense one', function (mixed $like) {
    $query = $like === null ? [] : ['like' => $like];

    expect(fhProps($this->actingAs($this->w->kumasiClerk)->get(route('receipts.create', $query))->assertOk())['prefill'])->toBeNull();
})->with([null, 'abc', '0', '999999', '1; drop table documents']);

it('never copies from another branch or another kind of transaction', function () {
    $central = fhReceipt($this, $this->w->centralManager, $this->centralCustomer, $this->centralCash);
    $receipt = fhReceipt($this, $this->w->kumasiClerk, $this->customer, $this->cash);

    // Kumasi cannot start from a Central receipt.
    expect(fhProps($this->actingAs($this->w->kumasiClerk)->get(route('receipts.create', ['like' => $central->id])))['prefill'])->toBeNull()
        // A receipt is not a payment.
        ->and(fhProps($this->actingAs($this->w->kumasiClerk)->get(route('payments.create', ['like' => $receipt->id])))['prefill'])->toBeNull();
});

it('does not carry over a ledger that has since been switched off', function () {
    $receipt = fhReceipt($this, $this->w->kumasiClerk, $this->customer, $this->cash);
    $this->customer->forceFill(['is_active' => false])->save();

    expect(fhProps($this->actingAs($this->w->kumasiClerk)->get(route('receipts.create', ['like' => $receipt->id])))['prefill'])->toBeNull();
});

// ------------------------------------------------------------ messages people can act on

it('says what to do, in plain words, when a form is saved with the basics missing', function () {
    $this->actingAs($this->w->kumasiClerk)
        ->post(route('receipts.store'), ['idempotency_key' => (string) Str::uuid()])
        ->assertSessionHasErrors([
            'date' => 'Enter the date.',
            'ledger_id' => 'Choose someone from your list of ledgers.',
            'cash_ledger_id' => 'Choose a cash or bank account.',
            'amount' => 'Enter the amount.',
        ]);

    $this->actingAs($this->w->kumasiClerk)
        ->post(route('purchases.store'), ['idempotency_key' => (string) Str::uuid(), 'form' => 'raw'])
        ->assertSessionHasErrors([
            'ledger_id' => 'Choose a customer or supplier from your list.',
            'weight' => 'Enter the weight.',
            'price' => 'Enter the price.',
        ]);
});
