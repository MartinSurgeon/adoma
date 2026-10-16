<?php

use App\Domain\Documents\Document;
use App\Domain\Ledgers\ChartOfAccounts;
use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use App\Domain\Stock\StockLot;
use App\Domain\Tenancy\Branch;
use Illuminate\Support\Str;
use Tests\Support\World;

// The home page's branch cards and gold in transit, the latest transactions with their weight, and the Transactions
// page showing the same rows. Kumasi buys 2,094.25 g of raw gold; sends 1,000 g to Central at 12,000 a pound.

beforeEach(function () {
    $this->w = World::build();

    $this->supplier = hoLedger($this->w->kumasi, 'Bright', LedgerKind::Supplier);
    $this->centralSupplier = hoLedger($this->w->central, 'Central Seller', LedgerKind::Supplier);
});

function hoLedger(Branch $branch, string $name, LedgerKind $kind): Ledger
{
    return Ledger::create([
        'tenant_id' => $branch->tenant_id, 'branch_id' => $branch->id, 'is_shared' => false,
        'group_id' => ChartOfAccounts::groupFor($branch->tenant_id, $kind)->id, 'kind' => $kind, 'name' => $name,
    ]);
}

function hoProps($response): array
{
    return $response->assertOk()->viewData('page')['props'];
}

function hoBuy(object $t, array $more = []): Document
{
    $t->actingAs($t->w->kumasiManager)->post(route('purchases.store'), array_merge([
        'date' => now()->toDateString(), 'ledger_id' => $t->supplier->id, 'form' => 'raw', 'weight' => '2094.25',
        'water_weight' => '114.56', 'karat' => '', 'price' => '10600', 'note' => '', 'idempotency_key' => (string) Str::uuid(),
    ], $more))->assertSessionHasNoErrors();

    return Document::withoutGlobalScopes()->where('type', 'purchase')->latest('id')->firstOrFail();
}

