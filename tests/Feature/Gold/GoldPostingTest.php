<?php

use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentType;
use App\Domain\Documents\JournalLine;
use App\Domain\Documents\LedgerBalances;
use App\Domain\Documents\PostingError;
use App\Domain\Documents\PostingService;
use App\Domain\Gold\FormulaSet;
use App\Domain\Gold\GoldCalculator;
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
use App\Domain\Tenancy\TenancyViolation;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Tests\Support\World;

beforeEach(function () {
    $this->w = World::build();

    $this->supplier = gdLedger($this->w->kumasi, 'Bright', LedgerKind::Supplier);
    $this->customer = gdLedger($this->w->kumasi, 'Passion Gold', LedgerKind::Customer);
    $this->bank = gdLedger($this->w->kumasi, 'Kumasi Bank', LedgerKind::Bank);
    $this->centralSupplier = gdLedger($this->w->central, 'Central Supplier', LedgerKind::Supplier);
    $this->otherSupplier = gdLedger($this->w->otherBranch, 'Other Co Supplier', LedgerKind::Supplier);

    $this->ctx = app(TenantContext::class);
    $this->ctx->set($this->w->adoma, $this->w->kumasi);
    $this->clerk = $this->w->kumasiClerk;
    $this->posting = app(PostingService::class);
    $this->formula = FormulaSet::currentFor($this->w->adoma->id);
});

function gdLedger(Branch $branch, string $name, LedgerKind $kind): Ledger
{
    return Ledger::create([
        'tenant_id' => $branch->tenant_id, 'branch_id' => $branch->id, 'is_shared' => false,
        'group_id' => ChartOfAccounts::groupFor($branch->tenant_id, $kind)->id, 'kind' => $kind, 'name' => $name,
    ]);
}

/** Raw gold from the client's sheet: row 2 is 2094.25 g, 114.56 g of water, at 10,600. */
function rawFigures(object $t, string $weight = '2094.25', string $water = '114.56', string $price = '10600'): GoldFigures
{
    return GoldCalculator::raw($t->formula, $weight, $water, $price);
}

function buy(object $t, ?GoldFigures $figures = null, ?string $date = null, $party = null, ?string $key = null, $branch = null): Document
{
    return $t->posting->postPurchase($branch ?? $t->w->kumasi, $date ?? now()->toDateString(), $party ?? $t->supplier, $figures ?? rawFigures($t), 'a parcel', $key, $t->clerk);
}

function sell(object $t, string $weight = '1000.00', string $price = '12000', ?string $key = null, $party = null): Document
{
    // A sale is weighed again, so it has its own water weight. Density 18.28 gives 22.45 karat.
    $figures = GoldCalculator::refined($t->formula, $weight, '22.45', $price);

    return $t->posting->postSale($t->w->kumasi, now()->toDateString(), $party ?? $t->customer, $figures, null, $key, $t->clerk);
}

function bal(Ledger $ledger): int
{
    return LedgerBalances::cents([$ledger->id])[$ledger->id];
}

function sys(string $key): Ledger
{
    return Ledger::where('system_key', $key)->firstOrFail();
}

function lotsLeft(): array
{
    return StockLot::query()->orderBy('date')->orderBy('id')->get()->map(fn ($l) => [Money::fromDb($l->remaining_g), Money::fromDb($l->remaining_cost)])->all();
}

function summary(Document $document): array
{
    return JournalLine::withoutGlobalScopes()->where('document_id', $document->id)->orderBy('id')->get()
        ->map(fn ($l) => [$l->ledger->name, Money::fromDb($l->debit), Money::fromDb($l->credit)])->all();
}

// ------------------------------------------------------------------- purchases

it('posts a purchase: Gold Stock is debited and the supplier is credited', function () {
    $document = buy($this);

    expect($document->number)->toBe('PCH-KUM-000001')
        ->and($document->type)->toBe(DocumentType::Purchase)
        ->and($document->total)->toBe('2795837.00')
        ->and($document->ledger_id)->toBe($this->supplier->id)
        ->and($document->cash_ledger_id)->toBeNull()
        ->and(summary($document))->toBe([['Gold Stock', 279583700, 0], ['Bright', 0, 279583700]])
        ->and(bal($this->supplier))->toBe(-279583700)
        ->and(bal(sys('gold_stock')))->toBe(279583700);
});

