<?php

use App\Domain\Ledgers\ChartOfAccounts;
use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use App\Domain\Tenancy\Branch;
use App\Domain\Tenancy\TenancyViolation;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\World;

beforeEach(function () {
    $this->w = World::build();
    $this->ctx = app(TenantContext::class);
});

/** An office ledger, written without any scope in the way. */
function ledger(World $w, Branch $branch, string $name, LedgerKind $kind = LedgerKind::Customer, bool $active = true): Ledger
{
    $ledger = Ledger::create([
        'tenant_id' => $branch->tenant_id,
        'branch_id' => $branch->id,
        'is_shared' => false,
        'group_id' => ChartOfAccounts::groupFor($branch->tenant_id, $kind)->id,
        'kind' => $kind,
        'name' => $name,
    ]);

    if (! $active) {
        $ledger->forceFill(['is_active' => false])->save();
    }

    return $ledger;
}

function sharedLedger(World $w, string $name = 'Shared Bank'): Ledger
{
    return Ledger::create([
        'tenant_id' => $w->adoma->id,
        'is_shared' => true,
        'group_id' => ChartOfAccounts::groupFor($w->adoma->id, LedgerKind::Bank)->id,
        'kind' => LedgerKind::Bank,
        'name' => $name,
    ]);
}

function newLedger(array $overrides = []): array
{
    return array_merge(['kind' => 'customer', 'name' => 'Ama Gold Traders', 'phone' => '0244000000'], $overrides);
}

function ledgerNames(): array
{
    return Ledger::orderBy('name')->pluck('name')->all();
}

// ------------------------------------------------------------------ visibility

it('shows nothing when no business is in context', function () {
    ledger($this->w, $this->w->kumasi, 'Hidden');

    expect(Ledger::count())->toBe(0);
});

it('shows an office its own ledgers and the shared ones, never another office\'s', function () {
    ledger($this->w, $this->w->kumasi, 'Kumasi Customer');
    ledger($this->w, $this->w->central, 'Central Customer');
    sharedLedger($this->w);

    $this->ctx->set($this->w->adoma, $this->w->kumasi);

    expect(ledgerNames())->toContain('Kumasi Customer', 'Shared Bank', 'Sales', 'Gold Stock', 'Central', 'Kumasi')
        ->not->toContain('Central Customer');
});

it('shows owners and admins everything in their own business when viewing all offices', function () {
    ledger($this->w, $this->w->kumasi, 'Kumasi Customer');
    ledger($this->w, $this->w->central, 'Central Customer');
    ledger($this->w, $this->w->otherBranch, 'Other Co Customer');

    $this->ctx->set($this->w->adoma, null, allBranches: true);

    expect(ledgerNames())->toContain('Kumasi Customer', 'Central Customer')->not->toContain('Other Co Customer', 'Main');
});

it('never shows another business\'s ledgers, even by id', function () {
    $theirs = ledger($this->w, $this->w->otherBranch, 'Other Co Customer');

    $this->ctx->set($this->w->adoma, null, allBranches: true);

    expect(Ledger::find($theirs->id))->toBeNull();
});

// ---------------------------------------------------------- rules when saving

it('refuses an office ledger with no office, and a shared ledger with one', function () {
    $group = ChartOfAccounts::groupFor($this->w->adoma->id, LedgerKind::Customer)->id;

    expect(fn () => Ledger::create(['tenant_id' => $this->w->adoma->id, 'is_shared' => false, 'group_id' => $group, 'kind' => 'customer', 'name' => 'x']))
        ->toThrow(TenancyViolation::class);

    expect(fn () => Ledger::create(['tenant_id' => $this->w->adoma->id, 'branch_id' => $this->w->kumasi->id, 'is_shared' => true, 'group_id' => $group, 'kind' => 'customer', 'name' => 'x']))
        ->toThrow(TenancyViolation::class);
});

it('refuses an office from another business', function () {
    $group = ChartOfAccounts::groupFor($this->w->adoma->id, LedgerKind::Customer)->id;

    Ledger::create(['tenant_id' => $this->w->adoma->id, 'branch_id' => $this->w->otherBranch->id, 'is_shared' => false, 'group_id' => $group, 'kind' => 'customer', 'name' => 'x']);
})->throws(TenancyViolation::class);

it('refuses to write into another office of the same business', function () {
    $this->ctx->set($this->w->adoma, $this->w->kumasi);

    ledger($this->w, $this->w->central, 'x');
})->throws(TenancyViolation::class);

