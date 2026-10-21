<?php

use App\Domain\Documents\Document;
use App\Domain\Gold\FormulaSet;
use App\Domain\Gold\GoldCalculator;
use App\Domain\Gold\GoldLine;
use App\Domain\Ledgers\ChartOfAccounts;
use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use App\Domain\Stock\StockLot;
use App\Domain\Stock\StockMovement;
use App\Domain\Tenancy\Branch;
use Illuminate\Support\Str;
use Tests\Support\World;

// A sale of the exact purchases the seller chose, instead of the oldest gold first. Part of a lot may be sold, several
// lots can be in one sale, and what is not chosen stays in stock.
//
// Kumasi holds three lots:
//   A  2,094.25 g raw      22.45 karat  bought 5 Mar
//   B  2,081.01 g raw      22.45 karat  bought 20 Mar
//   C    300.00 g refined  24.00 karat  bought 1 Apr

beforeEach(function () {
    $this->w = World::build();
    // The figures below come from the client's earlier sheet, which had no karat deduction.
    $this->w->earlierSheetFormula();

    $this->supplier = clLedger($this->w->kumasi, 'Bright', LedgerKind::Supplier);
    $this->customer = clLedger($this->w->kumasi, 'Passion Gold', LedgerKind::Customer);
    $this->centralSupplier = clLedger($this->w->central, 'Central Supplier', LedgerKind::Supplier);

    $this->a = clBuy($this, '2026-03-05', ['weight' => '2094.25', 'water_weight' => '114.56']);
    $this->b = clBuy($this, '2026-03-20', ['weight' => '2081.01', 'water_weight' => '113.78']);
    $this->c = clBuy($this, '2026-04-01', ['form' => 'refined', 'weight' => '300.00', 'water_weight' => '', 'karat' => '24']);
});

function clLedger(Branch $branch, string $name, LedgerKind $kind): Ledger
{
    return Ledger::create([
        'tenant_id' => $branch->tenant_id, 'branch_id' => $branch->id, 'is_shared' => false,
        'group_id' => ChartOfAccounts::groupFor($branch->tenant_id, $kind)->id, 'kind' => $kind, 'name' => $name,
    ]);
}

/** Buy gold as the Kumasi manager and return the lot it made. */
function clBuy(object $t, string $date, array $more): StockLot
{
    $t->actingAs($t->w->kumasiManager)->post(route('purchases.store'), array_merge([
        'date' => $date, 'ledger_id' => $t->supplier->id, 'form' => 'raw', 'karat' => '', 'price' => '10600', 'note' => '',
        'idempotency_key' => (string) Str::uuid(),
    ], $more))->assertSessionHasNoErrors();

    return StockLot::withoutGlobalScopes()->latest('id')->firstOrFail();
}

/** The sale form for chosen lots: [lot => grams, ...]. */
function clSale(object $t, array $take, array $more = []): array
{
    return array_merge([
        'date' => now()->toDateString(), 'ledger_id' => $t->customer->id, 'choose_lots' => true,
        'lots' => collect($take)->map(fn ($grams, $id) => ['id' => $id, 'weight' => $grams])->values()->all(),
        'price' => '12000', 'note' => '', 'idempotency_key' => (string) Str::uuid(),
    ], $more);
}

function clAmount(float|string $karat, string $grams, int $price = 12000): int
{
    return GoldCalculator::amountFor(
        FormulaSet::query()->withoutGlobalScopes()->firstOrFail(),
        (int) round((float) $grams * 100), (int) round((float) $karat * 100), $price * 100,
    );
}

function clRemaining(StockLot $lot): string
{
    return StockLot::withoutGlobalScopes()->find($lot->id)->remaining_g;
}

it('sells the purchases that were chosen, not the oldest, and leaves the rest', function () {
    $this->actingAs($this->w->kumasiClerk)->post(route('sales.store'), clSale($this, [$this->b->id => '500.00']))->assertSessionHasNoErrors();

    $sale = Document::withoutGlobalScopes()->where('type', 'sale')->firstOrFail();

    expect(clRemaining($this->a))->toEqual('2094.25')
        ->and(clRemaining($this->b))->toEqual('1581.01')
        ->and(clRemaining($this->c))->toEqual('300.00')
        ->and($sale->total)->toEqual(clAmount('22.45', '500.00'));
});

