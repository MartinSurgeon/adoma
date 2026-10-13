<?php

use App\Domain\Documents\DocumentType;
use App\Domain\Documents\LedgerBalances;
use App\Domain\Documents\PostingService;
use App\Domain\Ledgers\ChartOfAccounts;
use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use App\Domain\Tenancy\Branch;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\World;

beforeEach(function () {
    $this->w = World::build();

    $this->customer = stLedger($this->w->kumasi, 'Passion Gold', LedgerKind::Customer);
    $this->supplier = stLedger($this->w->kumasi, 'Black', LedgerKind::Supplier);
    $this->cash = stLedger($this->w->kumasi, 'Cash Kumasi', LedgerKind::Cash);
    $this->centralCustomer = stLedger($this->w->central, 'Central Customer', LedgerKind::Customer);
    $this->centralCash = stLedger($this->w->central, 'Cash HQ', LedgerKind::Cash);
});

function stLedger(Branch $branch, string $name, LedgerKind $kind, bool $active = true): Ledger
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

/** Post a document for either office, as one of that office's own people. */
function stPost(object $t, Branch $branch, string $type, Ledger $party, Ledger $cash, int $cents, ?string $date = null)
{
    $context = app(TenantContext::class);
    $context->set($t->w->adoma, $branch);

    try {
        return app(PostingService::class)->postCash(
            DocumentType::from($type), $branch, $date ?? now()->toDateString(), $party, $cash, $cents, null, null,
            $branch->is($t->w->kumasi) ? $t->w->kumasiClerk : $t->w->centralManager,
        );
    } finally {
        $context->clear();
    }
}

function stProps($response): array
{
    return $response->viewData('page')['props'];
}

// ------------------------------------------------------------ ledger search

it('finds ledgers by name, with their balance, for the type-ahead', function () {
    stPost($this, $this->w->kumasi, 'payment', $this->customer, $this->cash, 120000);

    $found = $this->actingAs($this->w->kumasiClerk)->getJson(route('ledgers.search', ['q' => 'passion']))->assertOk()->json();

    expect($found)->toHaveCount(1)
        ->and($found[0]['name'])->toBe('Passion Gold')
        ->and($found[0]['kind_label'])->toBe('Customer')
        ->and($found[0]['balance'])->toBe('GH₵ 1,200.00 · owes you');
});

it('only offers this office\'s ledgers and the shared ones', function () {
    $names = collect($this->actingAs($this->w->kumasiClerk)->getJson(route('ledgers.search'))->json())->pluck('name');

    expect($names)->toContain('Passion Gold', 'Black', 'Cash Kumasi', 'Sales')->not->toContain('Central Customer', 'Cash HQ');
});

it('filters by kind, leaves out switched-off ledgers and never goes past 20', function () {
    stLedger($this->w->kumasi, 'Closed Customer', LedgerKind::Customer, active: false);
    foreach (range(1, 25) as $i) {
        stLedger($this->w->kumasi, sprintf('Extra %02d', $i), LedgerKind::Supplier);
    }

    $this->actingAs($this->w->kumasiClerk);

    $cash = collect($this->getJson(route('ledgers.search', ['kinds' => 'cash,bank']))->json())->pluck('name')->all();
    $all = $this->getJson(route('ledgers.search'))->json();

    expect($cash)->toBe(['Cash Kumasi'])
        ->and($all)->toHaveCount(20)
        ->and(collect($this->getJson(route('ledgers.search', ['q' => 'closed']))->json()))->toHaveCount(0)
        ->and(collect($this->getJson(route('ledgers.search', ['kinds' => 'nonsense']))->json())->count())->toBeGreaterThan(0);
});

it('lets viewers search but not people without a business', function () {
    $this->actingAs($this->w->kumasiViewer)->getJson(route('ledgers.search'))->assertOk();
    $this->actingAs(User::factory()->create())->getJson(route('ledgers.search'))->assertForbidden();
});

it('needs a signed-in person', function () {
    $this->getJson(route('ledgers.search'))->assertUnauthorized();
});

// --------------------------------------------------------------- balances

it('shows each ledger\'s balance in plain words on the ledger list', function () {
    stPost($this, $this->w->kumasi, 'payment', $this->customer, $this->cash, 120000);   // they owe us 1,200
    stPost($this, $this->w->kumasi, 'receipt', $this->supplier, $this->cash, 30000);     // we owe them 300
    stPost($this, $this->w->kumasi, 'receipt', $this->customer, $this->cash, 120000);    // the customer settles

    $rows = collect(stProps($this->actingAs($this->w->kumasiClerk)->get(route('ledgers.index')))['ledgers']['data'])->keyBy('name');

    expect($rows['Passion Gold']['balance'])->toBe('Settled')
        ->and($rows['Black']['balance'])->toBe('GH₵ 300.00 · you owe them')
        ->and($rows['Cash Kumasi']['balance'])->toBe('GH₵ 300.00 · available');
});

