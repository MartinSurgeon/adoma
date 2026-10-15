<?php

use App\Domain\Documents\Document;
use App\Domain\Documents\JournalLine;
use App\Domain\Gold\GoldLine;
use App\Domain\Ledgers\ChartOfAccounts;
use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use App\Domain\Stock\StockLot;
use App\Domain\Support\Money;
use App\Domain\Tenancy\Branch;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Support\World;

beforeEach(function () {
    $this->w = World::build();

    $this->supplier = gdhLedger($this->w->kumasi, 'Bright', LedgerKind::Supplier);
    $this->customer = gdhLedger($this->w->kumasi, 'Passion Gold', LedgerKind::Customer);
    $this->bank = gdhLedger($this->w->kumasi, 'Kumasi Bank', LedgerKind::Bank);
    $this->centralSupplier = gdhLedger($this->w->central, 'Central Supplier', LedgerKind::Supplier);
    $this->otherSupplier = gdhLedger($this->w->otherBranch, 'Other Co Supplier', LedgerKind::Supplier);
});

function gdhLedger(Branch $branch, string $name, LedgerKind $kind, bool $active = true): Ledger
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

/** The purchase form: row 2 of the client's sheet, valid unless overridden. */
function purchaseForm(object $t, array $overrides = []): array
{
    return array_merge([
        'date' => now()->toDateString(),
        'ledger_id' => $t->supplier->id,
        'form' => 'raw',
        'weight' => '2094.25',
        'water_weight' => '114.56',
        'karat' => '',
        'price' => '10600',
        'note' => 'first lot',
        'idempotency_key' => (string) Str::uuid(),
    ], $overrides);
}

function saleForm(object $t, array $overrides = []): array
{
    return purchaseForm($t, array_merge(['ledger_id' => $t->customer->id, 'weight' => '1000.00', 'water_weight' => '54.70', 'price' => '12000', 'note' => ''], $overrides));
}

function gdhProps($response): array
{
    return $response->viewData('page')['props'];
}

/** Record a purchase over HTTP as the Kumasi clerk and return the document. */
function gdhBuy(object $t, array $overrides = []): Document
{
    $t->actingAs($t->w->kumasiClerk)->post(route('purchases.store'), purchaseForm($t, $overrides))->assertSessionHasNoErrors();

    return Document::withoutGlobalScopes()->where('type', 'purchase')->latest('id')->firstOrFail();
}

// ------------------------------------------------------------------ the forms

it('shows the purchase form with the base karat the price is quoted at', function () {
    $props = gdhProps($this->actingAs($this->w->kumasiClerk)->get(route('purchases.create'))->assertOk());

    expect($props['type'])->toBe('purchase')
        ->and($props['words']['party'])->toBe('Bought from')
        ->and($props['baseKarat'])->toBe(23)
        ->and($props['office'])->toBe('Kumasi')
        ->and($props['today'])->toBe(now()->toDateString())
        ->and($props['inStock'])->toBeNull()
        ->and(Str::isUuid($props['idempotencyKey']))->toBeTrue();
});

it('shows the sale form with how much gold the branch has', function () {
    gdhBuy($this);

    $props = gdhProps($this->actingAs($this->w->kumasiClerk)->get(route('sales.create'))->assertOk());

    expect($props['words']['party'])->toBe('Sold to')->and($props['inStock'])->toBe('2,094.25');
});

it('keeps viewers off the forms and the calculation', function () {
    $this->actingAs($this->w->kumasiViewer);

    $this->get(route('purchases.create'))->assertForbidden();
    $this->get(route('sales.create'))->assertForbidden();
    $this->post(route('purchases.store'), purchaseForm($this))->assertForbidden();
    $this->postJson(route('gold.calculate'), ['form' => 'raw', 'weight' => '2094.25', 'water_weight' => '114.56', 'price' => '10600'])->assertForbidden();
    expect(Document::withoutGlobalScopes()->count())->toBe(0);
});

it('asks an admin viewing all branches to choose one first', function () {
    $this->actingAs($this->w->owner)->withSession(['active_branch_id' => 'all']);

    $this->get(route('purchases.create'))->assertOk()->assertInertia(fn ($page) => $page->where('needsOffice', true));
    $this->post(route('purchases.store'), purchaseForm($this))->assertForbidden();
});