it('prices each lot at its own karat, and adds the slices up for a sale of several lots', function () {
    $this->actingAs($this->w->kumasiClerk)->post(route('sales.store'), clSale($this, [$this->a->id => '1000.00', $this->c->id => '100.00']))->assertSessionHasNoErrors();

    $sale = Document::withoutGlobalScopes()->where('type', 'sale')->firstOrFail();
    $line = GoldLine::withoutGlobalScopes()->where('document_id', $sale->id)->firstOrFail();
    $expected = clAmount('22.45', '1000.00') + clAmount('24.00', '100.00');

    expect((int) $sale->total)->toBe($expected)
        // 1,000 g at 22.45 and 100 g at 24: (2245 x 1000 + 2400 x 100) / 1100 = 22.59, cut to hundredths.
        ->and($line->weight_g)->toEqual('1100.00')->and($line->karat)->toEqual('22.59')
        ->and($line->form)->toBe('mixed')
        ->and($line->water_weight_g)->toBeNull();

    $moves = StockMovement::withoutGlobalScopes()->where('document_id', $sale->id)->orderBy('id')->get();

    expect($moves)->toHaveCount(2)
        ->and($moves->pluck('weight_g')->map(fn ($g) => (string) (float) $g)->all())->toBe(['-1000', '-100'])
        // Each slice keeps what it sold for.
        ->and($moves->sum(fn ($m) => (float) $m->value))->toEqual((float) $expected);
});

it('lets part of a lot be sold, then the rest of it in another sale', function () {
    $this->actingAs($this->w->kumasiClerk)->post(route('sales.store'), clSale($this, [$this->c->id => '120.00']))->assertSessionHasNoErrors();
    expect(clRemaining($this->c))->toEqual('180.00');

    $this->actingAs($this->w->kumasiClerk)->post(route('sales.store'), clSale($this, [$this->c->id => '180.00']))->assertSessionHasNoErrors();
    $lot = StockLot::withoutGlobalScopes()->find($this->c->id);

    // Across both sales the lot gave up exactly what it cost, nothing lost to rounding.
    expect($lot->remaining_g)->toEqual('0.00')->and($lot->remaining_cost)->toEqual('0.00');
});

it('takes the cost from the lots chosen, so the profit is worked out from them', function () {
    $this->actingAs($this->w->kumasiClerk)->post(route('sales.store'), clSale($this, [$this->b->id => '2081.01']))->assertSessionHasNoErrors();
    $sale = Document::withoutGlobalScopes()->where('type', 'sale')->firstOrFail();

    $props = $this->actingAs($this->w->kumasiManager)->get(route('documents.show', $sale))->assertOk()->viewData('page')['props'];

    // All of lot B was sold, at 12,000 against the 10,600 it was bought at.
    expect($props['profit']['cost'])->toBe('GH₵ 2,778,144.00')->and($props['profit']['is_loss'])->toBeFalse();
});

it('shows on the sale which purchases the gold came from', function () {
    $this->actingAs($this->w->kumasiClerk)->post(route('sales.store'), clSale($this, [$this->a->id => '1000.00', $this->c->id => '100.00']))->assertSessionHasNoErrors();
    $sale = Document::withoutGlobalScopes()->where('type', 'sale')->firstOrFail();

    $lots = $this->actingAs($this->w->kumasiClerk)->get(route('documents.show', $sale))->assertOk()->viewData('page')['props']['lots_sold'];

    expect($lots)->toHaveCount(2)
        ->and($lots[0])->toMatchArray(['origin_name' => 'Bright', 'date' => '5 Mar 2026', 'form' => 'Raw', 'karat' => '22.45', 'weight' => '1,000.00'])
        ->and($lots[1])->toMatchArray(['date' => '1 Apr 2026', 'form' => 'Refined', 'karat' => '24.00', 'weight' => '100.00'])
        ->and($lots[0]['amount'])->toStartWith('GH₵ ');
});