it('does not run a query for every ledger on the list', function () {
    foreach (range(1, 25) as $i) {
        stLedger($this->w->kumasi, sprintf('Customer %02d', $i), LedgerKind::Customer);
    }
    $this->actingAs($this->w->kumasiClerk);

    DB::enableQueryLog();
    $this->get(route('ledgers.index'))->assertOk();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThan(20);
});

it('describes each kind of balance the way people think of it', function (string $kind, int $cents, string $words) {
    expect(LedgerBalances::describe(LedgerKind::from($kind), $cents))->toBe($words);
})->with([
    ['customer', 50000, 'GH₵ 500.00 · owes you'],
    ['customer', -50000, 'GH₵ 500.00 · you owe them'],
    ['supplier', -50000, 'GH₵ 500.00 · you owe them'],
    ['supplier', 50000, 'GH₵ 500.00 · they owe you'],
    ['cash', 50000, 'GH₵ 500.00 · available'],
    ['bank', -50000, 'GH₵ 500.00 · overdrawn'],
    ['expense', 50000, 'GH₵ 500.00 · spent'],
    ['branch', 50000, 'GH₵ 500.00 · owes you'],
    ['account', -50000, 'GH₵ 500.00 Cr'],
    ['customer', 0, 'Settled'],
]);

// -------------------------------------------------------------- statement

it('shows every entry with a running balance, and totals that agree', function () {
    stPost($this, $this->w->kumasi, 'payment', $this->customer, $this->cash, 100000, '2026-02-01');
    stPost($this, $this->w->kumasi, 'receipt', $this->customer, $this->cash, 30000, '2026-02-10');
    stPost($this, $this->w->kumasi, 'receipt', $this->customer, $this->cash, 20050, '2026-02-20');

    $props = stProps($this->actingAs($this->w->kumasiClerk)->get(route('ledgers.statement', $this->customer))->assertOk());

    expect(collect($props['rows'])->map(fn ($r) => [$r['date'], $r['debit'], $r['credit'], $r['balance']])->all())->toBe([
        ['2026-02-01', '1,000.00', '', '1,000.00 Dr'],
        ['2026-02-10', '', '300.00', '700.00 Dr'],
        ['2026-02-20', '', '200.50', '499.50 Dr'],
    ])
        ->and($props['opening'])->toBe('0.00')
        ->and($props['totals'])->toBe(['debit' => '1,000.00', 'credit' => '500.50'])
        ->and($props['closing'])->toBe('499.50 Dr')
        ->and($props['closing_words'])->toBe('GH₵ 499.50 · owes you')
        ->and($props['ledger']['name'])->toBe('Passion Gold')
        ->and($props['covers'])->toBe('Kumasi');
});

it('links each entry to its document', function () {
    $document = stPost($this, $this->w->kumasi, 'receipt', $this->customer, $this->cash, 5000);

    $row = stProps($this->actingAs($this->w->kumasiClerk)->get(route('ledgers.statement', $this->customer)))['rows'][0];

    expect($row['document_id'])->toBe($document->id)->and($row['number'])->toBe($document->number)->and($row['type_label'])->toBe('Receipt');
});

it('starts from the balance brought forward when a period is chosen', function () {
    stPost($this, $this->w->kumasi, 'payment', $this->customer, $this->cash, 100000, '2026-01-05');
    stPost($this, $this->w->kumasi, 'receipt', $this->customer, $this->cash, 30000, '2026-02-10');
    stPost($this, $this->w->kumasi, 'receipt', $this->customer, $this->cash, 10000, '2026-03-15');

    $props = stProps($this->actingAs($this->w->kumasiClerk)->get(route('ledgers.statement', [$this->customer, 'from' => '2026-02-01', 'to' => '2026-02-28'])));

    expect($props['opening'])->toBe('1,000.00 Dr')
        ->and(collect($props['rows'])->pluck('balance')->all())->toBe(['700.00 Dr'])
        ->and($props['closing'])->toBe('700.00 Dr')
        ->and($props['totals'])->toBe(['debit' => '0.00', 'credit' => '300.00']);
});

it('ignores a date it cannot read', function () {
    stPost($this, $this->w->kumasi, 'receipt', $this->customer, $this->cash, 5000);

    $props = stProps($this->actingAs($this->w->kumasiClerk)->get(route('ledgers.statement', [$this->customer, 'from' => 'whenever'])));

    expect($props['filters'])->toBe(['from' => '', 'to' => ''])->and($props['rows'])->toHaveCount(1);
});