// -------------------------------------------------------- the live calculation

it('gives the sheet\'s figures for the first row, as the form needs them', function () {
    $data = $this->actingAs($this->w->kumasiClerk)
        ->postJson(route('gold.calculate'), ['type' => 'purchase', 'form' => 'raw', 'weight' => '2094.25', 'water_weight' => '114.56', 'price' => '10600'])
        ->assertOk()->json();

    expect($data['ok'])->toBeTrue()
        ->and($data['figures']['pounds'])->toBe('270.22')
        ->and($data['figures']['density'])->toBe('18.28')
        ->and($data['figures']['karat'])->toBe('22.45')
        ->and($data['figures']['amount'])->toBe('2795837.00')
        ->and($data['figures']['amount_text'])->toBe('GH₵ 2,795,837.00')
        ->and($data['figures']['formula_version'])->toBe(1);
});

it('works out refined gold from the karat typed', function () {
    $data = $this->actingAs($this->w->kumasiClerk)
        ->postJson(route('gold.calculate'), ['form' => 'refined', 'weight' => '1000', 'karat' => '24', 'price' => '10600'])->json();

    expect($data['figures']['amount'])->toBe('1427184.00')->and($data['figures']['density'])->toBeNull();
});

it('says what is wrong and which box it belongs to', function (array $input, string $field) {
    $data = $this->actingAs($this->w->kumasiClerk)->postJson(route('gold.calculate'), $input)->assertOk()->json();

    expect($data['ok'])->toBeFalse()->and($data['field'])->toBe($field)->and($data['message'])->not->toBeEmpty();
})->with([
    'no water weight' => [['form' => 'raw', 'weight' => '2094.25', 'water_weight' => '', 'price' => '10600'], 'water_weight'],
    'not a number' => [['form' => 'raw', 'weight' => 'abc', 'water_weight' => '114.56', 'price' => '10600'], 'weight'],
    'a density heavier than gold' => [['form' => 'raw', 'weight' => '1940', 'water_weight' => '100', 'price' => '10600'], 'water_weight'],
    'a karat above 24' => [['form' => 'refined', 'weight' => '100', 'karat' => '30', 'price' => '10600'], 'karat'],
    'no price' => [['form' => 'refined', 'weight' => '100', 'karat' => '22', 'price' => '0'], 'price'],
]);

it('tells a sale whether there is enough gold in stock', function () {
    gdhBuy($this, ['weight' => '1000.00', 'water_weight' => '54.70']);
    $this->actingAs($this->w->kumasiClerk);

    $enough = $this->postJson(route('gold.calculate'), ['type' => 'sale', 'form' => 'refined', 'weight' => '600', 'karat' => '22.45', 'price' => '12000'])->json();
    $tooMuch = $this->postJson(route('gold.calculate'), ['type' => 'sale', 'form' => 'refined', 'weight' => '1000.01', 'karat' => '22.45', 'price' => '12000'])->json();

    expect($enough['stock_ok'])->toBeTrue()->and($enough['in_stock'])->toBe('1,000.00')
        ->and($tooMuch['stock_ok'])->toBeFalse();
});

it('needs a signed-in person to calculate', function () {
    $this->postJson(route('gold.calculate'), ['form' => 'raw'])->assertUnauthorized();
});

// ------------------------------------------------------------------ purchasing

it('records a purchase and goes to the saved page', function () {
    $response = $this->actingAs($this->w->kumasiClerk)->post(route('purchases.store'), purchaseForm($this));

    $document = Document::withoutGlobalScopes()->firstOrFail();

    $response->assertSessionHasNoErrors()->assertRedirect(route('documents.show', $document))
        ->assertSessionHas('success', 'Purchase PCH-KUM-000001 was saved.');

    expect($document->total)->toBe('2795837.00')
        ->and($document->ledger_id)->toBe($this->supplier->id)
        ->and($document->branch_id)->toBe($this->w->kumasi->id)
        ->and($document->created_by)->toBe($this->w->kumasiClerk->id)
        ->and($document->note)->toBe('first lot')
        ->and(GoldLine::withoutGlobalScopes()->count())->toBe(1)
        ->and(StockLot::withoutGlobalScopes()->count())->toBe(1)
        ->and(JournalLine::withoutGlobalScopes()->count())->toBe(2);
});