it('never lets a ledger change kind, office, group or business', function (string $what) {
    $ledger = ledger($this->w, $this->w->kumasi, 'Kumasi Customer');

    $ledger->forceFill(match ($what) {
        'kind' => ['kind' => LedgerKind::Supplier],
        'office' => ['branch_id' => $this->w->central->id],
        'shared' => ['is_shared' => true, 'branch_id' => null],
        'business' => ['tenant_id' => $this->w->other->id],
        'system flag' => ['is_system' => true],
    });

    expect(fn () => $ledger->save())->toThrow(TenancyViolation::class);
})->with(['kind', 'office', 'shared', 'business', 'system flag']);

// ------------------------------------------------------------- who can look

it('lets everyone with access to the office open the list', function (string $who) {
    $this->actingAs($this->w->{$who})->get(route('ledgers.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Ledgers/Index'));
})->with(['owner', 'admin', 'kumasiManager', 'kumasiClerk', 'kumasiViewer']);

it('keeps people with no business out', function () {
    $stray = App\Models\User::factory()->create();

    $this->actingAs($stray)->get(route('ledgers.index'))->assertForbidden();
});

it('lists an office\'s ledgers and the shared ones only', function () {
    ledger($this->w, $this->w->kumasi, 'Kumasi Customer');
    ledger($this->w, $this->w->central, 'Central Customer');

    $this->actingAs($this->w->kumasiClerk)->get(route('ledgers.index'))
        ->assertInertia(fn ($page) => $page->where('ledgers.data', function ($rows) {
            $names = collect($rows)->pluck('name');

            return $names->contains('Kumasi Customer') && $names->contains('Sales') && ! $names->contains('Central Customer');
        }));
});

it('lets an owner see every office when viewing all offices', function () {
    ledger($this->w, $this->w->central, 'Central Customer');

    $this->actingAs($this->w->owner)->withSession(['active_branch_id' => 'all'])->get(route('ledgers.index'))
        ->assertInertia(fn ($page) => $page->where('ledgers.data', fn ($rows) => collect($rows)->pluck('name')->contains('Central Customer')));
});

it('shows which office or group each ledger belongs to', function () {
    ledger($this->w, $this->w->kumasi, 'Kumasi Customer');

    $this->actingAs($this->w->kumasiClerk)->get(route('ledgers.index', ['q' => 'Kumasi Customer']))
        ->assertInertia(fn ($page) => $page
            ->where('ledgers.data.0.office', 'Kumasi')
            ->where('ledgers.data.0.group', 'Current Assets / Debtors')
            ->where('ledgers.data.0.kind_label', 'Customer'));
});

it('searches by name or phone, filters by type and hides switched-off ledgers', function () {
    ledger($this->w, $this->w->kumasi, 'Passion Gold');
    ledger($this->w, $this->w->kumasi, 'Black', LedgerKind::Supplier);
    ledger($this->w, $this->w->kumasi, 'Old Customer', LedgerKind::Customer, active: false);
    Ledger::withoutGlobalScopes()->where('name', 'Black')->update(['phone' => '0555123456']);

    $names = fn (array $query) => collect(
        $this->actingAs($this->w->kumasiClerk)->get(route('ledgers.index', $query))->viewData('page')['props']['ledgers']['data']
    )->pluck('name')->all();

    expect($names(['q' => 'passion']))->toBe(['Passion Gold'])
        ->and($names(['q' => '0555']))->toBe(['Black'])
        ->and($names(['kind' => 'supplier']))->toBe(['Black'])
        ->and($names(['q' => 'Old Customer']))->toBe([])
        ->and($names(['q' => 'Old Customer', 'show_off' => 1]))->toBe(['Old Customer'])
        ->and($names(['kind' => 'not-a-kind']))->toContain('Passion Gold', 'Black');
});

it('pages the list 25 at a time', function () {
    foreach (range(1, 30) as $i) {
        ledger($this->w, $this->w->kumasi, sprintf('Customer %02d', $i));
    }

    $this->actingAs($this->w->kumasiClerk)->get(route('ledgers.index', ['kind' => 'customer']))
        ->assertInertia(fn ($page) => $page->has('ledgers.data', 25)->where('ledgers.total', 30)->where('ledgers.last_page', 2));
});

it('does not run a query for every row', function () {
    foreach (range(1, 25) as $i) {
        ledger($this->w, $this->w->kumasi, sprintf('Customer %02d', $i));
    }
    $this->actingAs($this->w->kumasiClerk);

    DB::enableQueryLog();
    $this->get(route('ledgers.index'))->assertOk();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Rows, their groups and offices are loaded together. One query per row would be 25 or more.
    expect($queries)->toBeLessThan(20);
});

