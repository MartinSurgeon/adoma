<?php

use App\Domain\Documents\Document;
use App\Domain\Ledgers\ChartOfAccounts;
use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use Illuminate\Support\Str;
use Tests\Support\World;

// The home page opens with the gold in stock, kept apart by kind (raw and refined) and shown under one total,
// and it changes as soon as a purchase or a sale is saved.

beforeEach(function () {
    $this->w = World::build();

    foreach (['supplier' => LedgerKind::Supplier, 'customer' => LedgerKind::Customer] as $name => $kind) {
        $this->{$name} = Ledger::create([
            'tenant_id' => $this->w->adoma->id, 'branch_id' => $this->w->kumasi->id, 'is_shared' => false,
            'group_id' => ChartOfAccounts::groupFor($this->w->adoma->id, $kind)->id, 'kind' => $kind, 'name' => ucfirst($name),
        ]);
    }
});

function dsHome(object $t): array
{
    return $t->actingAs($t->w->kumasiManager)->get(route('dashboard'))->assertOk()->viewData('page')['props'];
}

function dsBuy(object $t, array $fields): void
{
    $t->actingAs($t->w->kumasiManager)->post(route('purchases.store'), $fields + [
        'date' => now()->toDateString(), 'ledger_id' => $t->supplier->id, 'note' => '', 'price' => '10600', 'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();
}

it('shows the gold in stock apart by kind, and the kinds add up to the total', function () {
    dsBuy($this, ['form' => 'raw', 'weight' => '2094.25', 'water_weight' => '114.56']);
    dsBuy($this, ['form' => 'refined', 'weight' => '300.00', 'karat' => '24']);

    $props = dsHome($this);

    expect($props['goldInStock'])->toBe('2,394.25')
        ->and($props['goldByForm'])->toBe([['label' => 'Raw', 'grams' => '2,094.25'], ['label' => 'Refined', 'grams' => '300.00']]);
});

it('shows nothing of a kind there is none of, as zero, not as missing', function () {
    expect(dsHome($this)['goldByForm'])->toBe([['label' => 'Raw', 'grams' => '0.00'], ['label' => 'Refined', 'grams' => '0.00']]);
});

it('changes the totals as soon as gold is sold', function () {
    dsBuy($this, ['form' => 'raw', 'weight' => '2094.25', 'water_weight' => '114.56']);

    $this->actingAs($this->w->kumasiManager)->post(route('sales.store'), [
        'date' => now()->toDateString(), 'ledger_id' => $this->customer->id, 'form' => 'raw', 'weight' => '1000.00',
        'water_weight' => '54.70', 'karat' => '', 'price' => '12000', 'note' => '', 'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();

    $props = dsHome($this);

    expect($props['goldInStock'])->toBe('1,094.25')
        ->and($props['goldByForm'][0])->toBe(['label' => 'Raw', 'grams' => '1,094.25']);
});

it('keeps each branch\'s kinds to itself', function () {
    dsBuy($this, ['form' => 'raw', 'weight' => '2094.25', 'water_weight' => '114.56']);

    $central = $this->actingAs($this->w->centralManager)->get(route('dashboard'))->assertOk()->viewData('page')['props'];

    expect($central['goldInStock'])->toBe('0.00')->and($central['goldByForm'][0]['grams'])->toBe('0.00');
});