it('records refined gold with a typed karat', function () {
    $this->actingAs($this->w->kumasiClerk)->post(route('purchases.store'), purchaseForm($this, [
        'form' => 'refined', 'karat' => '24', 'water_weight' => '', 'weight' => '500.00',
    ]))->assertSessionHasNoErrors();

    $line = GoldLine::withoutGlobalScopes()->firstOrFail();

    expect($line->form)->toBe('refined')->and($line->karat)->toBe('24.00')->and($line->water_weight_g)->toBeNull();
});

it('works the figures out again on the server and ignores any it is sent', function () {
    $this->actingAs($this->w->kumasiClerk)->post(route('purchases.store'), purchaseForm($this, [
        'amount' => '1.00', 'total' => '1.00', 'pounds' => '1', 'density' => '99', 'tenant_id' => $this->w->other->id,
        'branch_id' => $this->w->central->id, 'status' => 'reversed', 'created_by' => $this->w->owner->id, 'formula_set_id' => 999,
    ]))->assertSessionHasNoErrors();

    $document = Document::withoutGlobalScopes()->firstOrFail();
    $line = GoldLine::withoutGlobalScopes()->firstOrFail();

    expect($document->total)->toBe('2795837.00')
        ->and($document->tenant_id)->toBe($this->w->adoma->id)
        ->and($document->branch_id)->toBe($this->w->kumasi->id)
        ->and($document->status)->toBe(Document::POSTED)
        ->and($line->pounds)->toBe('270.22')->and($line->density)->toBe('18.28');
});

it('puts each mistake next to the right box', function (array $override, string $field) {
    $this->actingAs($this->w->kumasiClerk)->post(route('purchases.store'), purchaseForm($this, $override))->assertSessionHasErrors($field);

    expect(Document::withoutGlobalScopes()->count())->toBe(0)->and(StockLot::withoutGlobalScopes()->count())->toBe(0);
})->with([
    'no water weight' => [['water_weight' => ''], 'water_weight'],
    'a weight that is not a number' => [['weight' => 'heavy'], 'weight'],
    'a weight of zero' => [['weight' => '0'], 'weight'],
    'three decimals' => [['weight' => '2094.255'], 'weight'],
    'a price of zero' => [['price' => '0'], 'price'],
    'no price' => [['price' => ''], 'price'],
    'a density heavier than gold' => [['weight' => '1940', 'water_weight' => '100'], 'water_weight'],
    'too much water' => [['weight' => '1000', 'water_weight' => '100'], 'water_weight'],
    'a future date' => [['date' => '2099-01-01'], 'date'],
    'a date in the wrong format' => [['date' => '14/10/2026'], 'date'],
    'no supplier chosen' => [['ledger_id' => ''], 'ledger_id'],
    'a note that is too long' => [['note' => str_repeat('x', 501)], 'note'],
    'a form that is neither' => [['form' => 'alloy'], 'form'],
    'no request key' => [['idempotency_key' => ''], 'idempotency_key'],
]);

it('needs a karat for refined gold', function () {
    $this->actingAs($this->w->kumasiClerk)->post(route('purchases.store'), purchaseForm($this, ['form' => 'refined', 'water_weight' => '', 'karat' => '']))
        ->assertSessionHasErrors('karat');
});

it('refuses a refined karat above 24', function () {
    $this->actingAs($this->w->kumasiClerk)->post(route('purchases.store'), purchaseForm($this, ['form' => 'refined', 'karat' => '25']))
        ->assertSessionHasErrors('karat');
});

it('refuses people who are not a customer or supplier, or that this branch cannot use', function (string $which) {
    $ids = [
        'a bank' => $this->bank->id,
        'another branch\'s supplier' => $this->centralSupplier->id,
        'another business\'s supplier' => $this->otherSupplier->id,
        'a system account' => Ledger::withoutGlobalScopes()->where('tenant_id', $this->w->adoma->id)->where('system_key', 'gold_stock')->value('id'),
        'a ledger that does not exist' => 999999,
    ];

    $this->actingAs($this->w->kumasiClerk)->post(route('purchases.store'), purchaseForm($this, ['ledger_id' => $ids[$which]]))->assertSessionHasErrors('ledger_id');
})->with(['a bank', 'another branch\'s supplier', 'another business\'s supplier', 'a system account', 'a ledger that does not exist']);