// ------------------------------------------------------------------- creating

it('lets a clerk add a customer or supplier to their own office', function (string $kind, string $groupKey) {
    $this->actingAs($this->w->kumasiClerk)->post(route('ledgers.store'), newLedger(['kind' => $kind]))
        ->assertSessionHasNoErrors()->assertRedirect(route('ledgers.index'));

    $ledger = Ledger::withoutGlobalScopes()->where('name', 'Ama Gold Traders')->firstOrFail();

    expect($ledger->tenant_id)->toBe($this->w->adoma->id)
        ->and($ledger->branch_id)->toBe($this->w->kumasi->id)
        ->and($ledger->is_shared)->toBeFalse()
        ->and($ledger->is_system)->toBeFalse()
        ->and($ledger->is_active)->toBeTrue()
        ->and($ledger->group->key)->toBe($groupKey)
        ->and($ledger->phone)->toBe('0244000000');
})->with([['customer', 'debtors'], ['supplier', 'creditors']]);

it('keeps a clerk\'s ledger in their own office whatever they send', function () {
    $this->actingAs($this->w->kumasiClerk)
        ->post(route('ledgers.store'), newLedger(['branch_id' => $this->w->central->id, 'tenant_id' => $this->w->other->id, 'is_system' => true, 'system_key' => 'sales']))
        ->assertSessionHasNoErrors();

    $ledger = Ledger::withoutGlobalScopes()->where('name', 'Ama Gold Traders')->firstOrFail();

    expect($ledger->branch_id)->toBe($this->w->kumasi->id)
        ->and($ledger->tenant_id)->toBe($this->w->adoma->id)
        ->and($ledger->is_system)->toBeFalse()
        ->and($ledger->system_key)->toBeNull();
});

it('keeps clerks from adding banks, cash accounts and expenses', function (string $kind) {
    $this->actingAs($this->w->kumasiClerk)->post(route('ledgers.store'), newLedger(['kind' => $kind]))->assertForbidden();

    expect(Ledger::withoutGlobalScopes()->where('name', 'Ama Gold Traders')->exists())->toBeFalse();
})->with(['bank', 'cash', 'expense']);

it('lets a manager add banks, cash accounts and expenses', function (string $kind, string $groupKey) {
    $this->actingAs($this->w->kumasiManager)->post(route('ledgers.store'), newLedger(['kind' => $kind]))
        ->assertSessionHasNoErrors();

    expect(Ledger::withoutGlobalScopes()->where('name', 'Ama Gold Traders')->firstOrFail()->group->key)->toBe($groupKey);
})->with([['bank', 'cash_bank'], ['cash', 'cash_bank'], ['expense', 'operating_expenses']]);

it('keeps viewers from adding anything', function () {
    $this->actingAs($this->w->kumasiViewer);

    $this->get(route('ledgers.create'))->assertForbidden();
    $this->post(route('ledgers.store'), newLedger())->assertForbidden();
});

it('never lets anyone add a system or office account by hand', function (string $kind) {
    $this->actingAs($this->w->owner)->withSession(['active_branch_id' => $this->w->kumasi->id])
        ->post(route('ledgers.store'), newLedger(['kind' => $kind]))->assertForbidden();
})->with(['account', 'branch', 'nonsense']);

it('keeps clerks and managers from sharing a ledger with every office', function (string $who) {
    $this->actingAs($this->w->{$who})->post(route('ledgers.store'), newLedger(['is_shared' => true]))->assertForbidden();
})->with(['kumasiClerk', 'kumasiManager', 'centralManager']);

it('lets an admin add a shared ledger that every office can see', function () {
    $this->actingAs($this->w->admin)->post(route('ledgers.store'), newLedger(['kind' => 'bank', 'name' => 'Group Bank', 'is_shared' => true]))
        ->assertSessionHasNoErrors();

    $ledger = Ledger::withoutGlobalScopes()->where('name', 'Group Bank')->firstOrFail();
    expect($ledger->is_shared)->toBeTrue()->and($ledger->branch_id)->toBeNull();

    $this->ctx->set($this->w->adoma, $this->w->central);
    expect(ledgerNames())->toContain('Group Bank');
});

it('makes an admin viewing all offices say which office the ledger is for', function () {
    $this->actingAs($this->w->owner)->withSession(['active_branch_id' => 'all']);

    $this->post(route('ledgers.store'), newLedger())->assertSessionHasErrors('branch_id');

    $this->post(route('ledgers.store'), newLedger(['branch_id' => $this->w->central->id]))->assertSessionHasNoErrors();
    expect(Ledger::withoutGlobalScopes()->where('name', 'Ama Gold Traders')->value('branch_id'))->toBe($this->w->central->id);
});

