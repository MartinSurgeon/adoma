<?php

use App\Domain\Documents\Document;
use App\Domain\Documents\JournalLine;
use App\Domain\Documents\PostingError;
use App\Domain\Documents\PostingService;
use App\Domain\Ledgers\ChartOfAccounts;
use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use App\Domain\Stock\StockLot;
use App\Domain\Stock\StockMovement;
use App\Domain\Support\Money;
use App\Domain\Tenancy\Branch;
use Illuminate\Support\Str;
use Tests\Support\World;

// Gold sent from one office to another, at what it cost. Two documents, one in each office's books.

beforeEach(function () {
    $this->w = World::build();

    $this->supplier = trLedger($this->w->kumasi, 'Bright', LedgerKind::Supplier);
    $this->customer = trLedger($this->w->central, 'Central Buyer', LedgerKind::Customer);
    $this->otherSupplier = trLedger($this->w->otherBranch, 'Other Co Supplier', LedgerKind::Supplier);
});

function trLedger(Branch $branch, string $name, LedgerKind $kind): Ledger
{
    return Ledger::create([
        'tenant_id' => $branch->tenant_id, 'branch_id' => $branch->id, 'is_shared' => false,
        'group_id' => ChartOfAccounts::groupFor($branch->tenant_id, $kind)->id, 'kind' => $kind, 'name' => $name,
    ]);
}

function trProps($response): array
{
    return $response->viewData('page')['props'];
}

/** Kumasi buys raw gold: row 2 of the client's sheet by default (2,094.25 g). */
function trBuy(object $t, array $overrides = []): Document
{
    $t->actingAs($t->w->kumasiManager)->post(route('purchases.store'), array_merge([
        'date' => now()->toDateString(), 'ledger_id' => $t->supplier->id, 'form' => 'raw', 'weight' => '2094.25',
        'water_weight' => '114.56', 'karat' => '', 'price' => '10600', 'note' => '', 'idempotency_key' => (string) Str::uuid(),
    ], $overrides))->assertSessionHasNoErrors();

    return Document::withoutGlobalScopes()->where('type', 'purchase')->latest('id')->firstOrFail();
}

function trSendForm(object $t, array $overrides = []): array
{
    return array_merge([
        'date' => now()->toDateString(), 'to_branch_id' => $t->w->central->id, 'weight' => '1000.00',
        'note' => 'Carried by Kofi', 'idempotency_key' => (string) Str::uuid(),
    ], $overrides);
}

/** Kumasi's manager sends gold to Central and returns the sending document. */
function trSend(object $t, array $overrides = []): Document
{
    $t->actingAs($t->w->kumasiManager)->post(route('transfers.store'), trSendForm($t, $overrides))->assertSessionHasNoErrors();

    return Document::withoutGlobalScopes()->where('type', 'transfer')->whereNull('reverses_id')->latest('id')->firstOrFail();
}

function trIn(Document $out): Document
{
    return Document::withoutGlobalScopes()->where('paired_id', $out->id)->firstOrFail();
}

function trStockBalance(object $t, Branch $branch): int
{
    $stock = Ledger::withoutGlobalScopes()->where('tenant_id', $t->w->adoma->id)->where('system_key', 'gold_stock')->firstOrFail();
    $lines = JournalLine::withoutGlobalScopes()->where('ledger_id', $stock->id)->where('branch_id', $branch->id)->get();

    return Money::fromDb($lines->sum('debit')) - Money::fromDb($lines->sum('credit'));
}

function trBranchBalance(Document $doc): array
{
    $lines = JournalLine::withoutGlobalScopes()->where('document_id', $doc->id)->get();

    return [Money::fromDb($lines->sum('debit')), Money::fromDb($lines->sum('credit')), $lines->pluck('branch_id')->unique()->values()->all()];
}

// ------------------------------------------------------------------ the books