it('refuses a switched-off supplier', function () {
    $off = gdhLedger($this->w->kumasi, 'Closed Supplier', LedgerKind::Supplier, active: false);

    $this->actingAs($this->w->kumasiClerk)->post(route('purchases.store'), purchaseForm($this, ['ledger_id' => $off->id]))->assertSessionHasErrors('ledger_id');
});

it('can buy from a customer too', function () {
    $this->actingAs($this->w->kumasiClerk)->post(route('purchases.store'), purchaseForm($this, ['ledger_id' => $this->customer->id]))->assertSessionHasNoErrors();

    expect(Document::withoutGlobalScopes()->count())->toBe(1);
});

it('posts once when the same purchase form is sent twice', function () {
    $data = purchaseForm($this);
    $this->actingAs($this->w->kumasiClerk);

    $first = $this->post(route('purchases.store'), $data);
    $second = $this->post(route('purchases.store'), $data);

    $document = Document::withoutGlobalScopes()->firstOrFail();

    $first->assertRedirect(route('documents.show', $document));
    $second->assertRedirect(route('documents.show', $document));
    expect(Document::withoutGlobalScopes()->count())->toBe(1)->and(StockLot::withoutGlobalScopes()->count())->toBe(1);
});

// --------------------------------------------------------------------- selling

it('records a sale and takes the gold out of stock', function () {
    gdhBuy($this);

    $response = $this->actingAs($this->w->kumasiClerk)->post(route('sales.store'), saleForm($this));
    $sale = Document::withoutGlobalScopes()->where('type', 'sale')->firstOrFail();

    $response->assertSessionHasNoErrors()->assertRedirect(route('documents.show', $sale))
        ->assertSessionHas('success', 'Sale SAL-KUM-000001 was saved.');

    expect($sale->ledger_id)->toBe($this->customer->id)
        ->and(StockLot::withoutGlobalScopes()->firstOrFail()->remaining_g)->toBe('1094.25')
        ->and(JournalLine::withoutGlobalScopes()->where('document_id', $sale->id)->count())->toBe(4);
});

it('refuses a sale for more gold than the branch has, with a clear message', function () {
    gdhBuy($this, ['weight' => '1000.00', 'water_weight' => '54.70']);

    $this->actingAs($this->w->kumasiClerk)->post(route('sales.store'), saleForm($this, ['weight' => '1000.01']))
        ->assertSessionHasErrors(['weight' => 'Not enough gold in stock. Kumasi has 1,000.00 g and this sale is 1,000.01 g.']);

    expect(Document::withoutGlobalScopes()->where('type', 'sale')->count())->toBe(0);
});

it('refuses a sale when the branch has no gold', function () {
    $this->actingAs($this->w->kumasiClerk)->post(route('sales.store'), saleForm($this))->assertSessionHasErrors('weight');
});

it('cannot sell gold another branch holds', function () {
    $this->actingAs($this->w->centralManager)->post(route('purchases.store'), purchaseForm($this, ['ledger_id' => $this->centralSupplier->id]))->assertSessionHasNoErrors();

    $this->actingAs($this->w->kumasiClerk)->post(route('sales.store'), saleForm($this))->assertSessionHasErrors('weight');
});

it('posts once when the same sale form is sent twice', function () {
    gdhBuy($this);
    $data = saleForm($this);
    $this->actingAs($this->w->kumasiClerk);

    $this->post(route('sales.store'), $data);
    $this->post(route('sales.store'), $data);

    expect(Document::withoutGlobalScopes()->where('type', 'sale')->count())->toBe(1)
        ->and(StockLot::withoutGlobalScopes()->firstOrFail()->remaining_g)->toBe('1094.25');
});

// -------------------------------------------------------------- reading them

