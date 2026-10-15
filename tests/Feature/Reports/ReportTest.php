<?php

use App\Domain\Documents\Document;
use App\Domain\Documents\JournalLine;
use App\Domain\Ledgers\ChartOfAccounts;
use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use App\Domain\Support\Money;
use App\Domain\Tenancy\Branch;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Support\World;

// The reports the client asked for: purchases, sales, one person's supplies, and opening and closing balances.
//
// The books used here, all in Kumasi:
//   5 Mar  bought 2,094.25 g from Bright at 10,600   GH₵ 2,795,837
//  20 Mar  bought 2,081.01 g from Black  at 10,600   GH₵ 2,778,144
//  25 Mar  sold   1,000.00 g to Passion Gold at 12,000   GH₵ 1,511,334
//   2 Apr  bought   599.15 g from Bright at 10,600   GH₵   805,129

beforeEach(function () {
    $this->w = World::build();

    $this->bright = rpLedger($this->w->kumasi, 'Bright', LedgerKind::Supplier);
    $this->black = rpLedger($this->w->kumasi, 'Black', LedgerKind::Supplier);
    $this->buyer = rpLedger($this->w->kumasi, 'Passion Gold', LedgerKind::Customer);
    $this->bank = rpLedger($this->w->kumasi, 'Kumasi Bank', LedgerKind::Bank);
    $this->centralBuyer = rpLedger($this->w->central, 'Central Buyer', LedgerKind::Customer);

    $this->p1 = rpBuy($this, $this->bright, '2026-03-05', '2094.25', '114.56');
    $this->p2 = rpBuy($this, $this->black, '2026-03-20', '2081.01', '113.78');
    $this->s1 = rpSell($this, '2026-03-25');
    $this->p3 = rpBuy($this, $this->bright, '2026-04-02', '599.15', '32.61');
});

function rpLedger(Branch $branch, string $name, LedgerKind $kind): Ledger
{
    return Ledger::create([
        'tenant_id' => $branch->tenant_id, 'branch_id' => $branch->id, 'is_shared' => false,
        'group_id' => ChartOfAccounts::groupFor($branch->tenant_id, $kind)->id, 'kind' => $kind, 'name' => $name,
    ]);
}