/** Kumasi sends gold to Central, and it waits there to be accepted. */
function hoSend(object $t, string $weight = '1000.00'): Document
{
    $t->actingAs($t->w->kumasiManager)->post(route('transfers.store'), [
        'date' => now()->toDateString(), 'to_branch_id' => $t->w->central->id, 'weight' => $weight, 'price' => '12000',
        'note' => '', 'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();

    return Document::withoutGlobalScopes()->where('type', 'transfer')->latest('id')->firstOrFail();
}

function hoOwnerHome(object $t): array
{
    $props = hoProps($t->actingAs($t->w->owner)->withSession(['active_branch_id' => 'all'])->get(route('dashboard')));
    $t->flushSession();

    return $props;
}

// ------------------------------------------------------------------ the branch cards

it('shows the owner each branch with its gold, raw and refined, and what is on its way to it', function () {
    hoBuy($this);
    hoBuy($this, ['form' => 'refined', 'weight' => '300.00', 'water_weight' => '', 'karat' => '24']);
    hoSend($this);

    $branches = collect(hoOwnerHome($this)['branches'])->keyBy('name');

    expect($branches->keys()->sort()->values()->all())->toBe(['Central', 'Kumasi'])
        ->and($branches['Kumasi'])->toMatchArray(['grams' => '1,394.25', 'raw' => '1,094.25', 'refined' => '300.00', 'raw_share' => 78, 'arriving' => null])
        ->and($branches['Kumasi']['updated_at'])->not->toBeNull()
        ->and($branches['Kumasi']['href'])->toBe(route('stock.index', ['branch' => $this->w->kumasi->id]))
        // Central holds nothing yet: the 1,000 g counts as its stock only once it is accepted.
        ->and($branches['Central'])->toMatchArray(['grams' => '0.00', 'raw_share' => null, 'arriving' => ['count' => 1, 'grams' => '1,000.00']]);
});

it('marks the head office', function () {
    $flags = collect(hoOwnerHome($this)['branches'])->pluck('is_head_office', 'name');

    expect($flags->filter()->count())->toBeLessThanOrEqual(1)
        ->and($flags->keys()->all())->toContain('Kumasi', 'Central');
});

it('shows the branch cards only to an owner or admin looking at every branch', function () {
    expect(hoProps($this->actingAs($this->w->kumasiManager)->get(route('dashboard')))['branches'])->toBeNull()
        ->and(hoProps($this->actingAs($this->w->kumasiClerk)->get(route('dashboard')))['branches'])->toBeNull();
});

// ------------------------------------------------------------------ gold in transit

it('shows the owner every transfer still on the road, with what both branches know about it', function () {
    hoBuy($this);
    $out = hoSend($this);

    $transit = hoOwnerHome($this)['transit'];

    expect($transit)->toHaveCount(1)
        ->and($transit[0])->toMatchArray([
            'id' => $out->id, 'number' => $out->number, 'from' => 'Kumasi', 'to' => 'Central', 'direction' => null,
            'lots' => 1, 'weight' => '1,000.00', 'form' => 'Raw', 'value' => 'GH₵ 1,511,334.00',
            'sent_by' => $this->w->kumasiManager->name, 'href' => route('documents.show', $out->id),
        ])
        // The sending branch's cost is its own business.
        ->and($transit[0])->not->toHaveKey('cost');
});

it('shows a branch what it sent and what is coming to it, leading each to the right page', function () {
    hoBuy($this);
    $out = hoSend($this);

    $sender = hoProps($this->actingAs($this->w->kumasiClerk)->get(route('dashboard')))['transit'];
    $receiver = hoProps($this->actingAs($this->w->centralManager)->get(route('dashboard')))['transit'];

    expect($sender)->toHaveCount(1)
        ->and($sender[0])->toMatchArray(['direction' => 'out', 'to' => 'Central', 'href' => route('documents.show', $out->id)])
        ->and($receiver)->toHaveCount(1)
        // Coming in: to the page where a manager accepts it.
        ->and($receiver[0])->toMatchArray(['direction' => 'in', 'from' => 'Kumasi', 'href' => route('transfers.incoming')]);
});

it('gives a clerk no link to accept gold, because only a manager can', function () {
    // Central sends to Kumasi, so Kumasi's clerk sees it coming.
    $this->actingAs($this->w->centralManager)->post(route('purchases.store'), [
        'date' => now()->toDateString(), 'ledger_id' => $this->centralSupplier->id, 'form' => 'refined', 'weight' => '500.00',
        'water_weight' => '', 'karat' => '24', 'price' => '10600', 'note' => '', 'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->w->centralManager)->post(route('transfers.store'), [
        'date' => now()->toDateString(), 'to_branch_id' => $this->w->kumasi->id, 'weight' => '100.00', 'price' => '12000',
        'note' => '', 'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();

    $clerk = hoProps($this->actingAs($this->w->kumasiClerk)->get(route('dashboard')))['transit'];
    $manager = hoProps($this->actingAs($this->w->kumasiManager)->get(route('dashboard')))['transit'];

    expect($clerk[0])->toMatchArray(['direction' => 'in', 'form' => 'Refined', 'href' => null])
        ->and($manager[0]['href'])->toBe(route('transfers.incoming'));
});

it('clears a transfer from the road once it is accepted', function () {
    hoBuy($this);
    $out = hoSend($this);
    $this->actingAs($this->w->centralManager)->post(route('transfers.accept', $out->id))->assertSessionHasNoErrors();

    $home = hoOwnerHome($this);

    expect($home['transit'])->toBe([])
        ->and(collect($home['branches'])->firstWhere('name', 'Central'))->toMatchArray(['grams' => '1,000.00', 'arriving' => null]);
});

it('never shows another business the transfers or branches', function () {
    hoBuy($this);
    hoSend($this);

    $other = hoProps($this->actingAs($this->w->otherAdmin)->withSession(['active_branch_id' => 'all'])->get(route('dashboard')));

    expect($other['transit'])->toBe([])
        ->and(collect($other['branches'])->pluck('name')->all())->not->toContain('Kumasi', 'Central');
});

// ------------------------------------------------------------------ the rows

it('gives each latest transaction its weight, and names the branch on a transfer', function () {
    $purchase = hoBuy($this);
    $out = hoSend($this);
    $this->actingAs($this->w->centralManager)->post(route('transfers.accept', $out->id))->assertSessionHasNoErrors();

    $kumasi = collect(hoProps($this->actingAs($this->w->kumasiClerk)->get(route('dashboard')))['recent'])->keyBy('number');
    $central = collect(hoProps($this->actingAs($this->w->centralManager)->get(route('dashboard')))['recent']);

    expect($kumasi[$purchase->number])->toMatchArray(['type_label' => 'Purchase', 'party' => 'Bright', 'weight' => '2,094.25'])
        ->and($kumasi[$out->number])->toMatchArray(['type_label' => 'Transfer out', 'party' => 'To Central', 'weight' => '1,000.00', 'waiting' => false])
        ->and($central->first())->toMatchArray(['type_label' => 'Transfer in', 'party' => 'From Kumasi', 'weight' => '1,000.00']);
});

it('shows the same rows on the Transactions page, with the branch column only across branches', function () {
    hoBuy($this);
    $out = hoSend($this);

    $clerk = hoProps($this->actingAs($this->w->kumasiClerk)->get(route('documents.index')));
    $rows = collect($clerk['documents']['data'])->keyBy('number');
    $owner = hoProps($this->actingAs($this->w->owner)->withSession(['active_branch_id' => 'all'])->get(route('documents.index')));

    expect($rows[$out->number])->toMatchArray(['party' => 'To Central', 'weight' => '1,000.00', 'waiting' => true])
        ->and($clerk['showOffice'])->toBeFalse()
        ->and($owner['showOffice'])->toBeTrue();
});

it('weighs a reversal by the gold it put back', function () {
    $purchase = hoBuy($this);
    $this->actingAs($this->w->kumasiManager)->post(route('documents.reverse', $purchase->id), ['reason' => 'Typed twice'])->assertSessionHasNoErrors();

    $reversal = collect(hoProps($this->actingAs($this->w->kumasiManager)->get(route('dashboard')))['recent'])->first();

    expect($reversal)->toMatchArray(['is_reversal' => true, 'weight' => '2,094.25']);
});

// ------------------------------------------------------------------ the stock page, one branch at a time

it('lets the owner see one branch\'s lots from its card', function () {
    hoBuy($this);
    $this->actingAs($this->w->centralManager)->post(route('purchases.store'), [
        'date' => now()->toDateString(), 'ledger_id' => $this->centralSupplier->id, 'form' => 'refined', 'weight' => '500.00',
        'water_weight' => '', 'karat' => '24', 'price' => '10600', 'note' => '', 'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();

    $page = hoProps($this->actingAs($this->w->owner)->withSession(['active_branch_id' => 'all'])->get(route('stock.index', ['branch' => $this->w->kumasi->id])));

    expect(collect($page['lots']['data'])->pluck('office')->unique()->values()->all())->toBe(['Kumasi'])
        ->and($page['onlyBranch'])->toBe(['id' => $this->w->kumasi->id, 'name' => 'Kumasi'])
        ->and($page['where'])->toBe('Kumasi')
        ->and($page['totals']['grams'])->toBe('2,094.25');
});

it('ignores a branch filter for a branch of another business, or for someone working in one branch', function () {
    hoBuy($this);

    $foreign = hoProps($this->actingAs($this->w->owner)->withSession(['active_branch_id' => 'all'])->get(route('stock.index', ['branch' => $this->w->otherBranch->id])));
    $this->flushSession();
    $clerk = hoProps($this->actingAs($this->w->kumasiClerk)->get(route('stock.index', ['branch' => $this->w->central->id])));

    expect($foreign['onlyBranch'])->toBeNull()->and($foreign['totals']['grams'])->toBe('2,094.25')
        ->and($clerk['onlyBranch'])->toBeNull()->and($clerk['totals']['grams'])->toBe('2,094.25')
        ->and(StockLot::withoutGlobalScopes()->count())->toBe(1);
});