it('shows the weighing on the saved page', function () {
    $document = gdhBuy($this);

    $props = gdhProps($this->actingAs($this->w->kumasiClerk)->get(route('documents.show', $document))->assertOk());

    expect($props['gold'])->toBe([
        'form' => 'raw', 'weight' => '2,094.25', 'water_weight' => '114.56', 'pounds' => '270.22', 'density' => '18.28',
        'karat' => '22.45', 'price' => 'GH₵ 10,600.00', 'base_karat' => 23, 'formula_version' => 1,
    ])
        ->and($props['document']['total'])->toBe('GH₵ 2,795,837.00')
        ->and($props['document']['total_in_words'])->toBe('Two Million Seven Hundred Ninety Five Thousand Eight Hundred Thirty Seven Ghana Cedis, No Pesewas')
        ->and($props['document']['cash'])->toBeNull()
        ->and($props['party_balance'])->toBe('GH₵ 2,795,837.00 · you owe them');
});

it('shows profit on a sale to managers and above, never to clerks or viewers', function () {
    gdhBuy($this);
    $this->actingAs($this->w->kumasiClerk)->post(route('sales.store'), saleForm($this));
    $sale = Document::withoutGlobalScopes()->where('type', 'sale')->firstOrFail();

    $profit = fn ($who) => gdhProps($this->actingAs($this->w->{$who})->get(route('documents.show', $sale)))['profit'];

    expect($profit('kumasiClerk'))->toBeNull()
        ->and($profit('kumasiViewer'))->toBeNull()
        ->and($profit('kumasiManager'))->not->toBeNull()
        ->and($profit('kumasiManager')['is_loss'])->toBeFalse()
        ->and($profit('kumasiManager')['cost'])->toStartWith('GH₵ ');

    $props = gdhProps($this->actingAs($this->w->kumasiManager)->get(route('documents.show', $sale)));
    $cost = Money::cents(str_replace(['GH₵ ', ','], '', $props['profit']['cost']));
    $profitCents = Money::cents(str_replace(['GH₵ ', ','], '', $props['profit']['profit']));
    $amount = Money::cents($sale->total);

    expect($cost + $profitCents)->toBe($amount);
});

it('says so when a sale was made at a loss', function () {
    gdhBuy($this);
    $this->actingAs($this->w->kumasiClerk)->post(route('sales.store'), saleForm($this, ['price' => '1000']));
    $sale = Document::withoutGlobalScopes()->where('type', 'sale')->firstOrFail();

    $profit = gdhProps($this->actingAs($this->w->kumasiManager)->get(route('documents.show', $sale)))['profit'];

    expect($profit['is_loss'])->toBeTrue();
});

it('shows no profit on a purchase or on a reversal', function () {
    $purchase = gdhBuy($this);
    $this->actingAs($this->w->kumasiManager);

    expect(gdhProps($this->get(route('documents.show', $purchase)))['profit'])->toBeNull();

    $this->post(route('documents.reverse', $purchase), ['reason' => 'Mistake']);
    $reversal = Document::withoutGlobalScopes()->where('reverses_id', $purchase->id)->firstOrFail();

    expect(gdhProps($this->get(route('documents.show', $reversal)))['profit'])->toBeNull()
        ->and(gdhProps($this->get(route('documents.show', $reversal)))['gold'])->toBeNull();
});

it('prints the weighing on the purchase slip and the sales receipt', function () {
    $purchase = gdhBuy($this);
    $this->actingAs($this->w->kumasiClerk)->post(route('sales.store'), saleForm($this));
    $sale = Document::withoutGlobalScopes()->where('type', 'sale')->firstOrFail();

    $slip = gdhProps($this->get(route('documents.print', $purchase)));
    $receipt = gdhProps($this->get(route('documents.print', [$sale, 'layout' => 'thermal'])));

    expect($slip['gold']['weight'])->toBe('2,094.25')->and($slip['document']['type'])->toBe('purchase')
        ->and($receipt['layout'])->toBe('thermal')->and($receipt['document']['type'])->toBe('sale')
        ->and(array_key_exists('profit', $receipt) ? $receipt['profit'] : null)->toBeNull();
});

it('lists purchases and sales among the transactions and filters by them', function () {
    gdhBuy($this);
    $this->actingAs($this->w->kumasiClerk)->post(route('sales.store'), saleForm($this));

    $rows = fn (array $q) => collect(gdhProps($this->get(route('documents.index', $q)))['documents']['data'])->pluck('number')->all();

    expect($rows(['type' => 'purchase']))->toBe(['PCH-KUM-000001'])
        ->and($rows(['type' => 'sale']))->toBe(['SAL-KUM-000001'])
        ->and($rows([]))->toHaveCount(2);

    expect(collect(gdhProps($this->get(route('documents.index')))['types'])->pluck('value')->all())->toContain('purchase', 'sale', 'receipt', 'payment');
});