it('keeps the weighing and the figures with the document', function () {
    $document = buy($this);
    $line = GoldLine::withoutGlobalScopes()->where('document_id', $document->id)->firstOrFail();

    expect($line->form)->toBe('raw')
        ->and($line->weight_g)->toBe('2094.25')
        ->and($line->water_weight_g)->toBe('114.56')
        ->and($line->pounds)->toBe('270.22')
        ->and($line->density)->toBe('18.28')
        ->and($line->karat)->toBe('22.45')
        ->and($line->price)->toBe('10600.00')
        ->and($line->amount)->toBe('2795837.00')
        ->and($line->formula_set_id)->toBe($this->formula->id)
        ->and($line->formulaSet->version)->toBe(1);
});

it('adds a lot of gold to the branch\'s stock, with a movement to show how it got there', function () {
    $document = buy($this);
    $lot = StockLot::firstOrFail();

    expect($lot->weight_g)->toBe('2094.25')
        ->and($lot->remaining_g)->toBe('2094.25')
        ->and($lot->cost)->toBe('2795837.00')
        ->and($lot->remaining_cost)->toBe('2795837.00')
        ->and($lot->karat)->toBe('22.45')
        ->and($lot->form)->toBe('raw')
        ->and($lot->branch_id)->toBe($this->w->kumasi->id);

    $movement = StockMovement::firstOrFail();
    expect($movement->document_id)->toBe($document->id)->and($movement->weight_g)->toBe('2094.25')->and($movement->cost)->toBe('2795837.00');
});

it('records refined gold with its karat as typed and no water weight', function () {
    $document = buy($this, GoldCalculator::refined($this->formula, '500.00', '24', '10600'));
    $line = GoldLine::withoutGlobalScopes()->where('document_id', $document->id)->firstOrFail();

    expect($line->form)->toBe('refined')->and($line->water_weight_g)->toBeNull()->and($line->density)->toBeNull()->and($line->karat)->toBe('24.00');
});

it('numbers purchases and sales separately', function () {
    expect(buy($this)->number)->toBe('PCH-KUM-000001')
        ->and(buy($this)->number)->toBe('PCH-KUM-000002')
        ->and(sell($this, '100.00')->number)->toBe('SAL-KUM-000001');
});

it('posts a purchase once however many times the same request arrives', function () {
    $key = (string) Str::uuid();

    $first = buy($this, key: $key);
    $second = buy($this, key: $key);

    expect($second->id)->toBe($first->id)->and(Document::count())->toBe(1)->and(StockLot::count())->toBe(1)
        ->and(GoldLine::count())->toBe(1)->and(Money::fromDb(StockLot::sum('remaining_g')))->toBe(209425);
});

it('can buy from a customer as well as a supplier', function () {
    expect(buy($this, party: $this->customer))->toBeInstanceOf(Document::class);
});

it('refuses to buy from or sell to anything but a customer or supplier', function () {
    expect(fn () => buy($this, party: $this->bank))->toThrow(PostingError::class, 'not a customer or supplier');
    expect(Document::count())->toBe(0)->and(StockLot::count())->toBe(0);
});

it('refuses a switched-off ledger and another branch\'s or business\'s ledger', function () {
    $this->supplier->forceFill(['is_active' => false])->save();

    expect(fn () => buy($this))->toThrow(PostingError::class, 'switched off')
        ->and(fn () => buy($this, party: $this->centralSupplier))->toThrow(PostingError::class, 'another branch')
        ->and(fn () => buy($this, party: $this->otherSupplier))->toThrow(PostingError::class, 'does not belong');
});

it('refuses figures worked out with another business\'s formulas', function () {
    $theirs = FormulaSet::currentFor($this->w->other->id);

    expect(fn () => buy($this, GoldCalculator::raw($theirs, '2094.25', '114.56', '10600')))
        ->toThrow(PostingError::class, 'another business');
});

it('refuses a date in the future', function () {
    expect(fn () => buy($this, date: now()->addDay()->toDateString()))->toThrow(PostingError::class, 'future');
});