it('sends gold as two documents, each balanced inside its own office', function () {
    $purchase = trBuy($this);
    $out = trSend($this);
    $in = trIn($out);

    [$outDebit, $outCredit, $outBranches] = trBranchBalance($out);
    [$inDebit, $inCredit, $inBranches] = trBranchBalance($in);

    expect($out->type->value)->toBe('transfer')->and($in->type->value)->toBe('transfer_in')
        ->and($out->number)->toBe('TRF-KUM-000001')->and($in->number)->toBe('TRI-CEN-000001')
        ->and($out->branch_id)->toBe($this->w->kumasi->id)->and($in->branch_id)->toBe($this->w->central->id)
        // Each document's lines stay in its own office, so each office's books balance by themselves.
        ->and($outDebit)->toBe($outCredit)->and($outDebit)->toBeGreaterThan(0)
        ->and($outBranches)->toBe([$this->w->kumasi->id])
        ->and($inDebit)->toBe($inCredit)->and($inDebit)->toBe($outDebit)
        ->and($inBranches)->toBe([$this->w->central->id])
        ->and($out->note)->toBe('Carried by Kofi')->and($in->note)->toBe('Carried by Kofi');
});

it('moves gold at what it cost, so neither office shows a profit or a loss', function () {
    $purchase = trBuy($this);
    $cost = Money::fromDb($purchase->total);

    $out = trSend($this, ['weight' => '1000.00']);
    $moved = Money::fromDb($out->total);

    // 1,000 of 2,094.25 g carries its share of the cost, and Kumasi keeps the rest, to the pesewa.
    expect($moved)->toBe(intdiv($cost * 100000, 209425))
        ->and(trStockBalance($this, $this->w->kumasi))->toBe($cost - $moved)
        ->and(trStockBalance($this, $this->w->central))->toBe($moved);
});

it('puts the gold into the receiving office as new lots that keep their age, karat and cost', function () {
    $old = trBuy($this, ['date' => now()->subDays(20)->toDateString(), 'weight' => '500.00', 'water_weight' => '27.30']);
    $new = trBuy($this, ['date' => now()->subDays(2)->toDateString()]);

    // 700 g: all of the older lot and 200 g of the newer one.
    $out = trSend($this, ['weight' => '700.00']);

    $kumasiLots = StockLot::withoutGlobalScopes()->where('branch_id', $this->w->kumasi->id)->orderBy('id')->get();
    $arrived = StockLot::withoutGlobalScopes()->where('branch_id', $this->w->central->id)->orderBy('id')->get();

    expect($arrived)->toHaveCount(2)
        ->and(Money::fromDb($arrived[0]->weight_g))->toBe(50000)
        ->and($arrived[0]->date->toDateString())->toBe(now()->subDays(20)->toDateString())
        ->and($arrived[0]->source_lot_id)->toBe($kumasiLots[0]->id)
        ->and($arrived[0]->gold_line_id)->toBeNull()
        ->and($arrived[0]->document_id)->toBe(trIn($out)->id)
        ->and(Money::fromDb($arrived[1]->weight_g))->toBe(20000)
        ->and($arrived[1]->date->toDateString())->toBe(now()->subDays(2)->toDateString())
        ->and($arrived[1]->karat)->toEqual($kumasiLots[1]->karat)
        // Nothing lost between the two offices: what left Kumasi is exactly what arrived.
        ->and(Money::fromDb($arrived->sum('cost')))->toBe(Money::fromDb($out->total))
        ->and(Money::fromDb($kumasiLots->sum('remaining_cost')) + Money::fromDb($arrived->sum('cost')))
        ->toBe(Money::fromDb($old->total) + Money::fromDb($new->total))
        ->and(Money::fromDb($kumasiLots[0]->remaining_g))->toBe(0)
        ->and(Money::fromDb($kumasiLots[1]->remaining_g))->toBe(Money::fromDb($kumasiLots[1]->weight_g) - 20000);
});

it('writes every gram that moved as stock movements in the office that holds it', function () {
    trBuy($this);
    $out = trSend($this, ['weight' => '1000.00']);
    $in = trIn($out);

    $outMoves = StockMovement::withoutGlobalScopes()->where('document_id', $out->id)->get();
    $inMoves = StockMovement::withoutGlobalScopes()->where('document_id', $in->id)->get();

    expect($outMoves->pluck('branch_id')->unique()->all())->toBe([$this->w->kumasi->id])
        ->and($inMoves->pluck('branch_id')->unique()->all())->toBe([$this->w->central->id])
        ->and(Money::fromDb($outMoves->sum('weight_g')))->toBe(-100000)
        ->and(Money::fromDb($inMoves->sum('weight_g')))->toBe(100000)
        ->and(Money::fromDb($outMoves->sum('cost')))->toBe(-Money::fromDb($inMoves->sum('cost')));
});