function rpBuy(object $t, Ledger $supplier, string $date, string $weight, string $water): Document
{
    $t->actingAs($t->w->kumasiManager)->post(route('purchases.store'), [
        'date' => $date, 'ledger_id' => $supplier->id, 'form' => 'raw', 'weight' => $weight, 'water_weight' => $water,
        'karat' => '', 'price' => '10600', 'note' => '', 'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();

    return Document::withoutGlobalScopes()->where('type', 'purchase')->latest('id')->firstOrFail();
}

function rpSell(object $t, string $date): Document
{
    $t->actingAs($t->w->kumasiManager)->post(route('sales.store'), [
        'date' => $date, 'ledger_id' => $t->buyer->id, 'form' => 'raw', 'weight' => '1000.00', 'water_weight' => '54.70',
        'karat' => '', 'price' => '12000', 'note' => '', 'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();

    return Document::withoutGlobalScopes()->where('type', 'sale')->latest('id')->firstOrFail();
}

function rpProps($response): array
{
    return $response->viewData('page')['props'];
}

function rpMarch(array $more = []): array
{
    return ['from' => '2026-03-01', 'to' => '2026-03-31'] + $more;
}

function rpRows(array $props, int $table = 0): array
{
    return $props['tables'][$table]['rows'];
}

// -------------------------------------------------------------------------- the purchases report

it('lists the purchases of the period, oldest first, with the figures people look for', function () {
    $props = rpProps($this->actingAs($this->w->kumasiClerk)->get(route('reports.purchases', rpMarch()))->assertOk());

    expect($props['title'])->toBe('Purchases')->and($props['covers'])->toBe('Kumasi')
        ->and($props['period'])->toMatchArray(['from' => '2026-03-01', 'to' => '2026-03-31', 'label' => '1 Mar 2026 to 31 Mar 2026'])
        ->and(collect($props['tables'][0]['columns'])->pluck('label')->all())
        ->toBe(['Date', 'Number', 'Supplier', 'Form', 'Weight (g)', 'Karat', 'Price per pound', 'Amount (GH₵)'])
        ->and(rpRows($props))->toBe([
            ['5 Mar 2026', $this->p1->number, 'Bright', 'Raw', '2,094.25', '22.45', '10,600.00', '2,795,837.00'],
            ['20 Mar 2026', $this->p2->number, 'Black', 'Raw', '2,081.01', '22.45', '10,600.00', '2,778,144.00'],
        ])
        ->and($props['tables'][0]['totals'])->toBe(['Total', '', '', '', '4,175.26', '', '', '5,573,981.00'])
        ->and(collect($props['stats'])->pluck('value', 'label')->all())
        ->toBe(['Purchases' => '2', 'Gold bought' => '4,175.26', 'Total amount' => 'GH₵ 5,573,981.00']);
});

it('includes both the first and the last day of the period, and nothing outside it', function () {
    $day = fn (string $from, string $to) => collect(rpRows(rpProps($this->actingAs($this->w->kumasiClerk)->get(route('reports.purchases', ['from' => $from, 'to' => $to]))->assertOk())))->pluck(1)->all();

    expect($day('2026-03-05', '2026-03-05'))->toBe([$this->p1->number])
        ->and($day('2026-03-20', '2026-03-20'))->toBe([$this->p2->number])
        ->and($day('2026-03-06', '2026-03-19'))->toBe([])
        ->and($day('2026-03-05', '2026-04-02'))->toBe([$this->p1->number, $this->p2->number, $this->p3->number]);
});

it('puts dates chosen the wrong way round the right way round, and ignores ones it cannot read', function () {
    $swapped = rpProps($this->actingAs($this->w->kumasiClerk)->get(route('reports.purchases', ['from' => '2026-03-31', 'to' => '2026-03-01']))->assertOk());
    $junk = rpProps($this->actingAs($this->w->kumasiClerk)->get(route('reports.purchases', ['from' => 'last tuesday', 'to' => '31/03/2026']))->assertOk());

    expect($swapped['period'])->toMatchArray(['from' => '2026-03-01', 'to' => '2026-03-31'])
        ->and(rpRows($swapped))->toHaveCount(2)
        // Nothing usable chosen: the month so far.
        ->and($junk['period']['from'])->toBe(now()->startOfMonth()->toDateString())
        ->and($junk['period']['to'])->toBe(now()->toDateString());
});

it('defaults to the month so far', function () {
    $props = rpProps($this->actingAs($this->w->kumasiClerk)->get(route('reports.purchases'))->assertOk());

    expect($props['period']['from'])->toBe(now()->startOfMonth()->toDateString())->and($props['period']['to'])->toBe(now()->toDateString());
});

it('leaves out a purchase that was reversed, and the reversal, so the list shows what stands', function () {
    $this->actingAs($this->w->kumasiManager)->post(route('documents.reverse', $this->p2->id), ['reason' => 'Wrong supplier'])->assertSessionHasNoErrors();

    $props = rpProps($this->actingAs($this->w->kumasiClerk)->get(route('reports.purchases', rpMarch()))->assertOk());

    expect(collect(rpRows($props))->pluck(1)->all())->toBe([$this->p1->number])
        ->and($props['tables'][0]['totals'][7])->toBe('2,795,837.00')
        ->and($props['stats'][0]['value'])->toBe('1');
});

// ---------------------------------------------------------------------------- the sales report

it('lists the sales of the period, and gives managers the cost and the profit', function () {
    $props = rpProps($this->actingAs($this->w->kumasiManager)->get(route('reports.sales', rpMarch()))->assertOk());

    $costLedger = Ledger::withoutGlobalScopes()->where('tenant_id', $this->w->adoma->id)->where('system_key', 'cost_of_sales')->firstOrFail();
    $cost = Money::fromDb(JournalLine::withoutGlobalScopes()->where('document_id', $this->s1->id)->where('ledger_id', $costLedger->id)->sum('debit'));
    $amount = Money::fromDb($this->s1->total);

    expect($amount)->toBe(151133400)->and($cost)->toBeGreaterThan(0)
        ->and(collect($props['tables'][0]['columns'])->pluck('label')->all())
        ->toBe(['Date', 'Number', 'Customer', 'Form', 'Weight (g)', 'Karat', 'Amount (GH₵)', 'Cost (GH₵)', 'Profit (GH₵)'])
        ->and(rpRows($props))->toBe([[
            '25 Mar 2026', $this->s1->number, 'Passion Gold', 'Raw', '1,000.00', '22.45', '1,511,334.00',
            Money::format($cost), Money::format($amount - $cost),
        ]])
        ->and($props['tables'][0]['totals'])->toBe(['Total', '', '', '', '1,000.00', '', '1,511,334.00', Money::format($cost), Money::format($amount - $cost)])
        ->and(collect($props['stats'])->pluck('label')->all())->toBe(['Sales', 'Gold sold', 'Total amount', 'Profit']);
});

it('keeps the cost and the profit from clerks and viewers, who see the sales without them', function (string $who) {
    $props = rpProps($this->actingAs($this->w->{$who})->get(route('reports.sales', rpMarch()))->assertOk());

    expect(collect($props['tables'][0]['columns'])->pluck('label')->all())->toBe(['Date', 'Number', 'Customer', 'Form', 'Weight (g)', 'Karat', 'Amount (GH₵)'])
        ->and(rpRows($props)[0])->toHaveCount(7)
        ->and(collect($props['stats'])->pluck('label')->all())->toBe(['Sales', 'Gold sold', 'Total amount'])
        ->and(json_encode($props))->not->toContain('Profit');
})->with(['kumasiClerk', 'kumasiViewer']);

it('says in a note that gold sent to another branch is not counted as a sale here', function () {
    expect(rpProps($this->actingAs($this->w->kumasiManager)->get(route('reports.sales', rpMarch()))->assertOk())['notes'])
        ->toBe(['Gold sent to another branch is a transfer, and is not counted here.']);
});

// ------------------------------------------------------------------------- the supplier report

it('shows what one supplier supplied in the period, with what they are owed now', function () {
    $props = rpProps($this->actingAs($this->w->kumasiClerk)->get(route('reports.supplier', ['ledger_id' => $this->bright->id, 'from' => '2026-03-01', 'to' => '2026-04-30']))->assertOk());

    expect($props['person'])->toBe(['id' => $this->bright->id, 'name' => 'Bright', 'kind_label' => 'Supplier'])
        ->and($props['tables'][0]['title'])->toBe('Gold supplied by Bright')
        ->and(collect(rpRows($props))->pluck(1)->all())->toBe([$this->p1->number, $this->p3->number])
        ->and($props['tables'][0]['totals'])->toBe(['Total', '', '', '2,693.40', '', '', '3,600,966.00'])
        // Black's gold is not in Bright's report.
        ->and(json_encode($props))->not->toContain($this->p2->number)
        ->and($props['notes'][0])->toBe('Bright now: GH₵ 3,600,966.00 · you owe them.');
});

it('respects the period for one supplier too', function () {
    $props = rpProps($this->actingAs($this->w->kumasiClerk)->get(route('reports.supplier', ['ledger_id' => $this->bright->id] + rpMarch()))->assertOk());

    expect(collect(rpRows($props))->pluck(1)->all())->toBe([$this->p1->number]);
});

it('asks who it is for when no one is chosen, and will not report on a ledger it cannot see or that is not a supplier or customer', function (?int $ledger) {
    $query = $ledger === null ? [] : ['ledger_id' => $ledger];

    $props = rpProps($this->actingAs($this->w->kumasiClerk)->get(route('reports.supplier', $query))->assertOk());

    expect($props['person'])->toBeNull()->and($props['tables'])->toBe([])->and($props['stats'])->toBe([]);
})->with([
    'nobody chosen' => [null],
    'no such ledger' => [999999],
]);

it('treats another branch\'s ledger, and a bank, as nobody', function () {
    $other = rpProps($this->actingAs($this->w->kumasiClerk)->get(route('reports.supplier', ['ledger_id' => $this->centralBuyer->id]))->assertOk());
    $bank = rpProps($this->actingAs($this->w->kumasiClerk)->get(route('reports.supplier', ['ledger_id' => $this->bank->id]))->assertOk());

    expect($other['person'])->toBeNull()->and($bank['person'])->toBeNull();
});

// ------------------------------------------------------------------------- the balances summary

it('gives each account its opening balance, what moved, and its closing balance', function () {
    $props = rpProps($this->actingAs($this->w->kumasiManager)->get(route('reports.summary', ['from' => '2026-04-01', 'to' => '2026-04-30']))->assertOk());

    $rows = collect(rpRows($props))->filter(fn ($row) => ! isset($row['heading']))->keyBy(0);

    // Bright was owed 2,795,837 at the start, is owed 805,129 more from April's purchase, and so 3,600,966 at the end.
    expect($rows['Bright'])->toBe(['Bright', '2,795,837.00 Cr', '0.00', '805,129.00', '3,600,966.00 Cr'])
        // Black had no dealings in April, but is still owed what it was.
        ->and($rows['Black'])->toBe(['Black', '2,778,144.00 Cr', '0.00', '0.00', '2,778,144.00 Cr'])
        // Passion Gold owed the sale at the start, and nothing moved.
        ->and($rows['Passion Gold'])->toBe(['Passion Gold', '1,511,334.00 Dr', '0.00', '0.00', '1,511,334.00 Dr']);
});

it('groups the accounts by kind, and its debits equal its credits, as books must', function () {
    $props = rpProps($this->actingAs($this->w->kumasiManager)->get(route('reports.summary', rpMarch()))->assertOk());

    $headings = collect(rpRows($props))->filter(fn ($row) => isset($row['heading']))->pluck('heading')->all();
    $totals = $props['tables'][0]['totals'];

    expect($headings)->toBe(['Customers', 'Suppliers', 'Business accounts'])
        ->and($totals[2])->toBe($totals[3]);
});

it('shows the gold in stock at the start, what came in and went out, and at the end', function () {
    $props = rpProps($this->actingAs($this->w->kumasiManager)->get(route('reports.summary', ['from' => '2026-04-01', 'to' => '2026-04-30']))->assertOk());

    // At the start: 2,094.25 + 2,081.01 bought, 1,000.00 sold = 3,175.26. April adds 599.15.
    expect($props['tables'][1]['title'])->toBe('Gold in stock')
        ->and($props['tables'][1]['rows'])->toBe([
            ['Opening', '3,175.26'], ['Purchases', '599.15'], ['Received from other branches', '0.00'], ['Sales', '0.00'], ['Sent to other branches', '0.00'],
        ])
        ->and($props['tables'][1]['totals'])->toBe(['Closing', '3,774.41']);

    $march = rpProps($this->actingAs($this->w->kumasiManager)->get(route('reports.summary', rpMarch()))->assertOk());

    expect($march['tables'][1]['rows'][0])->toBe(['Opening', '0.00'])
        ->and($march['tables'][1]['rows'][1])->toBe(['Purchases', '4,175.26'])
        ->and($march['tables'][1]['rows'][3])->toBe(['Sales', '-1,000.00'])
        ->and($march['tables'][1]['totals'])->toBe(['Closing', '3,175.26']);
});

it('counts a reversal in the summary, because the books do', function () {
    $this->actingAs($this->w->kumasiManager)->post(route('documents.reverse', $this->p2->id), ['reason' => 'Wrong supplier'])->assertSessionHasNoErrors();

    $props = rpProps($this->actingAs($this->w->kumasiManager)->get(route('reports.summary', ['from' => '2026-01-01', 'to' => '2026-12-31']))->assertOk());
    $black = collect(rpRows($props))->filter(fn ($row) => ! isset($row['heading']))->firstWhere(0, 'Black');

    // Bought and reversed: Black is owed nothing, and the year's movement shows both sides.
    expect($black)->toBe(['Black', '0.00', '2,778,144.00', '2,778,144.00', '0.00']);
});

// ------------------------------------------------------------------ whose books a report reads

it('shows a branch only its own books, never another branch\'s or another business\'s', function () {
    $central = rpProps($this->actingAs($this->w->centralManager)->get(route('reports.purchases', ['from' => '2026-01-01', 'to' => '2026-12-31']))->assertOk());
    $other = rpProps($this->actingAs($this->w->otherAdmin)->get(route('reports.purchases', ['from' => '2026-01-01', 'to' => '2026-12-31']))->assertOk());

    expect(rpRows($central))->toBe([])->and($central['stats'][0]['value'])->toBe('0')
        ->and(rpRows($other))->toBe([])->and($other['stats'][0]['value'])->toBe('0');
});

it('lets an owner viewing every branch see them all, with the branch named on each row', function () {
    $props = rpProps($this->actingAs($this->w->owner)->withSession(['active_branch_id' => 'all'])->get(route('reports.purchases', rpMarch()))->assertOk());

    expect($props['covers'])->toBe('All branches')
        ->and(collect($props['tables'][0]['columns'])->pluck('label')->all())->toBe(['Date', 'Number', 'Supplier', 'Branch', 'Form', 'Weight (g)', 'Karat', 'Price per pound', 'Amount (GH₵)'])
        ->and(rpRows($props)[0][3])->toBe('Kumasi');
});

it('lets a viewer read the reports, and keeps people with no branch out', function () {
    $this->actingAs($this->w->kumasiViewer)->get(route('reports.index'))->assertOk();
    $this->actingAs($this->w->kumasiViewer)->get(route('reports.summary'))->assertOk();

    $stray = User::factory()->create();
    foreach (['reports.index', 'reports.purchases', 'reports.sales', 'reports.supplier', 'reports.summary'] as $name) {
        $this->actingAs($stray)->get(route($name))->assertForbidden();
    }

    auth()->logout();
    $this->get(route('reports.purchases'))->assertRedirect(route('login'));
});

it('lists the reports on the reports page', function () {
    $props = rpProps($this->actingAs($this->w->kumasiClerk)->get(route('reports.index'))->assertOk());

    expect($props['covers'])->toBe('Kumasi');
});

// ---------------------------------------------------------------------- the spreadsheet download

it('downloads a report as a spreadsheet file, with plain numbers a spreadsheet can add up', function () {
    $response = $this->actingAs($this->w->kumasiClerk)->get(route('reports.purchases', rpMarch(['export' => 'csv'])))->assertOk();

    $csv = $response->streamedContent();

    expect($response->headers->get('content-type'))->toStartWith('text/csv')
        ->and($response->headers->get('content-disposition'))->toContain('purchases-2026-03-01-to-2026-03-31.csv')
        // Opens in Excel with the cedi sign intact.
        ->and(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeTrue()
        ->and($csv)->toContain('Purchases')->toContain('Kumasi, 1 Mar 2026 to 31 Mar 2026')
        ->toContain('Date,Number,Supplier,Form,"Weight (g)",Karat,"Price per pound","Amount (GH₵)"')
        ->toContain('"5 Mar 2026",'.$this->p1->number.',Bright,Raw,2094.25,22.45,10600.00,2795837.00')
        ->toContain('Total,,,,4175.26,,,5573981.00');
});

it('downloads the sales without the cost for someone who may not see it', function () {
    $clerk = $this->actingAs($this->w->kumasiClerk)->get(route('reports.sales', rpMarch(['export' => 'csv'])))->assertOk()->streamedContent();
    $manager = $this->actingAs($this->w->kumasiManager)->get(route('reports.sales', rpMarch(['export' => 'csv'])))->assertOk()->streamedContent();

    expect($clerk)->not->toContain('Cost')->not->toContain('Profit')
        ->and($manager)->toContain('Cost')->toContain('Profit');
});

it('downloads the summary with both tables', function () {
    $csv = $this->actingAs($this->w->kumasiManager)->get(route('reports.summary', rpMarch(['export' => 'csv'])))->assertOk()->streamedContent();

    expect($csv)->toContain('Balances')->toContain('Customers')->toContain('Bright')->toContain('Gold in stock')->toContain('Closing,3175.26');
});

it('does not download an empty supplier report when no one is chosen', function () {
    // Nothing to write: it shows the page that asks who.
    $this->actingAs($this->w->kumasiClerk)->get(route('reports.supplier', ['export' => 'csv']))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Reports/Show')->where('person', null));
});