it('saves nothing when a purchase fails part way, and gives the number back', function () {
    StockLot::creating(function () {
        throw new RuntimeException('the database failed');
    });

    try {
        buy($this);
    } catch (RuntimeException) {
    }

    expect(Document::withoutGlobalScopes()->count())->toBe(0)
        ->and(GoldLine::withoutGlobalScopes()->count())->toBe(0)
        ->and(JournalLine::withoutGlobalScopes()->count())->toBe(0);

    StockLot::flushEventListeners();
    StockLot::clearBootedModels();

    expect(buy($this)->number)->toBe('PCH-KUM-000001');
});

// ---------------------------------------------------------------------- sales

it('posts a sale: the customer owes the amount, and the gold\'s cost moves to Cost of Sales', function () {
    buy($this);
    $sale = sell($this, '1000.00', '12000');

    $figures = GoldCalculator::refined($this->formula, '1000.00', '22.45', '12000');
    $cost = intdiv(279583700 * 100000, 209425);   // its share of the lot's cost, cut to a pesewa

    expect($sale->type)->toBe(DocumentType::Sale)
        ->and($sale->total)->toBe(Money::decimal($figures->amountCents()))
        ->and(summary($sale))->toBe([
            ['Passion Gold', $figures->amountCents(), 0],
            ['Sales', 0, $figures->amountCents()],
            ['Cost of Sales', $cost, 0],
            ['Gold Stock', 0, $cost],
        ])
        ->and(bal($this->customer))->toBe($figures->amountCents())
        ->and(bal(sys('sales')))->toBe(-$figures->amountCents())
        ->and(bal(sys('cost_of_sales')))->toBe($cost)
        ->and(bal(sys('gold_stock')))->toBe(279583700 - $cost);
});

it('takes the gold out of the lot and records the movement with its cost', function () {
    buy($this);
    $sale = sell($this, '1000.00');
    $cost = intdiv(279583700 * 100000, 209425);

    $lot = StockLot::firstOrFail();
    $movement = StockMovement::where('document_id', $sale->id)->firstOrFail();

    expect($lot->remaining_g)->toBe('1094.25')
        ->and(Money::fromDb($lot->remaining_cost))->toBe(279583700 - $cost)
        ->and($movement->weight_g)->toBe('-1000.00')
        ->and(Money::fromDb($movement->cost))->toBe(-$cost)
        ->and($lot->weight_g)->toBe('2094.25');
});

it('takes gold from the oldest lot first, then the next', function () {
    buy($this, rawFigures($this, '1000.00', '54.70'), date: '2026-01-10');      // lot A, older
    buy($this, rawFigures($this, '2000.00', '109.40'), date: '2026-02-10');     // lot B

    $lotA = StockLot::orderBy('id')->first();
    $lotB = StockLot::orderBy('id')->skip(1)->first();
    $costA = Money::fromDb($lotA->cost);
    $costB = Money::fromDb($lotB->cost);

    sell($this, '1500.00');

    $costOfSales = bal(sys('cost_of_sales'));
    $fromB = intdiv($costB * 50000, 200000);

    expect(lotsLeft())->toBe([[0, 0], [150000, $costB - $fromB]])
        ->and($costOfSales)->toBe($costA + $fromB)
        ->and(StockMovement::where('lot_id', $lotA->id)->where('weight_g', '<', 0)->count())->toBe(1)
        ->and(StockMovement::where('lot_id', $lotB->id)->where('weight_g', '<', 0)->count())->toBe(1);
});

it('uses the lot\'s date, not the order it was entered, to decide which is oldest', function () {
    buy($this, rawFigures($this, '1000.00', '54.70'), date: '2026-03-01');      // entered first but newer
    buy($this, rawFigures($this, '1000.00', '54.70'), date: '2026-01-01');      // entered second but older

    sell($this, '500.00');

    expect(array_column(lotsLeft(), 0))->toBe([50000, 100000]);   // ordered by date: the older lot was cut, the newer is whole
});