it('does not accept an office from another business', function () {
    $this->actingAs($this->w->owner)->withSession(['active_branch_id' => 'all'])
        ->post(route('ledgers.store'), newLedger(['branch_id' => $this->w->otherBranch->id]))
        ->assertSessionHasErrors('branch_id');
});

it('needs a name and a known type', function () {
    $this->actingAs($this->w->kumasiClerk);

    $this->post(route('ledgers.store'), newLedger(['name' => '']))->assertSessionHasErrors('name');
    $this->post(route('ledgers.store'), newLedger(['name' => str_repeat('a', 121)]))->assertSessionHasErrors('name');
    $this->post(route('ledgers.store'), newLedger(['kind' => '']))->assertForbidden();
});

it('does not allow two ledgers with the same name in one office, but does across offices', function () {
    ledger($this->w, $this->w->kumasi, 'Passion Gold');
    ledger($this->w, $this->w->central, 'Black');

    $this->actingAs($this->w->kumasiClerk)->post(route('ledgers.store'), newLedger(['name' => 'Passion Gold']))
        ->assertSessionHasErrors('name');

    $this->actingAs($this->w->kumasiClerk)->post(route('ledgers.store'), newLedger(['name' => 'Black']))
        ->assertSessionHasNoErrors();
});

it('shows the add form only the kinds a person may add', function () {
    $kinds = fn ($user) => collect(
        $this->actingAs($user)->get(route('ledgers.create'))->viewData('page')['props']['kinds']
    )->pluck('value')->all();

    expect($kinds($this->w->kumasiClerk))->toBe(['customer', 'supplier'])
        ->and($kinds($this->w->kumasiManager))->toBe(['customer', 'supplier', 'bank', 'cash', 'expense']);
});

// -------------------------------------------------------------------- editing

it('lets a clerk rename a customer and change its phone, but not its type', function () {
    $ledger = ledger($this->w, $this->w->kumasi, 'Passion Gold');

    $this->actingAs($this->w->kumasiClerk)->put(route('ledgers.update', $ledger->id), [
        'name' => 'Passion Gold Ltd', 'phone' => '0200000000', 'kind' => 'supplier', 'branch_id' => $this->w->central->id, 'is_shared' => true,
    ])->assertSessionHasNoErrors()->assertRedirect(route('ledgers.index'));

    $ledger->refresh();

    expect($ledger->name)->toBe('Passion Gold Ltd')
        ->and($ledger->phone)->toBe('0200000000')
        ->and($ledger->kind)->toBe(LedgerKind::Customer)
        ->and($ledger->branch_id)->toBe($this->w->kumasi->id)
        ->and($ledger->is_shared)->toBeFalse();
});