it('shows a credit balance with Cr', function () {
    stPost($this, $this->w->kumasi, 'receipt', $this->customer, $this->cash, 5000);

    $props = stProps($this->actingAs($this->w->kumasiClerk)->get(route('ledgers.statement', $this->customer)));

    expect($props['closing'])->toBe('50.00 Cr')->and($props['closing_words'])->toBe('GH₵ 50.00 · you owe them');
});

it('shows an empty ledger as empty', function () {
    $props = stProps($this->actingAs($this->w->kumasiClerk)->get(route('ledgers.statement', $this->supplier)));

    expect($props['rows'])->toBe([])->and($props['closing'])->toBe('0.00')->and($props['truncated'])->toBeFalse();
});

it('keeps another office\'s ledger and entries out of reach', function () {
    stPost($this, $this->w->central, 'payment', $this->centralCustomer, $this->centralCash, 100000);

    $this->actingAs($this->w->kumasiClerk)->get(route('ledgers.statement', $this->centralCustomer))->assertNotFound();
});

it('keeps another business out of reach', function () {
    $this->actingAs($this->w->otherAdmin)->get(route('ledgers.statement', $this->customer))->assertNotFound();
});

it('lets viewers read a statement', function () {
    $this->actingAs($this->w->kumasiViewer)->get(route('ledgers.statement', $this->customer))->assertOk();
});

it('shows an office only its own entries on a shared ledger', function () {
    $shared = Ledger::create([
        'tenant_id' => $this->w->adoma->id, 'is_shared' => true,
        'group_id' => ChartOfAccounts::groupFor($this->w->adoma->id, LedgerKind::Bank)->id, 'kind' => LedgerKind::Bank, 'name' => 'Group Bank',
    ]);
    stPost($this, $this->w->kumasi, 'receipt', $this->customer, $shared, 100000);
    stPost($this, $this->w->central, 'receipt', $this->centralCustomer, $shared, 40000);

    $kumasi = stProps($this->actingAs($this->w->kumasiClerk)->get(route('ledgers.statement', $shared)));
    $central = stProps($this->actingAs($this->w->centralManager)->get(route('ledgers.statement', $shared)));
    $everything = stProps($this->actingAs($this->w->owner)->withSession(['active_branch_id' => 'all'])->get(route('ledgers.statement', $shared)));

    expect($kumasi['closing'])->toBe('1,000.00 Dr')
        ->and($central['closing'])->toBe('400.00 Dr')
        ->and($everything['closing'])->toBe('1,400.00 Dr')
        ->and($everything['covers'])->toBe('All offices');
});

it('caps a very long statement and says so', function () {
    $document = stPost($this, $this->w->kumasi, 'receipt', $this->customer, $this->cash, 100);

    $lines = [];
    foreach (range(1, 1100) as $i) {
        $lines[] = [
            'tenant_id' => $this->w->adoma->id, 'branch_id' => $this->w->kumasi->id, 'document_id' => $document->id,
            'ledger_id' => $this->customer->id, 'date' => '2026-01-01', 'debit' => '1.00', 'credit' => '0.00', 'created_at' => now(),
        ];
    }
    foreach (array_chunk($lines, 200) as $chunk) {
        DB::table('journal_lines')->insert($chunk);
    }

    $props = stProps($this->actingAs($this->w->kumasiClerk)->get(route('ledgers.statement', $this->customer)));

    expect($props['rows'])->toHaveCount(1000)->and($props['truncated'])->toBeTrue()->and($props['limit'])->toBe(1000);
});

// -------------------------------------------------------------- the dashboard

it('shows the record buttons to people who can record', function (string $who, bool $expected) {
    expect(stProps($this->actingAs($this->w->{$who})->get(route('dashboard')))['canRecord'])->toBe($expected);
})->with([
    ['owner', true],
    ['admin', true],
    ['kumasiManager', true],
    ['kumasiClerk', true],
    ['kumasiViewer', false],
]);

it('tells the navigation whether the person can record in this office', function () {
    $tenancy = fn ($user, $session = []) => stProps($this->actingAs($user)->withSession($session)->get(route('dashboard')))['tenancy'];

    expect($tenancy($this->w->kumasiClerk)['can_record'])->toBeTrue()
        ->and($tenancy($this->w->kumasiViewer)['can_record'])->toBeFalse()
        ->and($tenancy($this->w->owner, ['active_branch_id' => $this->w->kumasi->id])['can_record'])->toBeTrue()
        ->and($tenancy($this->w->owner, ['active_branch_id' => 'all'])['can_record'])->toBeFalse();
});