it('never loses a pesewa of cost across many sales from one lot', function () {
    buy($this, rawFigures($this, '999.99', '54.71', '10333'));
    $lotCost = Money::fromDb(StockLot::firstOrFail()->cost);

    foreach (['333.33', '333.33', '7.07', '12.34', '100.00'] as $weight) {
        sell($this, $weight);
    }
    $rest = Money::format(Money::fromDb(StockLot::firstOrFail()->remaining_g));
    sell($this, str_replace(',', '', $rest));   // the rest of the lot

    $lot = StockLot::firstOrFail();

    expect(Money::fromDb($lot->remaining_g))->toBe(0)
        ->and(Money::fromDb($lot->remaining_cost))->toBe(0)
        ->and(bal(sys('cost_of_sales')))->toBe($lotCost)
        ->and(bal(sys('gold_stock')))->toBe(0);
});

it('lets a sale take all the stock, and refuses the next one', function () {
    buy($this);
    sell($this, '2094.25');

    expect(lotsLeft())->toBe([[0, 0]])->and(LotAllocator::availableH($this->w->kumasi))->toBe(0);
    expect(fn () => sell($this, '1.00'))->toThrow(PostingError::class, 'Not enough gold in stock');
});

it('refuses a sale for more gold than the branch has, and says how much it has', function () {
    buy($this, rawFigures($this, '1000.00', '54.70'));

    expect(fn () => sell($this, '1000.01'))->toThrow(PostingError::class, 'Kumasi has 1,000.00 g and this sale is 1,000.01 g');

    expect(Document::where('type', 'sale')->count())->toBe(0)->and(lotsLeft()[0][0])->toBe(100000);
});

it('refuses a sale when there is no stock at all', function () {
    expect(fn () => sell($this, '10.00'))->toThrow(PostingError::class, 'Kumasi has 0.00 g');
});

it('cannot sell gold another branch holds', function () {
    // Central buys its own gold, working in Central.
    $this->ctx->set($this->w->adoma, $this->w->central);
    buy($this, branch: $this->w->central, party: $this->centralSupplier);
    $this->ctx->set($this->w->adoma, $this->w->kumasi);

    expect(LotAllocator::availableH($this->w->kumasi))->toBe(0);
    expect(fn () => sell($this, '10.00'))->toThrow(PostingError::class, 'Not enough gold');
    expect(Money::fromDb(StockLot::withoutGlobalScopes()->sum('remaining_g')))->toBe(209425);
});

it('posts a sale once however many times the same request arrives', function () {
    buy($this);
    $key = (string) Str::uuid();

    $first = sell($this, '500.00', key: $key);
    $second = sell($this, '500.00', key: $key);

    expect($second->id)->toBe($first->id)->and(lotsLeft()[0][0])->toBe(209425 - 50000);
});

it('leaves the stock untouched when a sale fails part way', function () {
    buy($this);
    $before = lotsLeft();

    JournalLine::creating(function () {
        throw new RuntimeException('failed');
    });

    try {
        sell($this, '500.00');
    } catch (RuntimeException) {
    }

    expect(lotsLeft())->toBe($before)->and(StockMovement::where('weight_g', '<', 0)->count())->toBe(0);
});

// ------------------------------------------------------------------- reversal

it('reverses a purchase that has not been touched, and the gold goes out of stock', function () {
    $purchase = buy($this);
    $reversal = $this->posting->reverse($purchase, 'Wrong supplier', $this->w->kumasiManager);

    expect(lotsLeft())->toBe([[0, 0]])
        ->and(bal($this->supplier))->toBe(0)
        ->and(bal(sys('gold_stock')))->toBe(0)
        ->and($reversal->number)->toBe('PCH-KUM-000002')
        ->and($purchase->fresh()->status)->toBe(Document::REVERSED)
        ->and(StockMovement::where('document_id', $reversal->id)->firstOrFail()->weight_g)->toBe('-2094.25');
});

it('refuses to reverse a purchase once some of its gold has been sold', function () {
    $purchase = buy($this);
    sell($this, '100.00');
    $before = lotsLeft();

    expect(fn () => $this->posting->reverse($purchase, 'Mistake', $this->w->kumasiManager))
        ->toThrow(PostingError::class, 'already been sold');

    expect($purchase->fresh()->status)->toBe(Document::POSTED)
        ->and(lotsLeft())->toBe($before)
        ->and(Document::where('reverses_id', $purchase->id)->count())->toBe(0)
        ->and(bal($this->supplier))->toBe(-279583700);
});

