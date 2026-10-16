<?php

use App\Domain\Documents\DocumentType;
use App\Domain\Documents\PostingService;
use App\Domain\Ledgers\ChartOfAccounts;
use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use App\Domain\Tenancy\Branch;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Tests\Support\World;

beforeEach(function () {
    $this->w = World::build();
});

function dashLedger(Branch $branch, string $name, LedgerKind $kind = LedgerKind::Customer, bool $active = true): Ledger
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

function dashStats(array $props): array
{
    return collect($props['stats'])->pluck('count', 'label')->all();
}

function dashProps($response): array
{
    return $response->viewData('page')['props'];
}

it('sends people who are not signed in to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

it('shows a branch the number of ledgers it has, active ones only', function () {
    dashLedger($this->w->kumasi, 'Passion Gold');
    dashLedger($this->w->kumasi, 'Gold Mart');
    dashLedger($this->w->kumasi, 'Old Customer', active: false);
    dashLedger($this->w->kumasi, 'Black', LedgerKind::Supplier);

    $stats = dashStats(dashProps($this->actingAs($this->w->kumasiClerk)->get(route('dashboard'))->assertOk()));

    expect($stats)->toBe(['Customers' => 2, 'Suppliers' => 1, 'Banks' => 0, 'Cash accounts' => 0]);
});

it('keeps another branch\'s figures out of the count', function () {
    dashLedger($this->w->kumasi, 'Passion Gold');
    dashLedger($this->w->central, 'Central One');
    dashLedger($this->w->central, 'Central Two');
    dashLedger($this->w->otherBranch, 'Other Co Customer');

    expect(dashStats(dashProps($this->actingAs($this->w->kumasiClerk)->get(route('dashboard'))))['Customers'])->toBe(1)
        ->and(dashStats(dashProps($this->actingAs($this->w->centralManager)->get(route('dashboard'))))['Customers'])->toBe(2);
});

it('counts shared ledgers for every branch', function () {
    Ledger::create([
        'tenant_id' => $this->w->adoma->id, 'is_shared' => true,
        'group_id' => ChartOfAccounts::groupFor($this->w->adoma->id, LedgerKind::Bank)->id, 'kind' => LedgerKind::Bank, 'name' => 'Group Bank',
    ]);

    expect(dashStats(dashProps($this->actingAs($this->w->kumasiClerk)->get(route('dashboard'))))['Banks'])->toBe(1)
        ->and(dashStats(dashProps($this->actingAs($this->w->centralManager)->get(route('dashboard'))))['Banks'])->toBe(1);
});

it('adds up every branch for an owner viewing all branches, but never another business', function () {
    dashLedger($this->w->kumasi, 'Passion Gold');
    dashLedger($this->w->central, 'Central One');
    dashLedger($this->w->otherBranch, 'Other Co Customer');

    $props = dashProps($this->actingAs($this->w->owner)->withSession(['active_branch_id' => 'all'])->get(route('dashboard')));

    expect(dashStats($props)['Customers'])->toBe(2)->and($props['where'])->toBe('All branches');
});

it('names the branch being looked at', function () {
    expect(dashProps($this->actingAs($this->w->kumasiClerk)->get(route('dashboard')))['where'])->toBe('Kumasi')
        ->and(dashProps($this->actingAs($this->w->centralManager)->get(route('dashboard')))['where'])->toBe('Central');
});

it('links each figure to the matching list', function () {
    $stats = collect(dashProps($this->actingAs($this->w->kumasiClerk)->get(route('dashboard')))['stats']);

    expect($stats->firstWhere('label', 'Customers')['href'])->toBe(route('ledgers.index', ['kind' => 'customer']))
        ->and($stats->firstWhere('label', 'Cash accounts')['href'])->toBe(route('ledgers.index', ['kind' => 'cash']));
});

function dashPost(object $t, Branch $branch, string $partyName, int $cents, string $date): void
{
    $party = dashLedger($branch, $partyName);
    $cash = dashLedger($branch, "Cash {$partyName}", LedgerKind::Cash);

    $context = app(TenantContext::class);
    $context->set($t->w->adoma, $branch);

    try {
        app(PostingService::class)->postCash(
            DocumentType::Receipt, $branch, $date, $party, $cash, $cents, null, null,
            $branch->is($t->w->kumasi) ? $t->w->kumasiClerk : $t->w->centralManager,
        );
    } finally {
        $context->clear();
    }
}

it('lists the eight latest transactions, last recorded first, from the branch in view only', function () {
    foreach (range(1, 9) as $i) {
        dashPost($this, $this->w->kumasi, "Customer $i", $i * 100, "2026-03-0$i");
    }
    dashPost($this, $this->w->central, 'Central Only', 999, '2026-03-09');

    $recent = collect(dashProps($this->actingAs($this->w->kumasiClerk)->get(route('dashboard')))['recent']);

    expect($recent->pluck('party')->all())->toBe(['Customer 9', 'Customer 8', 'Customer 7', 'Customer 6', 'Customer 5', 'Customer 4', 'Customer 3', 'Customer 2'])
        ->and($recent->first()['office'])->toBe('Kumasi')
        ->and($recent->first()['type_label'])->toBe('Receipt')
        // A receipt moves money, not gold, and the row says when it was recorded.
        ->and($recent->first()['weight'])->toBeNull()
        ->and($recent->first()['recorded_at'])->not->toBeNull();
});

it('puts the most recently recorded first, even when it is dated earlier', function () {
    dashPost($this, $this->w->kumasi, 'Dated today', 100, '2026-03-09');
    dashPost($this, $this->w->kumasi, 'Backdated', 200, '2026-03-01');

    $recent = collect(dashProps($this->actingAs($this->w->kumasiClerk)->get(route('dashboard')))['recent']);

    expect($recent->pluck('party')->all())->toBe(['Backdated', 'Dated today'])
        ->and($recent->first()['date'])->toBe('2026-03-01');
});

it('shows the add button only to people who can add a ledger', function (string $who, bool $expected) {
    expect(dashProps($this->actingAs($this->w->{$who})->get(route('dashboard')))['canCreate'])->toBe($expected);
})->with([
    ['owner', true],
    ['admin', true],
    ['kumasiManager', true],
    ['kumasiClerk', true],
    ['kumasiViewer', false],
]);

it('no longer sends the audit trail to the home page', function () {
    dashLedger($this->w->kumasi, 'Passion Gold');

    expect(dashProps($this->actingAs($this->w->owner)->get(route('dashboard')))['activity'] ?? null)->toBeNull();
});

it('never shows another business\'s transactions on the home page', function () {
    dashPost($this, $this->w->kumasi, 'Passion Gold', 5000, '2026-03-01');

    expect(dashProps($this->actingAs($this->w->otherAdmin)->get(route('dashboard')))['recent'])->toBe([]);
});

it('works for a business with nothing in it yet', function () {
    $stats = dashProps($this->actingAs($this->w->otherAdmin)->get(route('dashboard'))->assertOk());

    expect(dashStats($stats))->toBe(['Customers' => 0, 'Suppliers' => 0, 'Banks' => 0, 'Cash accounts' => 0])
        ->and($stats['recent'])->toBe([]);
});

it('works for a signed-in person who belongs to no business, and shows them nothing', function () {
    $stray = User::factory()->create();

    $props = dashProps($this->actingAs($stray)->get(route('dashboard'))->assertOk());

    expect(dashStats($props))->toBe(['Customers' => 0, 'Suppliers' => 0, 'Banks' => 0, 'Cash accounts' => 0])
        ->and($props['recent'])->toBe([])
        ->and($props['canCreate'])->toBeFalse();
});

it('does not run a query for every ledger or log line', function () {
    foreach (range(1, 30) as $i) {
        dashLedger($this->w->kumasi, "Customer $i");
    }
    $this->actingAs($this->w->owner);

    Illuminate\Support\Facades\DB::enableQueryLog();
    $this->get(route('dashboard'))->assertOk();
    $queries = count(Illuminate\Support\Facades\DB::getQueryLog());
    Illuminate\Support\Facades\DB::disableQueryLog();

    expect($queries)->toBeLessThan(25);
});