// ------------------------------------------------------------------ reversing

it('reverses a purchase from the saved page, and the gold leaves stock', function () {
    $purchase = gdhBuy($this);

    $this->actingAs($this->w->kumasiManager)->post(route('documents.reverse', $purchase), ['reason' => 'Wrong supplier'])->assertSessionHasNoErrors();

    expect($purchase->fresh()->status)->toBe(Document::REVERSED)
        ->and(StockLot::withoutGlobalScopes()->firstOrFail()->remaining_g)->toBe('0.00');
});

it('tells the manager why a purchase cannot be reversed once some gold is sold', function () {
    $purchase = gdhBuy($this);
    $this->actingAs($this->w->kumasiClerk)->post(route('sales.store'), saleForm($this));

    $this->actingAs($this->w->kumasiManager)->post(route('documents.reverse', $purchase), ['reason' => 'Mistake'])
        ->assertSessionHasErrors(['reason' => 'Some of this gold has already been sold or sent to another branch, so this purchase cannot be reversed. Reverse those first.']);

    expect($purchase->fresh()->status)->toBe(Document::POSTED);
});

it('puts the gold back when a sale is reversed', function () {
    gdhBuy($this);
    $this->actingAs($this->w->kumasiClerk)->post(route('sales.store'), saleForm($this));
    $sale = Document::withoutGlobalScopes()->where('type', 'sale')->firstOrFail();

    $this->actingAs($this->w->kumasiManager)->post(route('documents.reverse', $sale), ['reason' => 'Wrong customer'])->assertSessionHasNoErrors();

    expect(StockLot::withoutGlobalScopes()->firstOrFail()->remaining_g)->toBe('2094.25');
});

// ------------------------------------------------------------------- scoping

it('hides another branch\'s gold documents completely', function () {
    $purchase = gdhBuy($this);

    $this->actingAs($this->w->centralManager);
    $this->get(route('documents.show', $purchase))->assertNotFound();
    $this->get(route('documents.print', $purchase))->assertNotFound();
});

it('shows the gold in stock on the dashboard, for the branch only', function () {
    gdhBuy($this);
    $this->actingAs($this->w->centralManager)->post(route('purchases.store'), purchaseForm($this, ['ledger_id' => $this->centralSupplier->id, 'weight' => '1000.00', 'water_weight' => '54.70']));

    expect(gdhProps($this->actingAs($this->w->kumasiClerk)->get(route('dashboard')))['goldInStock'])->toBe('2,094.25')
        ->and(gdhProps($this->actingAs($this->w->centralManager)->get(route('dashboard')))['goldInStock'])->toBe('1,000.00')
        ->and(gdhProps($this->actingAs($this->w->owner)->withSession(['active_branch_id' => 'all'])->get(route('dashboard')))['goldInStock'])->toBe('3,094.25');
});

it('shows no stock to a person with no business', function () {
    expect(gdhProps($this->actingAs(User::factory()->create())->get(route('dashboard')))['goldInStock'])->toBe('0.00');
});

it('shows the record buttons and navigation to people who can record', function () {
    $tenancy = fn ($who) => gdhProps($this->actingAs($this->w->{$who})->get(route('dashboard')))['tenancy']['can_record'];

    expect($tenancy('kumasiClerk'))->toBeTrue()->and($tenancy('kumasiViewer'))->toBeFalse();
});

it('writes who bought and who sold into the audit log', function () {
    gdhBuy($this);
    $this->actingAs($this->w->kumasiClerk)->post(route('sales.store'), saleForm($this));

    $descriptions = App\Domain\Audit\AuditLog::withoutGlobalScopes()->where('auditable_type', Document::class)->orderBy('id')->get()
        ->map(fn ($l) => $l->actor_name.' '.$l->describe())->all();

    expect($descriptions)->toContain(
        $this->w->kumasiClerk->name.' added document “PCH-KUM-000001”',
        $this->w->kumasiClerk->name.' added document “SAL-KUM-000001”',
    );
});