it('reverses a sale: the gold and its cost go back to the lots', function () {
    buy($this);
    $before = lotsLeft();
    $sale = sell($this, '1000.00');

    $this->posting->reverse($sale, 'Wrong customer', $this->w->kumasiManager);

    expect(lotsLeft())->toBe($before)
        ->and(bal($this->customer))->toBe(0)
        ->and(bal(sys('sales')))->toBe(0)
        ->and(bal(sys('cost_of_sales')))->toBe(0)
        ->and(bal(sys('gold_stock')))->toBe(279583700);
});

it('puts a sale that took gold from two lots back into both', function () {
    buy($this, rawFigures($this, '1000.00', '54.70'), date: '2026-01-10');
    buy($this, rawFigures($this, '2000.00', '109.40'), date: '2026-02-10');
    $before = lotsLeft();

    $sale = sell($this, '1500.00');
    expect(lotsLeft())->not->toBe($before);

    $this->posting->reverse($sale, 'Mistake', $this->w->kumasiManager);

    expect(lotsLeft())->toBe($before);
});

it('allows reversing the purchase once the sale from it has been reversed', function () {
    $purchase = buy($this);
    $sale = sell($this, '100.00');
    $this->posting->reverse($sale, 'Mistake', $this->w->kumasiManager);

    $this->posting->reverse($purchase, 'Mistake', $this->w->kumasiManager);

    expect(lotsLeft())->toBe([[0, 0]])->and(bal(sys('gold_stock')))->toBe(0);
});

it('does not allow a gold document to be reversed twice', function () {
    $purchase = buy($this);
    $this->posting->reverse($purchase, 'Mistake', $this->w->kumasiManager);

    expect(fn () => $this->posting->reverse($purchase, 'Again', $this->w->kumasiManager))->toThrow(PostingError::class, 'already been reversed');
    expect(StockMovement::count())->toBe(2);
});

// -------------------------------------------------------------------- immutable

it('never lets gold figures, lots or movements be edited or deleted', function () {
    $purchase = buy($this);
    $line = GoldLine::firstOrFail();
    $movement = StockMovement::firstOrFail();
    $lot = StockLot::firstOrFail();

    expect(fn () => $line->forceFill(['karat' => '24.00'])->save())->toThrow(TenancyViolation::class)
        ->and(fn () => $line->delete())->toThrow(TenancyViolation::class)
        ->and(fn () => $movement->forceFill(['weight_g' => '1.00'])->save())->toThrow(TenancyViolation::class)
        ->and(fn () => $movement->delete())->toThrow(TenancyViolation::class)
        ->and(fn () => $lot->forceFill(['weight_g' => '1.00'])->save())->toThrow(TenancyViolation::class)
        ->and(fn () => $lot->forceFill(['cost' => '1.00'])->save())->toThrow(TenancyViolation::class)
        ->and(fn () => $lot->delete())->toThrow(TenancyViolation::class);

    expect($purchase->fresh()->total)->toBe('2795837.00');
});

it('never lets a formula version be changed or deleted', function () {
    expect(fn () => $this->formula->forceFill(['karat_base' => 24])->save())->toThrow(TenancyViolation::class)
        ->and(fn () => $this->formula->delete())->toThrow(TenancyViolation::class);
});

// --------------------------------------------------------------------- scoping

it('shows a branch only its own lots, lines and movements', function () {
    buy($this);
    $this->ctx->set($this->w->adoma, $this->w->central);

    expect(StockLot::count())->toBe(0)->and(GoldLine::count())->toBe(0)->and(StockMovement::count())->toBe(0);
});

it('shows nothing when no business is in context', function () {
    buy($this);
    $this->ctx->clear();

    expect(StockLot::count())->toBe(0)->and(GoldLine::count())->toBe(0);
});

it('gives every business its own formulas, starting from the client\'s sheet', function () {
    $mine = FormulaSet::currentFor($this->w->adoma->id);
    $theirs = FormulaSet::currentFor($this->w->other->id);

    expect($mine->id)->not->toBe($theirs->id)
        ->and($mine->version)->toBe(1)
        ->and((string) $mine->grams_per_pound)->toBe('7.75')
        ->and((string) $mine->density_offset)->toBe('10.51')
        ->and((string) $mine->karat_factor)->toBe('52.838')
        ->and($mine->karat_base)->toBe(23);
});

