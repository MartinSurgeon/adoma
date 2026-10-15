<?php

use App\Domain\Documents\Document;
use App\Domain\Gold\CalculationError;
use App\Domain\Gold\FormulaSet;
use App\Domain\Gold\GoldCalculator;
use App\Domain\Gold\GoldLine;
use App\Domain\Ledgers\ChartOfAccounts;
use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use Illuminate\Support\Str;
use Tests\Support\World;

// Karat is a share of 24, so more is normally a mistake in the weights. The client wants an admin to be able to
// allow it, "then at least it gets a second opinion". Clerks and managers are told to ask an admin.
//
// 2,000 g with 100 g of water is a density of 20.00, which works out to 25.07 karat.

beforeEach(function () {
    $this->w = World::build();

    $this->supplier = Ledger::create([
        'tenant_id' => $this->w->adoma->id, 'branch_id' => $this->w->kumasi->id, 'is_shared' => false,
        'group_id' => ChartOfAccounts::groupFor($this->w->adoma->id, LedgerKind::Supplier)->id, 'kind' => LedgerKind::Supplier, 'name' => 'Bright',
    ]);
});

function koForm(object $t, array $more = []): array
{
    return array_merge([
        'date' => now()->toDateString(), 'ledger_id' => $t->supplier->id, 'form' => 'raw', 'weight' => '2000.00',
        'water_weight' => '100.00', 'karat' => '', 'price' => '10600', 'note' => '', 'idempotency_key' => (string) Str::uuid(),
    ], $more);
}

function koAdmin(object $t)
{
    return $t->actingAs($t->w->admin)->withSession(['active_branch_id' => $t->w->kumasi->id]);
}

function koFormula(): FormulaSet
{
    return (new FormulaSet)->forceFill([
        'id' => 1, 'tenant_id' => 1, 'version' => 1,
        'grams_per_pound' => '7.75', 'density_offset' => '10.51', 'karat_factor' => '52.838', 'karat_base' => 23,
    ]);
}

it('refuses a karat above 24 in the calculator unless it is told to allow it', function () {
    expect(fn () => GoldCalculator::raw(koFormula(), '2000.00', '100.00', '10600'))->toThrow(CalculationError::class);

    $figures = GoldCalculator::raw(koFormula(), '2000.00', '100.00', '10600', null, true);

    expect($figures->karatH)->toBe(2507)->and($figures->isAboveMaxKarat())->toBeTrue();
});

it('marks a refusal of a karat a little over 24 as one an admin can override, and one far over as not', function () {
    $a = null;
    try {
        GoldCalculator::refined(koFormula(), '100', '25', '10600');
    } catch (CalculationError $e) {
        $a = $e;
    }

    expect($a?->overridable)->toBeTrue();

    // Even an admin cannot allow more than 30, which can only be a typing mistake.
    expect(fn () => GoldCalculator::refined(koFormula(), '100', '31', '10600', true))->toThrow(CalculationError::class);
    expect(fn () => GoldCalculator::refined(koFormula(), '100', '0', '10600', true))->toThrow(CalculationError::class);
});

it('tells an admin in the preview that the karat can be allowed, and a clerk to ask an admin', function () {
    $admin = koAdmin($this)->postJson(route('gold.calculate'), ['type' => 'purchase', 'form' => 'raw', 'weight' => '2000.00', 'water_weight' => '100.00', 'price' => '10600'])->json();
    $clerk = $this->actingAs($this->w->kumasiClerk)->postJson(route('gold.calculate'), ['type' => 'purchase', 'form' => 'raw', 'weight' => '2000.00', 'water_weight' => '100.00', 'price' => '10600'])->json();

    expect($admin['ok'])->toBeFalse()->and($admin['needs_override'])->toBeTrue()->and($admin['can_override'])->toBeTrue()
        ->and($admin['message'])->toContain('Allow this karat')
        ->and($clerk['can_override'])->toBeFalse()->and($clerk['message'])->toContain('ask an admin');
});

