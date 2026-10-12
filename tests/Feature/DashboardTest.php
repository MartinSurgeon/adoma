<?php

use App\Domain\Audit\AuditLog;
use App\Domain\Ledgers\ChartOfAccounts;
use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use App\Domain\Tenancy\Branch;
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

it('shows an office the number of ledgers it has, active ones only', function () {
    dashLedger($this->w->kumasi, 'Passion Gold');
    dashLedger($this->w->kumasi, 'Gold Mart');
    dashLedger($this->w->kumasi, 'Old Customer', active: false);
    dashLedger($this->w->kumasi, 'Black', LedgerKind::Supplier);

    $stats = dashStats(dashProps($this->actingAs($this->w->kumasiClerk)->get(route('dashboard'))->assertOk()));

    expect($stats)->toBe(['Customers' => 2, 'Suppliers' => 1, 'Banks' => 0, 'Cash accounts' => 0]);
});

it('keeps another office\'s figures out of the count', function () {
    dashLedger($this->w->kumasi, 'Passion Gold');
    dashLedger($this->w->central, 'Central One');
    dashLedger($this->w->central, 'Central Two');
    dashLedger($this->w->otherBranch, 'Other Co Customer');

    expect(dashStats(dashProps($this->actingAs($this->w->kumasiClerk)->get(route('dashboard'))))['Customers'])->toBe(1)
        ->and(dashStats(dashProps($this->actingAs($this->w->centralManager)->get(route('dashboard'))))['Customers'])->toBe(2);
});

it('counts shared ledgers for every office', function () {
    Ledger::create([
        'tenant_id' => $this->w->adoma->id, 'is_shared' => true,
        'group_id' => ChartOfAccounts::groupFor($this->w->adoma->id, LedgerKind::Bank)->id, 'kind' => LedgerKind::Bank, 'name' => 'Group Bank',
    ]);

    expect(dashStats(dashProps($this->actingAs($this->w->kumasiClerk)->get(route('dashboard'))))['Banks'])->toBe(1)
        ->and(dashStats(dashProps($this->actingAs($this->w->centralManager)->get(route('dashboard'))))['Banks'])->toBe(1);
});

it('adds up every office for an owner viewing all offices, but never another business', function () {
    dashLedger($this->w->kumasi, 'Passion Gold');
    dashLedger($this->w->central, 'Central One');
    dashLedger($this->w->otherBranch, 'Other Co Customer');

    $props = dashProps($this->actingAs($this->w->owner)->withSession(['active_branch_id' => 'all'])->get(route('dashboard')));

    expect(dashStats($props)['Customers'])->toBe(2)->and($props['where'])->toBe('All offices');
});

it('names the office being looked at', function () {
    expect(dashProps($this->actingAs($this->w->kumasiClerk)->get(route('dashboard')))['where'])->toBe('Kumasi')
        ->and(dashProps($this->actingAs($this->w->centralManager)->get(route('dashboard')))['where'])->toBe('Central');
});

it('links each figure to the matching list', function () {
    $stats = collect(dashProps($this->actingAs($this->w->kumasiClerk)->get(route('dashboard')))['stats']);

    expect($stats->firstWhere('label', 'Customers')['href'])->toBe(route('ledgers.index', ['kind' => 'customer']))
        ->and($stats->firstWhere('label', 'Cash accounts')['href'])->toBe(route('ledgers.index', ['kind' => 'cash']));
});

it('lists the five newest ledgers, newest first, without system accounts or other offices', function () {
    foreach (range(1, 7) as $i) {
        dashLedger($this->w->kumasi, "Customer $i");
    }
    dashLedger($this->w->central, 'Central Only');

    $recent = collect(dashProps($this->actingAs($this->w->kumasiClerk)->get(route('dashboard')))['recent']);

    expect($recent->pluck('name')->all())->toBe(['Customer 7', 'Customer 6', 'Customer 5', 'Customer 4', 'Customer 3'])
        ->and($recent->first()['office'])->toBe('Kumasi')
        ->and($recent->first()['kind_label'])->toBe('Customer');
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

it('shows recent activity to owners and admins only', function () {
    dashLedger($this->w->kumasi, 'Passion Gold');

    foreach (['kumasiClerk', 'kumasiManager', 'kumasiViewer', 'centralManager'] as $who) {
        expect(dashProps($this->actingAs($this->w->{$who})->get(route('dashboard')))['activity'])->toBeNull();
    }

    foreach (['owner', 'admin'] as $who) {
        $activity = dashProps($this->actingAs($this->w->{$who})->get(route('dashboard')))['activity'];
        expect($activity)->not->toBeNull()->and(collect($activity)->pluck('description')->first())->toBe('added ledger “Passion Gold”');
    }
});

it('limits recent activity to five entries from the owner\'s own business', function () {
    foreach (range(1, 8) as $i) {
        dashLedger($this->w->kumasi, "Customer $i");
    }
    dashLedger($this->w->otherBranch, 'Other Co Secret');

    $text = json_encode(dashProps($this->actingAs($this->w->owner)->get(route('dashboard')))['activity']);

    expect(count(dashProps($this->actingAs($this->w->owner)->get(route('dashboard')))['activity']))->toBe(5)
        ->and($text)->not->toContain('Other Co Secret');
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
        ->and($props['canCreate'])->toBeFalse()
        ->and($props['activity'])->toBeNull();
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