it('builds the formulas for a business that has none yet', function () {
    FormulaSet::withoutGlobalScopes()->where('tenant_id', $this->w->other->id)->delete();

    // Set up from outside any business, as an import or a seeder would. Inside Adoma it is rightly refused.
    $this->ctx->clear();

    expect(FormulaSet::currentFor($this->w->other->id)->version)->toBe(1);
});

it('uses the newest version for new work and keeps old documents on the version they used', function () {
    $old = buy($this);

    $second = FormulaSet::withoutGlobalScopes()->create([
        'tenant_id' => $this->w->adoma->id, 'version' => 2, 'grams_per_pound' => '8.00', 'density_offset' => '10.51',
        'karat_factor' => '52.838', 'karat_base' => 23, 'effective_from' => now()->toDateString(),
    ]);

    expect(FormulaSet::currentFor($this->w->adoma->id)->id)->toBe($second->id);

    $newer = buy($this, GoldCalculator::raw($second, '2094.25', '114.56', '10600'));

    expect(GoldLine::where('document_id', $old->id)->firstOrFail()->formula_set_id)->toBe($this->formula->id)
        ->and(GoldLine::where('document_id', $newer->id)->firstOrFail()->formula_set_id)->toBe($second->id)
        ->and(GoldLine::where('document_id', $newer->id)->firstOrFail()->pounds)->toBe('261.78');
});

// ------------------------------------------------------------------- invariants

/** What must always be true, whatever has been bought, sold and reversed. */
function assertTheBooksAgree(object $t): void
{
    $lots = StockLot::query()->get();
    $costLeft = $lots->sum(fn ($l) => Money::fromDb($l->remaining_cost));
    $weightLeft = $lots->sum(fn ($l) => Money::fromDb($l->remaining_g));

    // The Gold Stock account equals the cost of the gold still in the lots.
    expect(bal(sys('gold_stock')))->toBe($costLeft);

    // Gold in the lots equals every movement added up.
    expect($weightLeft)->toBe(Money::fromDb(StockMovement::sum('weight_g')));

    // Nothing is ever negative, and no lot holds more than it received.
    foreach ($lots as $lot) {
        expect(Money::fromDb($lot->remaining_g))->toBeGreaterThanOrEqual(0)
            ->and(Money::fromDb($lot->remaining_cost))->toBeGreaterThanOrEqual(0)
            ->and(Money::fromDb($lot->remaining_g))->toBeLessThanOrEqual(Money::fromDb($lot->weight_g));
        if (Money::fromDb($lot->remaining_g) === 0) {
            expect(Money::fromDb($lot->remaining_cost))->toBe(0);
        }
    }

    // Every document balances, and so do the books as a whole.
    $debits = JournalLine::sum('debit');
    $credits = JournalLine::sum('credit');
    expect(Money::fromDb($debits))->toBe(Money::fromDb($credits));
}

it('keeps the books in agreement through any mix of purchases, sales and reversals', function () {
    mt_srand(20261014);
    $open = [];

    foreach (range(1, 60) as $step) {
        $roll = mt_rand(1, 10);

        try {
            if ($roll <= 4) {
                $weight = number_format(mt_rand(100, 400000) / 100, 2, '.', '');
                $water = number_format(mt_rand(100, 400000) / 100 * 0.0547, 2, '.', '');
                $open[] = buy($this, GoldCalculator::raw($this->formula, $weight, max($water, '1.00'), (string) mt_rand(9000, 12000)), date: now()->subDays(mt_rand(0, 60))->toDateString());
            } elseif ($roll <= 8) {
                $weight = number_format(mt_rand(1, 150000) / 100, 2, '.', '');
                $open[] = sell($this, $weight, (string) mt_rand(9000, 14000));
            } elseif ($open !== []) {
                $this->posting->reverse(array_splice($open, mt_rand(0, count($open) - 1), 1)[0], 'random', $this->w->kumasiManager);
            }
        } catch (PostingError|App\Domain\Gold\CalculationError) {
            // Refused for a good reason: not enough stock, a lot partly sold, an impossible density.
        }

        assertTheBooksAgree($this);
    }

    expect(Document::count())->toBeGreaterThan(20);
});