it('also shows where an ordinary sale\'s gold came from, without a price for each slice', function () {
    $this->actingAs($this->w->kumasiClerk)->post(route('sales.store'), [
        'date' => now()->toDateString(), 'ledger_id' => $this->customer->id, 'form' => 'raw', 'weight' => '1000.00', 'water_weight' => '54.70',
        'karat' => '', 'price' => '12000', 'note' => '', 'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();
    $sale = Document::withoutGlobalScopes()->where('type', 'sale')->firstOrFail();

    $lots = $this->actingAs($this->w->kumasiClerk)->get(route('documents.show', $sale))->viewData('page')['props']['lots_sold'];

    expect($lots)->toHaveCount(1)->and($lots[0]['weight'])->toBe('1,000.00')->and($lots[0]['amount'])->toBeNull();
});

it('puts the gold back into the same lots when the sale is reversed', function () {
    $this->actingAs($this->w->kumasiClerk)->post(route('sales.store'), clSale($this, [$this->b->id => '500.00', $this->c->id => '100.00']))->assertSessionHasNoErrors();
    $sale = Document::withoutGlobalScopes()->where('type', 'sale')->firstOrFail();

    $this->actingAs($this->w->kumasiManager)->post(route('documents.reverse', $sale->id), ['reason' => 'Wrong customer'])->assertSessionHasNoErrors();

    expect(clRemaining($this->a))->toEqual('2094.25')
        ->and(clRemaining($this->b))->toEqual('2081.01')
        ->and(clRemaining($this->c))->toEqual('300.00')
        ->and(StockLot::withoutGlobalScopes()->find($this->b->id)->remaining_cost)->toEqual(StockLot::withoutGlobalScopes()->find($this->b->id)->cost);
});

it('previews the figures of the lots chosen', function () {
    $data = $this->actingAs($this->w->kumasiClerk)->postJson(route('gold.calculate'), [
        'type' => 'sale', 'choose_lots' => true, 'price' => '12000',
        'lots' => [['id' => $this->a->id, 'weight' => '1000.00'], ['id' => $this->c->id, 'weight' => '100.00']],
    ])->json();

    expect($data['ok'])->toBeTrue()->and($data['figures']['weight'])->toBe('1100.00')->and($data['figures']['karat'])->toBe('22.59')
        ->and($data['figures']['amount'])->toBe(number_format(clAmount('22.45', '1000.00') + clAmount('24.00', '100.00'), 2, '.', ''));
});

it('refuses more than what is left of a lot, and says how much is left', function () {
    $this->actingAs($this->w->kumasiClerk)->post(route('sales.store'), clSale($this, [$this->c->id => '300.01']))->assertSessionHasErrors('lots');

    expect(Document::withoutGlobalScopes()->where('type', 'sale')->count())->toBe(0);

    $preview = $this->actingAs($this->w->kumasiClerk)->postJson(route('gold.calculate'), [
        'type' => 'sale', 'choose_lots' => true, 'price' => '12000', 'lots' => [['id' => $this->c->id, 'weight' => '300.01']],
    ])->json();

    expect($preview['ok'])->toBeFalse()->and($preview['field'])->toBe('lots')->and($preview['message'])->toContain('Only 300.00 g is left');
});

it('refuses nothing chosen, a lot twice, a weight of zero, and a weight that is not a number', function () {
    $post = fn (array $form) => $this->actingAs($this->w->kumasiClerk)->post(route('sales.store'), $form);

    $post(clSale($this, []))->assertSessionHasErrors('lots');
    $post(clSale($this, [], ['lots' => [['id' => $this->a->id, 'weight' => '10'], ['id' => $this->a->id, 'weight' => '20']]]))->assertSessionHasErrors('lots');
    $post(clSale($this, [$this->a->id => '0']))->assertSessionHasErrors('lots');
    $post(clSale($this, [$this->a->id => 'lots']))->assertSessionHasErrors('lots');

    expect(Document::withoutGlobalScopes()->where('type', 'sale')->count())->toBe(0);
});

it('will not sell another branch\'s gold, even by sending its lot number', function () {
    $this->actingAs($this->w->centralManager)->post(route('purchases.store'), [
        'date' => '2026-03-05', 'ledger_id' => $this->centralSupplier->id, 'form' => 'refined', 'weight' => '500.00', 'karat' => '24',
        'water_weight' => '', 'price' => '10600', 'note' => '', 'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();
    $central = StockLot::withoutGlobalScopes()->latest('id')->firstOrFail();

    $this->actingAs($this->w->kumasiClerk)->post(route('sales.store'), clSale($this, [$central->id => '100.00']))->assertSessionHasErrors('lots');

    expect(clRemaining($central))->toEqual('500.00')->and(Document::withoutGlobalScopes()->where('type', 'sale')->count())->toBe(0);
});

it('saves a sale once when the same form is sent twice', function () {
    $form = clSale($this, [$this->b->id => '100.00']);

    $this->actingAs($this->w->kumasiClerk)->post(route('sales.store'), $form)->assertSessionHasNoErrors();
    $this->actingAs($this->w->kumasiClerk)->post(route('sales.store'), $form)->assertSessionHasNoErrors();

    expect(Document::withoutGlobalScopes()->where('type', 'sale')->count())->toBe(1)->and(clRemaining($this->b))->toEqual('1981.01');
});

it('does not let a second sale take gold the first already took', function () {
    $this->actingAs($this->w->kumasiClerk)->post(route('sales.store'), clSale($this, [$this->c->id => '300.00']))->assertSessionHasNoErrors();
    $this->actingAs($this->w->kumasiClerk)->post(route('sales.store'), clSale($this, [$this->c->id => '1.00']))->assertSessionHasErrors('lots');

    expect(Document::withoutGlobalScopes()->where('type', 'sale')->count())->toBe(1);
});

it('lists the purchases to choose from on the sale form only', function () {
    $sale = $this->actingAs($this->w->kumasiClerk)->get(route('sales.create'))->assertOk()->viewData('page')['props'];
    $purchase = $this->actingAs($this->w->kumasiClerk)->get(route('purchases.create'))->assertOk()->viewData('page')['props'];

    expect(collect($sale['lots'])->pluck('id')->all())->toBe([$this->a->id, $this->b->id, $this->c->id])
        ->and($sale['lots'][1])->toMatchArray(['date' => '20 Mar 2026', 'form' => 'Raw', 'karat' => '22.45', 'remaining' => '2081.01', 'remaining_text' => '2,081.01', 'from' => 'Bright'])
        ->and($purchase['lots'])->toBe([]);
});

it('keeps a sold-out lot off the list', function () {
    $this->actingAs($this->w->kumasiClerk)->post(route('sales.store'), clSale($this, [$this->c->id => '300.00']))->assertSessionHasNoErrors();

    $lots = $this->actingAs($this->w->kumasiClerk)->get(route('sales.create'))->viewData('page')['props']['lots'];

    expect(collect($lots)->pluck('id')->all())->toBe([$this->a->id, $this->b->id]);
});

it('ignores the chosen lots on a purchase, which makes a lot and takes from none', function () {
    $this->actingAs($this->w->kumasiClerk)->post(route('purchases.store'), [
        'date' => now()->toDateString(), 'ledger_id' => $this->supplier->id, 'form' => 'refined', 'weight' => '50.00', 'karat' => '24',
        'water_weight' => '', 'price' => '10600', 'note' => '', 'idempotency_key' => (string) Str::uuid(),
        'choose_lots' => true, 'lots' => [['id' => $this->a->id, 'weight' => '100']],
    ])->assertSessionHasNoErrors();

    expect(clRemaining($this->a))->toEqual('2094.25')->and(StockLot::withoutGlobalScopes()->count())->toBe(4);
});