it('lets the office that received the gold sell it, oldest first, at the cost it arrived with', function () {
    trBuy($this);
    $out = trSend($this, ['weight' => '1000.00']);

    $this->actingAs($this->w->centralManager)->post(route('sales.store'), [
        'date' => now()->toDateString(), 'ledger_id' => $this->customer->id, 'form' => 'raw', 'weight' => '400.00',
        'water_weight' => '21.80', 'karat' => '', 'price' => '12000', 'note' => '', 'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();

    $lot = StockLot::withoutGlobalScopes()->where('branch_id', $this->w->central->id)->firstOrFail();

    expect(Money::fromDb($lot->remaining_g))->toBe(60000)
        // Selling 400 of 1,000 g takes 40% of the cost the gold arrived with.
        ->and(Money::fromDb($lot->remaining_cost))->toBe(Money::fromDb($out->total) - intdiv(Money::fromDb($out->total) * 40000, 100000));
});

// ------------------------------------------------------------- who may, and what is refused

it('lets a manager send gold, but not a clerk or a viewer', function () {
    trBuy($this);

    $this->actingAs($this->w->kumasiClerk)->get(route('transfers.create'))->assertForbidden();
    $this->actingAs($this->w->kumasiClerk)->post(route('transfers.store'), trSendForm($this))->assertForbidden();
    $this->actingAs($this->w->kumasiViewer)->post(route('transfers.store'), trSendForm($this))->assertForbidden();
    $this->actingAs($this->w->kumasiClerk)->postJson(route('transfers.quote'), ['weight' => '10'])->assertForbidden();

    expect(Document::withoutGlobalScopes()->where('type', 'transfer')->count())->toBe(0);

    $this->actingAs($this->w->kumasiManager)->get(route('transfers.create'))->assertOk();
});

it('shows the form with the other offices, and how much gold there is to send', function () {
    trBuy($this);

    $props = trProps($this->actingAs($this->w->kumasiManager)->get(route('transfers.create'))->assertOk());

    expect($props['office'])->toBe('Kumasi')->and($props['inStock'])->toBe('2,094.25')
        ->and(collect($props['offices'])->pluck('name')->all())->toBe(['Central'])
        ->and(Str::isUuid($props['idempotencyKey']))->toBeTrue();
});

it('asks an owner viewing every office to choose one first', function () {
    $this->actingAs($this->w->owner)->withSession(['active_branch_id' => 'all']);

    $this->get(route('transfers.create'))->assertOk()->assertInertia(fn ($page) => $page->where('needsOffice', true));
    $this->post(route('transfers.store'), trSendForm($this))->assertForbidden();
});

it('refuses a transfer that cannot be made, and saves nothing', function (array $change, string $message) {
    trBuy($this);

    $this->actingAs($this->w->kumasiManager)->post(route('transfers.store'), trSendForm($this, $change))
        ->assertSessionHasErrors(['weight' => $message] + (isset($change['to_branch_id']) ? [] : []));

    expect(Document::withoutGlobalScopes()->where('type', 'transfer')->count())->toBe(0)
        ->and(StockLot::withoutGlobalScopes()->where('branch_id', $this->w->central->id)->count())->toBe(0);
})->with([
    'more than there is' => [['weight' => '5000'], 'Not enough gold in stock. Kumasi has 2,094.25 g and this sale is 5,000.00 g.'],
]);

it('says in plain words what is wrong with the form', function () {
    trBuy($this);
    $this->actingAs($this->w->kumasiManager);

    $this->post(route('transfers.store'), trSendForm($this, ['weight' => '0']))->assertSessionHasErrors(['weight' => 'The weight must be more than zero.']);
    $this->post(route('transfers.store'), trSendForm($this, ['weight' => 'lots']))->assertSessionHasErrors('weight');
    $this->post(route('transfers.store'), trSendForm($this, ['to_branch_id' => '']))->assertSessionHasErrors(['to_branch_id' => 'Choose the office the gold is going to.']);
    $this->post(route('transfers.store'), trSendForm($this, ['to_branch_id' => $this->w->kumasi->id]))->assertSessionHasErrors(['to_branch_id' => 'Choose the office the gold is going to.']);
    $this->post(route('transfers.store'), trSendForm($this, ['to_branch_id' => $this->w->otherBranch->id]))->assertSessionHasErrors(['to_branch_id' => 'Choose the office the gold is going to.']);
    $this->post(route('transfers.store'), trSendForm($this, ['date' => now()->addDay()->toDateString()]))->assertSessionHasErrors(['date' => 'The date cannot be in the future.']);

    expect(Document::withoutGlobalScopes()->where('type', 'transfer')->count())->toBe(0);
});

it('cannot send gold to another business, even by calling the service directly', function () {
    trBuy($this);
    $this->actingAs($this->w->kumasiManager);

    expect(fn () => app(PostingService::class)->postTransfer($this->w->kumasi, $this->w->otherBranch, now()->toDateString(), 1000, null, null, $this->w->kumasiManager))
        ->toThrow(PostingError::class, 'same business');
});

it('saves a transfer once however many times the button is pressed', function () {
    trBuy($this);
    $form = trSendForm($this);

    $this->actingAs($this->w->kumasiManager);
    $this->post(route('transfers.store'), $form)->assertSessionHasNoErrors();
    $this->post(route('transfers.store'), $form)->assertSessionHasNoErrors();

    expect(Document::withoutGlobalScopes()->where('type', 'transfer')->count())->toBe(1)
        ->and(Document::withoutGlobalScopes()->where('type', 'transfer_in')->count())->toBe(1)
        ->and(StockLot::withoutGlobalScopes()->where('branch_id', $this->w->central->id)->count())->toBe(1);
});

it('does not touch any other business', function () {
    trBuy($this);
    trSend($this);

    expect(StockLot::withoutGlobalScopes()->where('tenant_id', $this->w->other->id)->count())->toBe(0)
        ->and(Document::withoutGlobalScopes()->where('tenant_id', $this->w->other->id)->count())->toBe(0);
});

// ------------------------------------------------------------------------ the quote

it('quotes the cost and the number of lots, and reads without saving anything', function () {
    trBuy($this);

    $quote = $this->actingAs($this->w->kumasiManager)->postJson(route('transfers.quote'), ['weight' => '1000'])->assertOk()->json();

    expect($quote['ok'])->toBeTrue()->and($quote['weight'])->toBe('1,000.00')->and($quote['lots'])->toBe(1)
        ->and($quote['in_stock'])->toBe('2,094.25')->and($quote['cost'])->toStartWith('GH₵ ')
        ->and(Document::withoutGlobalScopes()->where('type', 'transfer')->count())->toBe(0)
        ->and(StockLot::withoutGlobalScopes()->where('branch_id', $this->w->kumasi->id)->first()->remaining_g)->toEqual('2094.25');
});

it('quotes what it would cost exactly as saving then does', function () {
    trBuy($this);
    $this->actingAs($this->w->kumasiManager);

    $quote = $this->postJson(route('transfers.quote'), ['weight' => '1000'])->json();
    $out = trSend($this, ['weight' => '1000.00']);

    expect($quote['cost'])->toBe(Money::cedis(Money::fromDb($out->total)));
});

it('says so when there is not enough gold, or the weight is not a number', function () {
    trBuy($this);
    $this->actingAs($this->w->kumasiManager);

    expect($this->postJson(route('transfers.quote'), ['weight' => '9999'])->json())
        ->toMatchArray(['ok' => false, 'enough' => false, 'in_stock' => '2,094.25'])
        ->and($this->postJson(route('transfers.quote'), ['weight' => 'abc'])->json('ok'))->toBeFalse()
        ->and($this->postJson(route('transfers.quote'), ['weight' => '0'])->json('message'))->toBe('The weight must be more than zero.');
});

// ---------------------------------------------------------------------- reversing

it('puts everything back when the sending office reverses a transfer, both halves together', function () {
    $purchase = trBuy($this);
    $out = trSend($this);
    $in = trIn($out);

    $this->actingAs($this->w->kumasiManager)->post(route('documents.reverse', $out->id), ['reason' => 'Sent to the wrong office'])
        ->assertSessionHasNoErrors();

    $reversal = Document::withoutGlobalScopes()->where('reverses_id', $out->id)->firstOrFail();
    $inReversal = Document::withoutGlobalScopes()->where('reverses_id', $in->id)->firstOrFail();

    expect($out->fresh()->status)->toBe('reversed')->and($in->fresh()->status)->toBe('reversed')
        ->and($reversal->branch_id)->toBe($this->w->kumasi->id)->and($inReversal->branch_id)->toBe($this->w->central->id)
        ->and($inReversal->paired_id)->toBe($reversal->id)
        ->and($reversal->number)->toBe('TRF-KUM-000002')->and($inReversal->number)->toBe('TRI-CEN-000002')
        ->and(trStockBalance($this, $this->w->kumasi))->toBe(Money::fromDb($purchase->total))
        ->and(trStockBalance($this, $this->w->central))->toBe(0)
        ->and(Money::fromDb(StockLot::withoutGlobalScopes()->where('branch_id', $this->w->kumasi->id)->first()->remaining_g))->toBe(209425)
        ->and(Money::fromDb(StockLot::withoutGlobalScopes()->where('branch_id', $this->w->central->id)->sum('remaining_g')))->toBe(0)
        // Each half of the reversal balances inside its own office.
        ->and(trBranchBalance($reversal)[2])->toBe([$this->w->kumasi->id])
        ->and(trBranchBalance($inReversal)[2])->toBe([$this->w->central->id])
        ->and(trBranchBalance($inReversal)[0])->toBe(trBranchBalance($inReversal)[1]);
});

it('refuses to reverse once the receiving office has sold some of the gold, and changes nothing', function () {
    trBuy($this);
    $out = trSend($this, ['weight' => '1000.00']);
    $in = trIn($out);

    $this->actingAs($this->w->centralManager)->post(route('sales.store'), [
        'date' => now()->toDateString(), 'ledger_id' => $this->customer->id, 'form' => 'raw', 'weight' => '600.00',
        'water_weight' => '32.70', 'karat' => '', 'price' => '12000', 'note' => '', 'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();

    $before = Document::withoutGlobalScopes()->count();

    $this->actingAs($this->w->kumasiManager)->post(route('documents.reverse', $out->id), ['reason' => 'Changed my mind'])
        ->assertSessionHasErrors(['reason' => 'Some of this gold has already been sold or sent to another office, so this transfer cannot be reversed. Reverse those first.']);

    expect(Document::withoutGlobalScopes()->count())->toBe($before)
        ->and($out->fresh()->status)->toBe('posted')->and($in->fresh()->status)->toBe('posted')
        ->and(Money::fromDb(StockLot::withoutGlobalScopes()->where('branch_id', $this->w->kumasi->id)->first()->remaining_g))->toBe(109425);
});

it('keeps the receiving office from reversing, and from reaching the sending office\'s half', function () {
    trBuy($this);
    $out = trSend($this);
    $in = trIn($out);

    // Central's manager sees the transfer in, but has no Reverse button and cannot do it by hand.
    $this->actingAs($this->w->centralManager);
    expect(trProps($this->get(route('documents.show', $in->id))->assertOk())['canReverse'])->toBeFalse();
    $this->post(route('documents.reverse', $in->id), ['reason' => 'Not mine to undo'])->assertForbidden();
    $this->get(route('documents.show', $out->id))->assertNotFound();

    expect(fn () => app(PostingService::class)->reverse($in, 'by hand', $this->w->centralManager))
        ->toThrow(PostingError::class, 'put right by the office that sent it');
    expect($out->fresh()->status)->toBe('posted');
});

it('never lets a transfer be reversed twice', function () {
    trBuy($this);
    $out = trSend($this);

    $this->actingAs($this->w->kumasiManager)->post(route('documents.reverse', $out->id), ['reason' => 'first'])->assertSessionHasNoErrors();
    $this->post(route('documents.reverse', $out->id), ['reason' => 'second'])->assertForbidden();

    expect(Document::withoutGlobalScopes()->where('reverses_id', $out->id)->count())->toBe(1);
});

// -------------------------------------------------------------- reading a transfer

it('shows each office its own half, with the other half named but opened only by who may', function () {
    trBuy($this);
    $out = trSend($this, ['weight' => '1000.00']);
    $in = trIn($out);

    $sending = trProps($this->actingAs($this->w->kumasiManager)->get(route('documents.show', $out->id))->assertOk());
    $receiving = trProps($this->actingAs($this->w->centralManager)->get(route('documents.show', $in->id))->assertOk());

    expect($sending['transfer'])->toBe([
        'direction' => 'out', 'weight' => '1,000.00', 'other_office' => 'Central', 'other_id' => null, 'other_number' => $in->number,
    ])->and($sending['document']['type_label'])->toBe('Transfer out')->and($sending['document']['party']['name'])->toBe('Central')
        ->and($sending['canReverse'])->toBeTrue()
        // Central's manager cannot open Kumasi's document, so no link is given, only its number.
        ->and($receiving['transfer'])->toBe([
            'direction' => 'in', 'weight' => '1,000.00', 'other_office' => 'Kumasi', 'other_id' => null, 'other_number' => $out->number,
        ])->and($receiving['document']['type_label'])->toBe('Transfer in')->and($receiving['document']['party']['name'])->toBe('Kumasi');

    // An owner can open both, so the link is there.
    $owner = trProps($this->actingAs($this->w->owner)->withSession(['active_branch_id' => 'all'])->get(route('documents.show', $in->id))->assertOk());
    expect($owner['transfer']['other_id'])->toBe($out->id);
});

it('lists each office\'s own half in its transactions, and offers both as filters', function () {
    trBuy($this);
    $out = trSend($this);
    $in = trIn($out);

    $kumasi = trProps($this->actingAs($this->w->kumasiManager)->get(route('documents.index', ['type' => 'transfer']))->assertOk());
    $central = trProps($this->actingAs($this->w->centralManager)->get(route('documents.index', ['type' => 'transfer_in']))->assertOk());

    expect(collect($kumasi['documents']['data'])->pluck('number')->all())->toBe([$out->number])
        ->and(collect($central['documents']['data'])->pluck('number')->all())->toBe([$in->number])
        ->and(collect($kumasi['types'])->pluck('label')->all())->toContain('Transfers out', 'Transfers in', 'Purchases')
        // Central sees no trace of Kumasi's half.
        ->and(collect(trProps($this->actingAs($this->w->centralManager)->get(route('documents.index')))['documents']['data'])->pluck('number')->all())->toBe([$in->number]);
});

it('lets the two offices settle what they owe each other with an ordinary payment', function () {
    trBuy($this);
    $out = trSend($this);
    $cost = Money::fromDb($out->total);

    $kumasiAccountOfCentral = Ledger::withoutGlobalScopes()->where('linked_branch_id', $this->w->central->id)->firstOrFail();
    $balance = fn () => Money::fromDb(JournalLine::withoutGlobalScopes()->where('ledger_id', $kumasiAccountOfCentral->id)->where('branch_id', $this->w->kumasi->id)->sum('debit'))
        - Money::fromDb(JournalLine::withoutGlobalScopes()->where('ledger_id', $kumasiAccountOfCentral->id)->where('branch_id', $this->w->kumasi->id)->sum('credit'));

    // Kumasi is owed the cost by Central until it is settled.
    expect($balance())->toBe($cost);

    $cash = trLedger($this->w->kumasi, 'Cash Kumasi', LedgerKind::Cash);

    $this->actingAs($this->w->kumasiManager)->post(route('receipts.store'), [
        'date' => now()->toDateString(), 'ledger_id' => $kumasiAccountOfCentral->id, 'cash_ledger_id' => $cash->id,
        'amount' => Money::decimal($cost), 'note' => 'Central pays for the gold', 'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();

    expect($balance())->toBe(0);
});

// ------------------------------------------------------------------ the stock page

it('lists the lots an office holds, oldest first, with where each came from', function () {
    $old = trBuy($this, ['date' => now()->subDays(9)->toDateString(), 'weight' => '500.00', 'water_weight' => '27.30']);
    $new = trBuy($this);
    trSend($this, ['weight' => '700.00']);

    $kumasi = trProps($this->actingAs($this->w->kumasiManager)->get(route('stock.index'))->assertOk());
    $central = trProps($this->actingAs($this->w->centralManager)->get(route('stock.index'))->assertOk());

    // Kumasi: only what is left of the newer purchase. The older lot is empty and drops off the list.
    expect(collect($kumasi['lots']['data'])->pluck('origin_number')->all())->toBe([$new->number])
        ->and($kumasi['lots']['data'][0]['remaining'])->toBe('1,894.25')
        ->and($kumasi['totals']['grams'])->toBe('1,894.25')
        // Central: two lots that arrived, oldest first, each saying it came by transfer.
        ->and(collect($central['lots']['data'])->pluck('origin_kind')->unique()->all())->toBe(['Transfer from'])
        ->and(collect($central['lots']['data'])->pluck('origin_name')->unique()->all())->toBe(['Kumasi'])
        ->and(collect($central['lots']['data'])->pluck('remaining')->all())->toBe(['500.00', '200.00'])
        ->and($central['totals']['grams'])->toBe('700.00')->and($central['totals']['lots'])->toBe(2);
});

it('shows what the gold cost to managers and above only', function () {
    trBuy($this);

    $clerk = trProps($this->actingAs($this->w->kumasiClerk)->get(route('stock.index'))->assertOk());
    $manager = trProps($this->actingAs($this->w->kumasiManager)->get(route('stock.index'))->assertOk());
    $owner = trProps($this->actingAs($this->w->owner)->withSession(['active_branch_id' => 'all'])->get(route('stock.index'))->assertOk());

    expect($clerk['canSeeCost'])->toBeFalse()->and($clerk['totals']['cost'])->toBeNull()->and($clerk['lots']['data'][0]['cost'])->toBeNull()
        ->and($manager['canSeeCost'])->toBeTrue()->and($manager['totals']['cost'])->toStartWith('GH₵ ')->and($manager['lots']['data'][0]['cost'])->toStartWith('GH₵ ')
        ->and($owner['canSeeCost'])->toBeTrue();
});

it('keeps one office\'s stock, and one business\'s, out of another\'s page', function () {
    trBuy($this);

    $central = trProps($this->actingAs($this->w->centralManager)->get(route('stock.index'))->assertOk());
    $other = trProps($this->actingAs($this->w->otherAdmin)->get(route('stock.index'))->assertOk());

    expect($central['lots']['data'])->toBe([])->and($central['totals']['grams'])->toBe('0.00')
        ->and($other['lots']['data'])->toBe([])->and($other['totals']['grams'])->toBe('0.00');

    // An owner looking at every office sees them together, with the office named on each.
    $owner = trProps($this->actingAs($this->w->owner)->withSession(['active_branch_id' => 'all'])->get(route('stock.index'))->assertOk());
    expect($owner['showOffice'])->toBeTrue()->and($owner['lots']['data'][0]['office'])->toBe('Kumasi');
});

it('keeps people with no office role off the stock page', function () {
    $stray = App\Models\User::factory()->create();

    $this->actingAs($stray)->get(route('stock.index'))->assertForbidden();
});

// -------------------------------------------------------------------- the dashboard

it('tells an owner how much gold each office holds, and nobody else', function () {
    trBuy($this);
    trSend($this, ['weight' => '1000.00']);

    $owner = trProps($this->actingAs($this->w->owner)->withSession(['active_branch_id' => 'all'])->get(route('dashboard'))->assertOk());
    $this->flushSession();   // the owner's "all offices" choice belongs to the owner's own session
    $manager = trProps($this->actingAs($this->w->kumasiManager)->get(route('dashboard'))->assertOk());

    expect(collect($owner['stockByOffice'])->pluck('grams', 'name')->all())->toBe(['Central' => '1,000.00', 'Kumasi' => '1,094.25'])
        ->and($owner['goldInStock'])->toBe('2,094.25')
        ->and($manager['stockByOffice'])->toBeNull()
        ->and($manager['canTransfer'])->toBeTrue()
        ->and(trProps($this->actingAs($this->w->kumasiClerk)->get(route('dashboard')))['canTransfer'])->toBeFalse();
});

it('tells the menu who may send gold', function (string $who, bool $expected) {
    expect(trProps($this->actingAs($this->w->{$who})->get(route('dashboard')))['tenancy']['can_transfer'])->toBe($expected);
})->with([
    ['kumasiManager', true],
    ['centralManager', true],
    ['kumasiClerk', false],
    ['kumasiViewer', false],
]);