it('previews the figures once an admin ticks the box', function () {
    $data = koAdmin($this)->postJson(route('gold.calculate'), [
        'type' => 'purchase', 'form' => 'raw', 'weight' => '2000.00', 'water_weight' => '100.00', 'price' => '10600', 'karat_override' => true,
    ])->json();

    expect($data['ok'])->toBeTrue()->and($data['figures']['karat'])->toBe('25.07')->and($data['figures']['above_max_karat'])->toBeTrue();
});

it('saves a purchase with a karat above 24 when an admin allows it, keeping who and why', function () {
    koAdmin($this)->post(route('purchases.store'), koForm($this, ['karat_override' => true, 'override_reason' => 'Re-weighed twice, the scale was checked']))
        ->assertSessionHasNoErrors();

    $line = GoldLine::withoutGlobalScopes()->latest('id')->firstOrFail();

    expect($line->karat)->toEqual('25.07')
        ->and($line->karat_override_reason)->toBe('Re-weighed twice, the scale was checked')
        ->and($line->karat_overridden_by)->toBe($this->w->admin->id);
});

it('shows on the document who allowed the karat, and why', function () {
    koAdmin($this)->post(route('purchases.store'), koForm($this, ['karat_override' => true, 'override_reason' => 'Second weighing agreed']))->assertSessionHasNoErrors();
    $document = Document::withoutGlobalScopes()->where('type', 'purchase')->latest('id')->firstOrFail();

    $gold = koAdmin($this)->get(route('documents.show', $document))->assertOk()->viewData('page')['props']['gold'];

    expect($gold['karat_override'])->toBe(['by' => $this->w->admin->name, 'reason' => 'Second weighing agreed']);
});

it('needs a reason before an admin can allow it', function () {
    koAdmin($this)->post(route('purchases.store'), koForm($this, ['karat_override' => true, 'override_reason' => '  ']))
        ->assertSessionHasErrors('override_reason');

    expect(Document::withoutGlobalScopes()->count())->toBe(0);
});

it('refuses an admin who has not ticked the box', function () {
    koAdmin($this)->post(route('purchases.store'), koForm($this))->assertSessionHasErrors('water_weight');
});

it('does not let a clerk or a manager allow it, even by sending the box ticked', function () {
    foreach ([$this->w->kumasiClerk, $this->w->kumasiManager] as $user) {
        $this->actingAs($user)->post(route('purchases.store'), koForm($this, ['karat_override' => true, 'override_reason' => 'Trust me']))
            ->assertSessionHasErrors('water_weight');
    }

    expect(Document::withoutGlobalScopes()->count())->toBe(0);
});

it('never keeps an override note on gold within 24 karat', function () {
    koAdmin($this)->post(route('purchases.store'), koForm($this, [
        'weight' => '2094.25', 'water_weight' => '114.56', 'karat_override' => true, 'override_reason' => 'Not needed here',
    ]))->assertSessionHasNoErrors();

    $line = GoldLine::withoutGlobalScopes()->latest('id')->firstOrFail();

    expect($line->karat_override_reason)->toBeNull()->and($line->karat_overridden_by)->toBeNull();
});

it('allows a refined karat above 24 the same way', function () {
    $refined = ['form' => 'refined', 'weight' => '500.00', 'water_weight' => '', 'karat' => '24.5'];

    $this->actingAs($this->w->kumasiClerk)->post(route('purchases.store'), koForm($this, $refined))->assertSessionHasErrors('karat');
    koAdmin($this)->post(route('purchases.store'), koForm($this, $refined + ['karat_override' => true, 'override_reason' => 'Assay certificate']))->assertSessionHasNoErrors();

    expect(GoldLine::withoutGlobalScopes()->latest('id')->firstOrFail()->karat)->toEqual('24.50');
});