it('opens the edit page for a ledger a person may change', function () {
    $ledger = ledger($this->w, $this->w->kumasi, 'Passion Gold');

    $this->actingAs($this->w->kumasiClerk)->get(route('ledgers.edit', $ledger->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Ledgers/Edit')->where('ledger.name', 'Passion Gold')->where('ledger.office', 'Kumasi'));
});

it('lets a person keep a ledger\'s own name when saving', function () {
    $ledger = ledger($this->w, $this->w->kumasi, 'Passion Gold');

    $this->actingAs($this->w->kumasiClerk)->put(route('ledgers.update', $ledger->id), ['name' => 'Passion Gold', 'phone' => '0200000000'])
        ->assertSessionHasNoErrors();
});

it('keeps clerks from editing banks, cash accounts and expenses, but not managers', function () {
    $bank = ledger($this->w, $this->w->kumasi, 'Kumasi Bank', LedgerKind::Bank);

    $this->actingAs($this->w->kumasiClerk);
    $this->get(route('ledgers.edit', $bank->id))->assertForbidden();
    $this->put(route('ledgers.update', $bank->id), ['name' => 'Renamed'])->assertForbidden();

    $this->actingAs($this->w->kumasiManager)->put(route('ledgers.update', $bank->id), ['name' => 'Renamed'])
        ->assertSessionHasNoErrors();
    expect($bank->fresh()->name)->toBe('Renamed');
});

it('keeps viewers from editing', function () {
    $ledger = ledger($this->w, $this->w->kumasi, 'Passion Gold');

    $this->actingAs($this->w->kumasiViewer)->put(route('ledgers.update', $ledger->id), ['name' => 'x'])->assertForbidden();
});

it('hides another office\'s ledgers completely', function () {
    $central = ledger($this->w, $this->w->central, 'Central Customer');
    $this->actingAs($this->w->kumasiManager);

    $this->get(route('ledgers.edit', $central->id))->assertNotFound();
    $this->put(route('ledgers.update', $central->id), ['name' => 'x'])->assertNotFound();
    $this->patch(route('ledgers.toggle', $central->id))->assertNotFound();

    expect($central->fresh()->name)->toBe('Central Customer');
});

it('hides another business\'s ledgers completely', function () {
    $theirs = ledger($this->w, $this->w->otherBranch, 'Other Co Customer');

    $this->actingAs($this->w->owner)->withSession(['active_branch_id' => 'all']);

    $this->get(route('ledgers.edit', $theirs->id))->assertNotFound();
    $this->patch(route('ledgers.toggle', $theirs->id))->assertNotFound();
});

it('lets only owners and admins change a shared ledger', function () {
    $shared = sharedLedger($this->w);

    $this->actingAs($this->w->kumasiManager)->put(route('ledgers.update', $shared->id), ['name' => 'x'])->assertForbidden();
    $this->actingAs($this->w->admin)->put(route('ledgers.update', $shared->id), ['name' => 'Renamed Shared'])->assertSessionHasNoErrors();

    expect($shared->fresh()->name)->toBe('Renamed Shared');
});

it('never lets anyone edit or switch off a system or office account', function (string $name) {
    $account = Ledger::withoutGlobalScopes()->where('tenant_id', $this->w->adoma->id)->where('name', $name)->firstOrFail();

    $this->actingAs($this->w->owner)->withSession(['active_branch_id' => 'all']);

    $this->get(route('ledgers.edit', $account->id))->assertForbidden();
    $this->put(route('ledgers.update', $account->id), ['name' => 'Hacked'])->assertForbidden();
    $this->patch(route('ledgers.toggle', $account->id))->assertForbidden();

    expect($account->fresh()->name)->toBe($name)->and($account->fresh()->is_active)->toBeTrue();
})->with(['Sales', 'Gold Stock', 'Cost of Sales', 'Kumasi', 'Central']);

// ------------------------------------------------------------ switching on and off

it('lets a manager switch a ledger off and on, and hides it from the default list', function () {
    $ledger = ledger($this->w, $this->w->kumasi, 'Passion Gold');
    $this->actingAs($this->w->kumasiManager);

    $this->patch(route('ledgers.toggle', $ledger->id))->assertRedirect();
    expect($ledger->fresh()->is_active)->toBeFalse();

    $rows = fn (array $q) => collect($this->get(route('ledgers.index', $q))->viewData('page')['props']['ledgers']['data'])->pluck('name');
    expect($rows([])->contains('Passion Gold'))->toBeFalse()
        ->and($rows(['show_off' => 1])->contains('Passion Gold'))->toBeTrue();

    $this->patch(route('ledgers.toggle', $ledger->id));
    expect($ledger->fresh()->is_active)->toBeTrue();
});

it('keeps clerks and viewers from switching ledgers off', function (string $who) {
    $ledger = ledger($this->w, $this->w->kumasi, 'Passion Gold');

    $this->actingAs($this->w->{$who})->patch(route('ledgers.toggle', $ledger->id))->assertForbidden();

    expect($ledger->fresh()->is_active)->toBeTrue();
})->with(['kumasiClerk', 'kumasiViewer']);

it('does not offer actions people cannot use', function () {
    ledger($this->w, $this->w->kumasi, 'Passion Gold');
    ledger($this->w, $this->w->kumasi, 'Kumasi Bank', LedgerKind::Bank);

    $rows = fn ($user) => collect(
        $this->actingAs($user)->get(route('ledgers.index'))->viewData('page')['props']['ledgers']['data']
    )->keyBy('name');

    $clerk = $rows($this->w->kumasiClerk);
    expect($clerk['Passion Gold']['can_edit'])->toBeTrue()
        ->and($clerk['Passion Gold']['can_toggle'])->toBeFalse()
        ->and($clerk['Kumasi Bank']['can_edit'])->toBeFalse()
        ->and($clerk['Sales']['can_edit'])->toBeFalse();

    $manager = $rows($this->w->kumasiManager);
    expect($manager['Kumasi Bank']['can_edit'])->toBeTrue()->and($manager['Kumasi Bank']['can_toggle'])->toBeTrue();

    $viewer = $rows($this->w->kumasiViewer);
    expect($viewer['Passion Gold']['can_edit'])->toBeFalse();
});
