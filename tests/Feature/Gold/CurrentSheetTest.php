<?php

use App\Domain\Gold\FormulaSet;
use App\Domain\Gold\GoldCalculator;
use App\Domain\Gold\GoldLine;
use App\Domain\Ledgers\ChartOfAccounts;
use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use Tests\Support\World;

/*
 * The client's current sheet, "2010 (current).xlsx": 106 purchases, each worked out by their own formulas. Adoma must
 * give every figure of every row, to the cedi. Only the numbers are kept here (tests/Fixtures/current-sheet.json):
 * the suppliers' names and the dates stay with the client.
 *
 * The sheet pays a supplier at the karat less 0.16 ("Karat (-0.7%)"), shows Purity (%) as that karat / 0.24, and
 * prices sales at the karat itself.
 */

function currentSheetRows(): array
{
    return json_decode(file_get_contents(__DIR__.'/../../Fixtures/current-sheet.json'), true);
}

it('matches every figure of every row of the current sheet', function () {
    $formula = new FormulaSet(FormulaSet::DEFAULTS);
    $rows = currentSheetRows();

    expect($rows)->toHaveCount(106);

    foreach ($rows as $i => $row) {
        $figures = GoldCalculator::raw($formula, $row['weight'], $row['water_weight'], $row['price'], purchase: true)->toArray();
        $where = 'row '.($i + 2).' of the sheet';

        expect($figures['pounds'])->toBe($row['pounds'], "pounds, $where")
            ->and($figures['density'])->toBe($row['density'], "density, $where")
            ->and($figures['karat'])->toBe($row['karat'], "karat, $where")
            ->and($figures['paid_karat'])->toBe($row['paid_karat'], "karat paid, $where")
            ->and($figures['purity'])->toBe($row['purity'], "purity, $where")
            ->and($figures['amount'])->toBe($row['amount'].'.00', "amount, $where");
    }
});

it('prices a sale at the karat itself, with no deduction', function () {
    $formula = new FormulaSet(FormulaSet::DEFAULTS);
    $row = currentSheetRows()[0];

    $sale = GoldCalculator::raw($formula, $row['weight'], $row['water_weight'], $row['price'])->toArray();

    // (22.24 / 23) x 5230 x 67.02, cut to whole cedis.
    expect($sale['karat'])->toBe('22.24')
        ->and($sale['paid_karat'])->toBeNull()
        ->and($sale['amount'])->toBe('338932.00')
        ->and($sale['purity'])->toBe('92.66');
});

it('takes the deduction off refined gold bought too', function () {
    $formula = new FormulaSet(FormulaSet::DEFAULTS);

    $bought = GoldCalculator::refined($formula, '1000', '24', '10600', purchase: true)->toArray();

    expect($bought['paid_karat'])->toBe('23.84')->and($bought['karat_deduction'])->toBe('0.16');
});

it('gives a new business the current sheet\'s deduction, and records what a purchase was paid at', function () {
    $w = World::build();
    $supplier = Ledger::create([
        'tenant_id' => $w->adoma->id, 'branch_id' => $w->kumasi->id, 'is_shared' => false,
        'group_id' => ChartOfAccounts::groupFor($w->adoma->id, LedgerKind::Supplier)->id, 'kind' => LedgerKind::Supplier, 'name' => 'Nana',
    ]);
    $row = currentSheetRows()[0];

    $data = $this->actingAs($w->kumasiClerk)
        ->postJson(route('gold.calculate'), ['type' => 'purchase', 'form' => 'raw', 'weight' => $row['weight'], 'water_weight' => $row['water_weight'], 'price' => $row['price']])
        ->assertOk()->json();

    expect($data['figures']['amount'])->toBe('336494.00')->and($data['figures']['paid_karat'])->toBe('22.08');

    $this->actingAs($w->kumasiClerk)->post(route('purchases.store'), [
        'date' => now()->toDateString(), 'ledger_id' => $supplier->id, 'form' => 'raw',
        'weight' => $row['weight'], 'water_weight' => $row['water_weight'], 'price' => $row['price'],
        'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();

    $line = GoldLine::withoutGlobalScopes()->latest('id')->first();
    expect($line->karat)->toBe('22.24')
        ->and($line->paid_karat)->toBe('22.08')
        ->and($line->amount)->toBe('336494.00')
        ->and((float) $line->formulaSet->karat_deduction)->toBe(0.16);
});

it('keeps purchases saved before the deduction at the formula they were saved with', function () {
    $w = World::build();
    $w->earlierSheetFormula();
    $earlier = FormulaSet::withoutGlobalScopes()->where('tenant_id', $w->adoma->id)->sole();

    // The migration's step: the current sheet arrives as the next version.
    FormulaSet::withoutGlobalScopes()->create([...FormulaSet::DEFAULTS, 'tenant_id' => $w->adoma->id, 'version' => 2, 'effective_from' => now()->toDateString()]);

    expect((float) $earlier->fresh()->karat_deduction)->toBe(0.0)
        ->and(FormulaSet::currentFor($w->adoma->id)->version)->toBe(2)
        ->and((float) FormulaSet::currentFor($w->adoma->id)->karat_deduction)->toBe(0.16);
});
