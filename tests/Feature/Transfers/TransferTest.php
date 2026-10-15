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

// Gold sold by one branch to another, at a price per pound, and accepted by the receiving branch.
// Two documents, one in each branch's books.

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
        'date' => now()->toDateString(), 'to_branch_id' => $t->w->central->id, 'weight' => '1000.00', 'price' => '12000',
        'note' => 'Carried by Kofi', 'idempotency_key' => (string) Str::uuid(),
    ], $overrides);
}

/**
 * Kumasi's manager sends gold to Central and returns the sending document. By default Central's manager then
 * accepts it, so the gold has arrived. Pass accept: false to leave it waiting.
 */
function trSend(object $t, array $overrides = [], bool $accept = true): Document
{
    $t->actingAs($t->w->kumasiManager)->post(route('transfers.store'), trSendForm($t, $overrides))->assertSessionHasNoErrors();

    $out = Document::withoutGlobalScopes()->where('type', 'transfer')->whereNull('reverses_id')->latest('id')->firstOrFail();

    if ($accept) {
        trAccept($t, $out);
    }

    return $out;
}

function trAccept(object $t, Document $out): void
{
    $t->actingAs($t->w->centralManager)->post(route('transfers.accept', $out->id))->assertSessionHasNoErrors();
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

it('sends gold as two documents, each balanced inside its own branch', function () {
    $purchase = trBuy($this);
    $out = trSend($this);
    $in = trIn($out);

    [$outDebit, $outCredit, $outBranches] = trBranchBalance($out);
    [$inDebit, $inCredit, $inBranches] = trBranchBalance($in);

    expect($out->type->value)->toBe('transfer')->and($in->type->value)->toBe('transfer_in')
        ->and($out->number)->toBe('TRF-KUM-000001')->and($in->number)->toBe('TRI-CEN-000001')
        ->and($out->branch_id)->toBe($this->w->kumasi->id)->and($in->branch_id)->toBe($this->w->central->id)
        // Each document's lines stay in its own branch, so each branch's books balance by themselves.
        ->and($outDebit)->toBe($outCredit)->and($outDebit)->toBeGreaterThan(0)
        ->and($outBranches)->toBe([$this->w->kumasi->id])
        // The receiving branch books only the price. The sending branch also moves the gold's cost into Cost of Sales.
        ->and($inDebit)->toBe($inCredit)->and($inDebit)->toBe(Money::fromDb($out->total))
        ->and($inBranches)->toBe([$this->w->central->id])
        ->and($out->note)->toBe('Carried by Kofi')->and($in->note)->toBe('Carried by Kofi');
});

it('sells the gold to the other branch at the price, like any sale', function () {
    $purchase = trBuy($this);
    $cost = Money::fromDb($purchase->total);

    $out = trSend($this, ['weight' => '1000.00', 'price' => '12000']);

    // 1,000 g of 22.45 karat at 12,000 a pound is the same GH₵ 1,511,334 a sale of it would be.
    $amount = Money::fromDb($out->total);
    // And it cost its share of the purchase: 1,000 of 2,094.25 g, to the pesewa.
    $sliceCost = intdiv($cost * 100000, 209425);

    $ledger = fn (string $key) => Ledger::withoutGlobalScopes()->where('tenant_id', $this->w->adoma->id)->where('system_key', $key)->firstOrFail();
    $net = fn (Ledger $l, Branch $b) => Money::fromDb(JournalLine::withoutGlobalScopes()->where('ledger_id', $l->id)->where('branch_id', $b->id)->sum('debit'))
        - Money::fromDb(JournalLine::withoutGlobalScopes()->where('ledger_id', $l->id)->where('branch_id', $b->id)->sum('credit'));

    $central = Ledger::withoutGlobalScopes()->where('linked_branch_id', $this->w->central->id)->firstOrFail();
    $kumasi = Ledger::withoutGlobalScopes()->where('linked_branch_id', $this->w->kumasi->id)->firstOrFail();

    expect($amount)->toBe(151133400)
        // Kumasi: Central owes it the price, the price is sales, the gold's cost leaves stock for cost of sales.
        ->and($net($central, $this->w->kumasi))->toBe($amount)
        ->and($net($ledger('sales'), $this->w->kumasi))->toBe(-$amount)
        ->and($net($ledger('cost_of_sales'), $this->w->kumasi))->toBe($sliceCost)
        ->and(trStockBalance($this, $this->w->kumasi))->toBe($cost - $sliceCost)
        // Central: the gold is in stock at the price it paid, and it owes Kumasi that.
        ->and(trStockBalance($this, $this->w->central))->toBe($amount)
        ->and($net($kumasi, $this->w->central))->toBe(-$amount);
});

it('prices every lot by its own karat, with the rule a sale uses', function () {
    // Two lots of different karat: 500 g at 22.50 and 2,094.25 g at 22.45.
    trBuy($this, ['date' => now()->subDays(20)->toDateString(), 'weight' => '500.00', 'water_weight' => '27.30']);
    trBuy($this, ['date' => now()->subDays(2)->toDateString()]);
    $formula = App\Domain\Gold\FormulaSet::currentFor($this->w->adoma->id);

    $out = trSend($this, ['weight' => '700.00', 'price' => '12000']);

    $slices = StockMovement::withoutGlobalScopes()->where('document_id', $out->id)->orderBy('id')->get();
    $expected = [
        App\Domain\Gold\GoldCalculator::amountFor($formula, 50000, 2250, 1200000) * 100,
        App\Domain\Gold\GoldCalculator::amountFor($formula, 20000, 2245, 1200000) * 100,
    ];

    expect($slices->map(fn ($m) => Money::fromDb($m->value))->all())->toBe($expected)
        ->and(Money::fromDb($out->total))->toBe(array_sum($expected))
        // The gold arrives at the price paid for each slice.
        ->and(StockLot::withoutGlobalScopes()->where('branch_id', $this->w->central->id)->orderBy('id')->get()->map(fn ($l) => Money::fromDb($l->cost))->all())->toBe($expected);
});

it('puts the gold into the receiving branch as new lots that keep their age, karat and cost', function () {
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
        // The gold arrives costing what Central pays for it, the price. Kumasi's cost of what it kept and what it
        // sent still adds up to exactly what it paid, with nothing lost.
        ->and(Money::fromDb($arrived->sum('cost')))->toBe(Money::fromDb($out->total))
        ->and(Money::fromDb($kumasiLots->sum('remaining_cost')) - Money::fromDb(StockMovement::withoutGlobalScopes()->where('document_id', $out->id)->sum('cost')))
        ->toBe(Money::fromDb($old->total) + Money::fromDb($new->total))
        ->and(Money::fromDb($kumasiLots[0]->remaining_g))->toBe(0)
        ->and(Money::fromDb($kumasiLots[1]->remaining_g))->toBe(Money::fromDb($kumasiLots[1]->weight_g) - 20000);
});

it('writes every gram that moved as stock movements in the branch that holds it', function () {
    trBuy($this);
    $out = trSend($this, ['weight' => '1000.00']);
    $in = trIn($out);

    $outMoves = StockMovement::withoutGlobalScopes()->where('document_id', $out->id)->get();
    $inMoves = StockMovement::withoutGlobalScopes()->where('document_id', $in->id)->get();

    expect($outMoves->pluck('branch_id')->unique()->all())->toBe([$this->w->kumasi->id])
        ->and($inMoves->pluck('branch_id')->unique()->all())->toBe([$this->w->central->id])
        ->and(Money::fromDb($outMoves->sum('weight_g')))->toBe(-100000)
        ->and(Money::fromDb($inMoves->sum('weight_g')))->toBe(100000)
        // What Kumasi sold it for is what it cost Central.
        ->and(Money::fromDb($outMoves->sum('value')))->toBe(Money::fromDb($inMoves->sum('cost')))
        ->and($inMoves->first()->value)->toBeNull();
});

it('lets the branch that received the gold sell it, oldest first, at the cost it arrived with', function () {
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

it('shows the form with the other branches, and how much gold there is to send', function () {
    trBuy($this);

    $props = trProps($this->actingAs($this->w->kumasiManager)->get(route('transfers.create'))->assertOk());

    expect($props['office'])->toBe('Kumasi')->and($props['inStock'])->toBe('2,094.25')
        ->and(collect($props['offices'])->pluck('name')->all())->toBe(['Central'])
        ->and(Str::isUuid($props['idempotencyKey']))->toBeTrue();
});

it('asks an owner viewing every branch to choose one first', function () {
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
    $this->post(route('transfers.store'), trSendForm($this, ['to_branch_id' => '']))->assertSessionHasErrors(['to_branch_id' => 'Choose the branch the gold is going to.']);
    $this->post(route('transfers.store'), trSendForm($this, ['to_branch_id' => $this->w->kumasi->id]))->assertSessionHasErrors(['to_branch_id' => 'Choose the branch the gold is going to.']);
    $this->post(route('transfers.store'), trSendForm($this, ['to_branch_id' => $this->w->otherBranch->id]))->assertSessionHasErrors(['to_branch_id' => 'Choose the branch the gold is going to.']);
    $this->post(route('transfers.store'), trSendForm($this, ['date' => now()->addDay()->toDateString()]))->assertSessionHasErrors(['date' => 'The date cannot be in the future.']);

    expect(Document::withoutGlobalScopes()->where('type', 'transfer')->count())->toBe(0);
});

it('cannot send gold to another business, even by calling the service directly', function () {
    trBuy($this);
    $this->actingAs($this->w->kumasiManager);

    expect(fn () => app(PostingService::class)->postTransfer($this->w->kumasi, $this->w->otherBranch, now()->toDateString(), 1000, 1200000, null, null, $this->w->kumasiManager))
        ->toThrow(PostingError::class, 'same business');
});

it('saves a transfer once however many times the button is pressed', function () {
    trBuy($this);
    $form = trSendForm($this);

    $this->actingAs($this->w->kumasiManager);
    $this->post(route('transfers.store'), $form)->assertSessionHasNoErrors();
    $this->post(route('transfers.store'), $form)->assertSessionHasNoErrors();

    // One transfer, waiting: nothing has arrived until Central accepts.
    expect(Document::withoutGlobalScopes()->where('type', 'transfer')->count())->toBe(1)
        ->and(Document::withoutGlobalScopes()->where('type', 'transfer_in')->count())->toBe(0)
        ->and(StockLot::withoutGlobalScopes()->where('branch_id', $this->w->central->id)->count())->toBe(0);
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

    $quote = $this->postJson(route('transfers.quote'), ['weight' => '1000', 'price' => '12000'])->json();
    $out = trSend($this, ['weight' => '1000.00', 'price' => '12000']);
    $cost = abs(Money::fromDb(StockMovement::withoutGlobalScopes()->where('document_id', $out->id)->sum('cost')));

    // The amount and the profit shown while typing are exactly what is saved.
    expect($quote['amount'])->toBe(Money::cedis(Money::fromDb($out->total)))
        ->and($quote['cost'])->toBe(Money::cedis($cost))
        ->and($quote['profit'])->toBe(Money::cedis(Money::fromDb($out->total) - $cost))
        ->and($quote['is_loss'])->toBeFalse();
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

it('puts everything back when the sending branch reverses a transfer, both halves together', function () {
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
        // Each half of the reversal balances inside its own branch.
        ->and(trBranchBalance($reversal)[2])->toBe([$this->w->kumasi->id])
        ->and(trBranchBalance($inReversal)[2])->toBe([$this->w->central->id])
        ->and(trBranchBalance($inReversal)[0])->toBe(trBranchBalance($inReversal)[1]);
});

it('refuses to reverse once the receiving branch has sold some of the gold, and changes nothing', function () {
    trBuy($this);
    $out = trSend($this, ['weight' => '1000.00']);
    $in = trIn($out);

    $this->actingAs($this->w->centralManager)->post(route('sales.store'), [
        'date' => now()->toDateString(), 'ledger_id' => $this->customer->id, 'form' => 'raw', 'weight' => '600.00',
        'water_weight' => '32.70', 'karat' => '', 'price' => '12000', 'note' => '', 'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();

    $before = Document::withoutGlobalScopes()->count();

    $this->actingAs($this->w->kumasiManager)->post(route('documents.reverse', $out->id), ['reason' => 'Changed my mind'])
        ->assertSessionHasErrors(['reason' => 'Some of this gold has already been sold or sent to another branch, so this transfer cannot be reversed. Reverse those first.']);

    expect(Document::withoutGlobalScopes()->count())->toBe($before)
        ->and($out->fresh()->status)->toBe('posted')->and($in->fresh()->status)->toBe('posted')
        ->and(Money::fromDb(StockLot::withoutGlobalScopes()->where('branch_id', $this->w->kumasi->id)->first()->remaining_g))->toBe(109425);
});

it('keeps the receiving branch from reversing, and from reaching the sending branch\'s half', function () {
    trBuy($this);
    $out = trSend($this);
    $in = trIn($out);

    // Central's manager sees the transfer in, but has no Reverse button and cannot do it by hand.
    $this->actingAs($this->w->centralManager);
    expect(trProps($this->get(route('documents.show', $in->id))->assertOk())['canReverse'])->toBeFalse();
    $this->post(route('documents.reverse', $in->id), ['reason' => 'Not mine to undo'])->assertForbidden();
    $this->get(route('documents.show', $out->id))->assertNotFound();

    expect(fn () => app(PostingService::class)->reverse($in, 'by hand', $this->w->centralManager))
        ->toThrow(PostingError::class, 'put right by the branch that sent it');
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

it('shows each branch its own half, with the other half named but opened only by who may', function () {
    trBuy($this);
    $out = trSend($this, ['weight' => '1000.00']);
    $in = trIn($out);

    $sending = trProps($this->actingAs($this->w->kumasiManager)->get(route('documents.show', $out->id))->assertOk());
    $receiving = trProps($this->actingAs($this->w->centralManager)->get(route('documents.show', $in->id))->assertOk());

    expect($sending['transfer'])->toBe([
        'direction' => 'out', 'state' => 'accepted', 'weight' => '1,000.00', 'other_office' => 'Central', 'other_id' => null, 'other_number' => $in->number,
    ])->and($sending['document']['type_label'])->toBe('Transfer out')->and($sending['document']['party']['name'])->toBe('Central')
        ->and($sending['canReverse'])->toBeTrue()
        // Central's manager cannot open Kumasi's document, so no link is given, only its number.
        ->and($receiving['transfer'])->toBe([
            'direction' => 'in', 'state' => 'accepted', 'weight' => '1,000.00', 'other_office' => 'Kumasi', 'other_id' => null, 'other_number' => $out->number,
        ])->and($receiving['document']['type_label'])->toBe('Transfer in')->and($receiving['document']['party']['name'])->toBe('Kumasi');

    // An owner can open both, so the link is there.
    $owner = trProps($this->actingAs($this->w->owner)->withSession(['active_branch_id' => 'all'])->get(route('documents.show', $in->id))->assertOk());
    expect($owner['transfer']['other_id'])->toBe($out->id);
});

it('lists each branch\'s own half in its transactions, and offers both as filters', function () {
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

it('lets the two branches settle what they owe each other with an ordinary payment', function () {
    $cash = trLedger($this->w->kumasi, 'Cash Kumasi', LedgerKind::Cash);
    trBuy($this);
    $out = trSend($this);
    $cost = Money::fromDb($out->total);

    $kumasiAccountOfCentral = Ledger::withoutGlobalScopes()->where('linked_branch_id', $this->w->central->id)->firstOrFail();
    $balance = fn () => Money::fromDb(JournalLine::withoutGlobalScopes()->where('ledger_id', $kumasiAccountOfCentral->id)->where('branch_id', $this->w->kumasi->id)->sum('debit'))
        - Money::fromDb(JournalLine::withoutGlobalScopes()->where('ledger_id', $kumasiAccountOfCentral->id)->where('branch_id', $this->w->kumasi->id)->sum('credit'));

    // Kumasi is owed the cost by Central until it is settled.
    expect($balance())->toBe($cost);

    $this->actingAs($this->w->kumasiManager)->post(route('receipts.store'), [
        'date' => now()->toDateString(), 'ledger_id' => $kumasiAccountOfCentral->id, 'cash_ledger_id' => $cash->id,
        'amount' => Money::decimal($cost), 'note' => 'Central pays for the gold', 'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();

    expect($balance())->toBe(0);
});

// ------------------------------------------------------------------ the stock page

it('lists the lots a branch holds, oldest first, with where each came from', function () {
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

it('keeps one branch\'s stock, and one business\'s, out of another\'s page', function () {
    trBuy($this);

    $central = trProps($this->actingAs($this->w->centralManager)->get(route('stock.index'))->assertOk());
    $other = trProps($this->actingAs($this->w->otherAdmin)->get(route('stock.index'))->assertOk());

    expect($central['lots']['data'])->toBe([])->and($central['totals']['grams'])->toBe('0.00')
        ->and($other['lots']['data'])->toBe([])->and($other['totals']['grams'])->toBe('0.00');

    // An owner looking at every branch sees them together, with the branch named on each.
    $owner = trProps($this->actingAs($this->w->owner)->withSession(['active_branch_id' => 'all'])->get(route('stock.index'))->assertOk());
    expect($owner['showOffice'])->toBeTrue()->and($owner['lots']['data'][0]['office'])->toBe('Kumasi');
});

it('keeps people with no branch role off the stock page', function () {
    $stray = App\Models\User::factory()->create();

    $this->actingAs($stray)->get(route('stock.index'))->assertForbidden();
});

// -------------------------------------------------------------------- the dashboard

it('tells an owner how much gold each branch holds, and nobody else', function () {
    trBuy($this);
    trSend($this, ['weight' => '1000.00']);

    $owner = trProps($this->actingAs($this->w->owner)->withSession(['active_branch_id' => 'all'])->get(route('dashboard'))->assertOk());
    $this->flushSession();   // the owner's "all branches" choice belongs to the owner's own session
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

// ======================================================= the receiving branch accepts

it('keeps sent gold out of both branches\' stock, and Central\'s books untouched, while it waits', function () {
    $purchase = trBuy($this);
    $out = trSend($this, ['weight' => '1000.00'], accept: false);
    // What leaves Kumasi's stock is what the gold cost it, not the price it was sold at.
    $cost = abs(Money::fromDb(StockMovement::withoutGlobalScopes()->where('document_id', $out->id)->sum('cost')));

    expect(Document::withoutGlobalScopes()->where('paired_id', $out->id)->exists())->toBeFalse()
        ->and(Document::withoutGlobalScopes()->where('branch_id', $this->w->central->id)->where('type', 'transfer_in')->count())->toBe(0)
        ->and(StockLot::withoutGlobalScopes()->where('branch_id', $this->w->central->id)->count())->toBe(0)
        ->and(trStockBalance($this, $this->w->central))->toBe(0)
        ->and(JournalLine::withoutGlobalScopes()->where('branch_id', $this->w->central->id)->count())->toBe(0)
        // Kumasi has already let go of it, and its own books balance.
        ->and(trStockBalance($this, $this->w->kumasi))->toBe(Money::fromDb($purchase->total) - $cost)
        ->and(trBranchBalance($out)[0])->toBe(trBranchBalance($out)[1])
        ->and(Money::fromDb(StockLot::withoutGlobalScopes()->where('branch_id', $this->w->kumasi->id)->first()->remaining_g))->toBe(109425);

    $page = trProps($this->actingAs($this->w->kumasiManager)->get(route('documents.show', $out->id))->assertOk());

    expect($page['transfer']['state'])->toBe('waiting')->and($page['transfer']['other_office'])->toBe('Central')
        ->and($page['transfer']['other_number'])->toBeNull()->and($page['canReverse'])->toBeTrue();
});

it('says the gold is waiting when it is sent, and marks it waiting in the list', function () {
    trBuy($this);
    $this->actingAs($this->w->kumasiManager)->post(route('transfers.store'), trSendForm($this))
        ->assertSessionHas('success', fn ($message) => str_contains($message, 'waiting for Central to accept'));

    $out = Document::withoutGlobalScopes()->where('type', 'transfer')->firstOrFail();
    $rows = collect(trProps($this->actingAs($this->w->kumasiManager)->get(route('documents.index'))->assertOk())['documents']['data']);

    expect($rows->firstWhere('number', $out->number)['waiting'])->toBeTrue();

    trAccept($this, $out);

    $rows = collect(trProps($this->actingAs($this->w->kumasiManager)->get(route('documents.index'))->assertOk())['documents']['data']);
    expect($rows->firstWhere('number', $out->number)['waiting'])->toBeFalse();
});

it('shows the receiving branch what is waiting for it, and nobody else', function () {
    trBuy($this);
    $out = trSend($this, ['weight' => '1000.00', 'note' => 'Carried by Kofi'], accept: false);

    $central = trProps($this->actingAs($this->w->centralManager)->get(route('transfers.incoming'))->assertOk());

    expect($central['office'])->toBe('Central')->and($central['transfers'])->toHaveCount(1)
        ->and($central['transfers'][0])->toMatchArray([
            'id' => $out->id, 'number' => $out->number, 'from' => 'Kumasi', 'note' => 'Carried by Kofi', 'weight' => '1,000.00', 'lots' => 1,
        ])->and($central['transfers'][0]['amount'])->toBe('GH₵ 1,511,334.00')
        // Kumasi's cost is Kumasi's business, and is not sent to the receiving branch at all.
        ->and($central['transfers'][0])->not->toHaveKey('cost');

    // The sending branch has nothing to accept, and a clerk may not answer for a branch.
    expect(trProps($this->actingAs($this->w->kumasiManager)->get(route('transfers.incoming'))->assertOk())['transfers'])->toBe([]);
    $this->actingAs($this->w->kumasiClerk)->get(route('transfers.incoming'))->assertForbidden();
});

it('tells the menu how many are waiting, to managers of the receiving branch only', function () {
    trBuy($this);
    trSend($this, [], accept: false);
    trSend($this, ['weight' => '200.00'], accept: false);

    $central = trProps($this->actingAs($this->w->centralManager)->get(route('dashboard'))->assertOk());
    $kumasi = trProps($this->actingAs($this->w->kumasiManager)->get(route('dashboard'))->assertOk());
    $clerk = trProps($this->actingAs($this->w->kumasiClerk)->get(route('dashboard'))->assertOk());

    expect($central['tenancy']['incoming_transfers'])->toBe(2)
        ->and($kumasi['tenancy']['incoming_transfers'])->toBe(0)
        ->and($clerk['tenancy']['incoming_transfers'])->toBe(0);
});

it('brings the gold in when the receiving branch accepts, and says so', function () {
    trBuy($this, ['date' => now()->subDays(20)->toDateString(), 'weight' => '500.00', 'water_weight' => '27.30']);
    trBuy($this);
    $out = trSend($this, ['weight' => '700.00'], accept: false);

    $this->actingAs($this->w->centralManager)->post(route('transfers.accept', $out->id))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('documents.show', trIn($out)->id))
        ->assertSessionHas('success', fn ($message) => str_contains($message, 'Accepted. 700.00 g from Kumasi is now in Central\'s stock.'));

    $in = trIn($out);
    $arrived = StockLot::withoutGlobalScopes()->where('branch_id', $this->w->central->id)->orderBy('id')->get();

    expect($in->number)->toBe('TRI-CEN-000001')->and($in->branch_id)->toBe($this->w->central->id)
        ->and($in->created_by)->toBe($this->w->centralManager->id)
        ->and(Money::fromDb($in->total))->toBe(Money::fromDb($out->total))
        ->and($arrived)->toHaveCount(2)
        // The gold keeps the date it was first bought, so the oldest still sells first.
        ->and($arrived[0]->date->toDateString())->toBe(now()->subDays(20)->toDateString())
        ->and(Money::fromDb($arrived->sum('remaining_g')))->toBe(70000)
        ->and(trStockBalance($this, $this->w->central))->toBe(Money::fromDb($out->total))
        ->and(trBranchBalance($in)[0])->toBe(trBranchBalance($in)[1])
        ->and(trBranchBalance($in)[2])->toBe([$this->w->central->id]);

    // It is no longer waiting, for anyone.
    expect(trProps($this->actingAs($this->w->centralManager)->get(route('transfers.incoming')))['transfers'])->toBe([])
        ->and(trProps($this->actingAs($this->w->kumasiManager)->get(route('documents.show', $out->id)))['transfer']['state'])->toBe('accepted');
});

it('lets only a manager of the receiving branch accept', function () {
    trBuy($this);
    $out = trSend($this, [], accept: false);

    // The sending branch cannot accept its own gold, and neither can a clerk of the receiving branch.
    $this->actingAs($this->w->kumasiManager)->post(route('transfers.accept', $out->id))->assertNotFound();
    $this->actingAs($this->w->kumasiClerk)->post(route('transfers.accept', $out->id))->assertForbidden();
    $this->actingAs($this->w->otherAdmin)->post(route('transfers.accept', $out->id))->assertNotFound();

    $centralClerk = App\Models\User::factory()->make();
    $centralClerk->forceFill(['tenant_id' => $this->w->adoma->id])->save();
    $centralClerk->branches()->attach($this->w->central->id, ['role' => App\Domain\Tenancy\Role::Clerk->value]);
    $this->actingAs($centralClerk)->post(route('transfers.accept', $out->id))->assertForbidden();

    expect(Document::withoutGlobalScopes()->where('type', 'transfer_in')->count())->toBe(0)
        ->and(StockLot::withoutGlobalScopes()->where('branch_id', $this->w->central->id)->count())->toBe(0);
});

it('accepts a transfer once, however many times the button is pressed', function () {
    trBuy($this);
    $out = trSend($this, [], accept: false);

    $this->actingAs($this->w->centralManager);
    $this->post(route('transfers.accept', $out->id))->assertSessionHasNoErrors();
    $this->post(route('transfers.accept', $out->id))->assertNotFound();

    expect(Document::withoutGlobalScopes()->where('type', 'transfer_in')->count())->toBe(1)
        ->and(StockLot::withoutGlobalScopes()->where('branch_id', $this->w->central->id)->count())->toBe(1)
        ->and(trStockBalance($this, $this->w->central))->toBe(Money::fromDb($out->total));

    // Even asked directly, the service refuses a second acceptance.
    expect(fn () => app(PostingService::class)->acceptTransfer($out, $this->w->central, $this->w->centralManager))
        ->toThrow(PostingError::class, 'already been accepted');
});

it('puts the gold back when the receiving branch declines, with its reason on the record', function () {
    $purchase = trBuy($this);
    $out = trSend($this, ['weight' => '1000.00'], accept: false);

    $this->actingAs($this->w->centralManager)->post(route('transfers.decline', $out->id), ['reason' => 'Weight does not match'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('transfers.incoming'));

    $reversal = Document::withoutGlobalScopes()->where('reverses_id', $out->id)->firstOrFail();

    expect($out->fresh()->status)->toBe('reversed')
        ->and($reversal->branch_id)->toBe($this->w->kumasi->id)
        ->and($reversal->note)->toContain('declined by Central: Weight does not match')
        ->and($reversal->created_by)->toBe($this->w->centralManager->id)
        ->and(trStockBalance($this, $this->w->kumasi))->toBe(Money::fromDb($purchase->total))
        ->and(Money::fromDb(StockLot::withoutGlobalScopes()->where('branch_id', $this->w->kumasi->id)->first()->remaining_g))->toBe(209425)
        // Central never had it.
        ->and(Document::withoutGlobalScopes()->where('type', 'transfer_in')->count())->toBe(0)
        ->and(StockLot::withoutGlobalScopes()->where('branch_id', $this->w->central->id)->count())->toBe(0)
        ->and(trProps($this->actingAs($this->w->centralManager)->get(route('transfers.incoming')))['transfers'])->toBe([]);

    // It can neither be accepted nor declined now.
    $this->post(route('transfers.accept', $out->id))->assertNotFound();
    $this->post(route('transfers.decline', $out->id), ['reason' => 'again'])->assertNotFound();
});

it('asks the receiving branch for a reason when it declines', function () {
    trBuy($this);
    $out = trSend($this, [], accept: false);

    $this->actingAs($this->w->centralManager)->post(route('transfers.decline', $out->id), ['reason' => ''])
        ->assertSessionHasErrors(['reason' => 'Say why you are declining it, so the sending branch knows.']);

    expect($out->fresh()->status)->toBe('posted');
});

it('lets the sending branch cancel gold nobody has accepted yet, and then it cannot be accepted', function () {
    $purchase = trBuy($this);
    $out = trSend($this, ['weight' => '1000.00'], accept: false);

    $this->actingAs($this->w->kumasiManager)->post(route('documents.reverse', $out->id), ['reason' => 'Sent too much'])
        ->assertSessionHasNoErrors();

    expect($out->fresh()->status)->toBe('reversed')
        ->and(Document::withoutGlobalScopes()->where('reverses_id', $out->id)->count())->toBe(1)
        ->and(Document::withoutGlobalScopes()->where('type', 'transfer_in')->count())->toBe(0)
        ->and(trStockBalance($this, $this->w->kumasi))->toBe(Money::fromDb($purchase->total))
        ->and(Money::fromDb(StockLot::withoutGlobalScopes()->where('branch_id', $this->w->kumasi->id)->first()->remaining_g))->toBe(209425);

    $this->actingAs($this->w->centralManager)->post(route('transfers.accept', $out->id))->assertNotFound();

    expect(fn () => app(PostingService::class)->acceptTransfer($out, $this->w->central, $this->w->centralManager))
        ->toThrow(PostingError::class, 'was cancelled by the sending branch');
});

it('refuses to accept or decline a transfer meant for another branch', function () {
    trBuy($this);
    $out = trSend($this, [], accept: false);

    // Called directly, with Kumasi named as the receiver of a transfer that is addressed to Central.
    expect(fn () => app(PostingService::class)->acceptTransfer($out, $this->w->kumasi, $this->w->kumasiManager))
        ->toThrow(PostingError::class, 'not addressed to this branch')
        ->and(fn () => app(PostingService::class)->declineTransfer($out, $this->w->kumasi, 'no', $this->w->kumasiManager))
        ->toThrow(PostingError::class, 'not addressed to this branch');
});

it('shows the sending branch its gold on the way, and the receiving branch what is waiting for it', function () {
    trBuy($this);
    trSend($this, ['weight' => '300.00'], accept: false);

    $kumasi = trProps($this->actingAs($this->w->kumasiManager)->get(route('stock.index'))->assertOk());
    $central = trProps($this->actingAs($this->w->centralManager)->get(route('stock.index'))->assertOk());

    expect($kumasi['onTheWay']['sent'])->toBe(['count' => 1, 'grams' => '300.00'])->and($kumasi['onTheWay']['incoming'])->toBeNull()
        // It is not in Kumasi's stock any more, and not yet in Central's.
        ->and($kumasi['totals']['grams'])->toBe('1,794.25')->and($central['totals']['grams'])->toBe('0.00')
        ->and($central['onTheWay']['incoming'])->toBe(['count' => 1, 'grams' => '300.00'])->and($central['onTheWay']['sent'])->toBeNull();
});

it('writes the receiving half as the receiving branch even when an owner accepts for it', function () {
    trBuy($this);
    $out = trSend($this, [], accept: false);

    // An owner working in Central's office may answer for it, and the document still lands in Central's books.
    $this->actingAs($this->w->owner)->withSession(['active_branch_id' => $this->w->central->id])
        ->post(route('transfers.accept', $out->id))->assertSessionHasNoErrors();

    expect(trIn($out)->branch_id)->toBe($this->w->central->id)->and(trIn($out)->created_by)->toBe($this->w->owner->id);
});
