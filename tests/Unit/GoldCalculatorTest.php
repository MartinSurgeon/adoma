<?php

use App\Domain\Gold\CalculationError;
use App\Domain\Gold\FormulaSet;
use App\Domain\Gold\GoldCalculator;

/** The constants from the client's Excel sheet. */
function sheetFormula(array $overrides = []): FormulaSet
{
    return (new FormulaSet)->forceFill(array_merge([
        'id' => 1, 'tenant_id' => 1, 'version' => 1,
        'grams_per_pound' => '7.75', 'density_offset' => '10.51', 'karat_factor' => '52.838', 'karat_base' => 23,
    ], $overrides));
}

/**
 * The 16 rows of the client's FORMULARS.xlsx: weight, water weight, price, and what the sheet shows
 * for pounds, density, karat and amount. The numbers are the sheet's own, copied as they are.
 */
dataset('the client\'s sheet', [
    'row 2' => ['2094.25', '114.56', '10600', '270.22', '18.28', '22.45', 2795837],
    'row 3' => ['2081.01', '113.78', '10600', '268.51', '18.28', '22.45', 2778144],
    'row 4' => ['2095.43', '114.66', '10600', '270.37', '18.27', '22.44', 2796143],
    'row 5' => ['2081.41', '113.82', '10600', '268.56', '18.28', '22.45', 2778661],
    'row 6' => ['2095.24', '114.87', '10600', '270.35', '18.24', '22.39', 2789706],
    'row 7' => ['2094.56', '115.22', '10600', '270.26', '18.17', '22.27', 2773831],
    'row 8' => ['2094.66', '114.75', '10600', '270.27', '18.25', '22.40', 2790126],
    'row 9' => ['2093.16', '114.21', '10600', '270.08', '18.32', '22.52', 2803101],
    'row 10' => ['2097.25', '115.08', '10600', '270.61', '18.22', '22.35', 2787400],
    'row 11' => ['3094.10', '169.23', '10600', '399.23', '18.28', '22.45', 4130641],
    'row 12' => ['3098.89', '169.47', '10600', '399.85', '18.28', '22.45', 4137056],
    'row 13' => ['3096.96', '169.29', '10600', '399.60', '18.29', '22.47', 4138153],
    'row 14, whose exact answer is a whole number' => ['3099.82', '169.22', '10600', '399.97', '18.31', '22.50', 4147515],
    'row 15' => ['599.15', '32.61', '10600', '77.30', '18.37', '22.60', 805129],
    'row 16' => ['3088.84', '170.70', '10600', '398.56', '18.09', '22.13', 4064930],
    'row 17' => ['1495.18', '82.64', '10600', '192.92', '18.09', '22.13', 1967599],
]);

it('gives the sheet\'s own figures for every row', function (string $weight, string $water, string $price, string $pounds, string $density, string $karat, int $amount) {
    $figures = GoldCalculator::raw(sheetFormula(), $weight, $water, $price)->toArray();

    expect($figures['pounds'])->toBe($pounds)
        ->and($figures['density'])->toBe($density)
        ->and($figures['karat'])->toBe($karat)
        ->and($figures['amount'])->toBe(number_format($amount, 2, '.', ''));
})->with('the client\'s sheet');

it('adds up to the sheet\'s total', function () {
    $total = 0;
    foreach (dataset_rows() as [$weight, $water, $price, , , , $amount]) {
        $total += GoldCalculator::raw(sheetFormula(), $weight, $water, $price)->amountCedis;
    }

    expect($total)->toBe(48483972);
});

function dataset_rows(): array
{
    return [
        ['2094.25', '114.56', '10600', 0, 0, 0, 2795837], ['2081.01', '113.78', '10600', 0, 0, 0, 2778144],
        ['2095.43', '114.66', '10600', 0, 0, 0, 2796143], ['2081.41', '113.82', '10600', 0, 0, 0, 2778661],
        ['2095.24', '114.87', '10600', 0, 0, 0, 2789706], ['2094.56', '115.22', '10600', 0, 0, 0, 2773831],
        ['2094.66', '114.75', '10600', 0, 0, 0, 2790126], ['2093.16', '114.21', '10600', 0, 0, 0, 2803101],
        ['2097.25', '115.08', '10600', 0, 0, 0, 2787400], ['3094.10', '169.23', '10600', 0, 0, 0, 4130641],
        ['3098.89', '169.47', '10600', 0, 0, 0, 4137056], ['3096.96', '169.29', '10600', 0, 0, 0, 4138153],
        ['3099.82', '169.22', '10600', 0, 0, 0, 4147515], ['599.15', '32.61', '10600', 0, 0, 0, 805129],
        ['3088.84', '170.70', '10600', 0, 0, 0, 4064930], ['1495.18', '82.64', '10600', 0, 0, 0, 1967599],
    ];
}

it('does not lose a cedi when the exact answer is a whole number', function () {
    // 22.50 / 23 x 10,600 x 399.97 is exactly 4,147,515. Dividing early in decimals gives 4,147,514.
    $figures = GoldCalculator::raw(sheetFormula(), '3099.82', '169.22', '10600');

    expect($figures->karatH)->toBe(2250)->and($figures->poundsH)->toBe(39997)
        ->and($figures->amountCedis)->toBe(4147515)
        ->and((2250 * 1060000 * 39997) % (23 * 1_000_000))->toBe(0);
});

it('cuts off, never rounds, at every step', function () {
    // 2094.25 / 7.75 is 270.2258...: rounding would give 270.23, cutting gives 270.22.
    // 2099.99 / 114.66 is 18.3154...: rounding would give 18.32, cutting gives 18.31.
    expect(GoldCalculator::raw(sheetFormula(), '2094.25', '114.56', '10600')->poundsH)->toBe(27022)
        ->and(GoldCalculator::raw(sheetFormula(), '2099.99', '114.66', '10600')->densityH)->toBe(1831)
        ->and(GoldCalculator::raw(sheetFormula(), '2099.99', '114.66', '10600')->karatH)->toBe(2250);
});

it('uses the constants of the formula version it is given', function () {
    $base = GoldCalculator::raw(sheetFormula(), '2094.25', '114.56', '10600');
    $other = GoldCalculator::raw(sheetFormula(['grams_per_pound' => '8.00', 'karat_base' => 24]), '2094.25', '114.56', '10600');

    expect($other->poundsH)->toBe(26178)->and($other->amountCedis)->not->toBe($base->amountCedis)
        ->and($other->formula->karat_base)->toBe(24);
});

// ------------------------------------------------------------------ refined gold

it('takes the karat as typed for refined gold, with the same pounds and amount steps', function () {
    $figures = GoldCalculator::refined(sheetFormula(), '1000.00', '24', '10600')->toArray();

    // 1000 / 7.75 is 129.03 pounds. 24 / 23 x 10,600 x 129.03 is exactly 1,427,184.
    expect($figures['form'])->toBe('refined')
        ->and($figures['water_weight'])->toBeNull()
        ->and($figures['density'])->toBeNull()
        ->and($figures['pounds'])->toBe('129.03')
        ->and($figures['karat'])->toBe('24.00')
        ->and($figures['amount'])->toBe('1427184.00');
});

it('gives refined gold the same amount as raw gold of the same karat, weight and price', function () {
    $raw = GoldCalculator::raw(sheetFormula(), '2094.25', '114.56', '10600');
    $refined = GoldCalculator::refined(sheetFormula(), '2094.25', '22.45', '10600');

    expect($refined->amountCedis)->toBe($raw->amountCedis)->and($refined->poundsH)->toBe($raw->poundsH);
});

// ------------------------------------------------------------------- the guards

it('refuses a karat above 24 and says what to check', function () {
    // Density 19.40 would be heavier than pure gold, so the weights are wrong.
    expect(fn () => GoldCalculator::raw(sheetFormula(), '1940.00', '100.00', '10600'))
        ->toThrow(CalculationError::class, 'density works out to 19.40');
});

it('refuses a karat of zero or less', function (string $water) {
    expect(fn () => GoldCalculator::raw(sheetFormula(), '1000.00', $water, '10600'))
        ->toThrow(CalculationError::class, 'Karat must be more than 0');
})->with(['too much water, density 10.00' => '100.00', 'density just under the offset, 10.50' => '95.15']);

it('refuses a density exactly at the offset, which gives no karat at all', function () {
    // 1051.00 / 100.00 is 10.51, the offset itself, so the karat works out to exactly zero.
    expect(fn () => GoldCalculator::raw(sheetFormula(), '1051.00', '100.00', '10600'))
        ->toThrow(CalculationError::class, 'Karat must be more than 0');
});

it('allows exactly 24 karat and nothing above', function () {
    expect(GoldCalculator::refined(sheetFormula(), '100', '24', '10600')->karatH)->toBe(2400);
    expect(fn () => GoldCalculator::refined(sheetFormula(), '100', '24.01', '10600'))->toThrow(CalculationError::class);
});

it('points each problem at the right box on the form', function (callable $try, string $field) {
    try {
        $try();
        $this->fail('Expected a CalculationError.');
    } catch (CalculationError $error) {
        expect($error->field)->toBe($field);
    }
})->with([
    'weight' => [fn () => GoldCalculator::raw(sheetFormula(), 'abc', '114.56', '10600'), 'weight'],
    'water weight' => [fn () => GoldCalculator::raw(sheetFormula(), '2094.25', '0', '10600'), 'water_weight'],
    'price' => [fn () => GoldCalculator::raw(sheetFormula(), '2094.25', '114.56', '-5'), 'price'],
    'karat' => [fn () => GoldCalculator::refined(sheetFormula(), '100', 'x', '10600'), 'karat'],
    'a density that gives no karat' => [fn () => GoldCalculator::raw(sheetFormula(), '1000.00', '100.00', '10600'), 'water_weight'],
    'a karat above 24' => [fn () => GoldCalculator::refined(sheetFormula(), '100', '30', '10600'), 'karat'],
]);

it('refuses numbers that are not plain numbers above zero', function (string $weight, string $water, string $price) {
    expect(fn () => GoldCalculator::raw(sheetFormula(), $weight, $water, $price))->toThrow(CalculationError::class);
})->with([
    ['', '114.56', '10600'], ['2094.25', '', '10600'], ['2094.25', '114.56', ''],
    ['0', '114.56', '10600'], ['2094.25', '0.00', '10600'], ['2094.25', '114.56', '0'],
    ['-1', '114.56', '10600'], ['1e3', '114.56', '10600'], ['2094.255', '114.56', '10600'],
    ['2,094.25 g', '114.56', '10600'],
]);

it('refuses weights and prices that are far too large, so nothing can overflow', function () {
    expect(fn () => GoldCalculator::raw(sheetFormula(), '100000.01', '5500.00', '10600'))->toThrow(CalculationError::class, 'typing mistake')
        ->and(fn () => GoldCalculator::raw(sheetFormula(), '2094.25', '114.56', '1000000.01'))->toThrow(CalculationError::class, 'typing mistake');

    // The largest allowed inputs still work out without overflowing.
    $big = GoldCalculator::refined(sheetFormula(), '100000.00', '24', '1000000');
    expect($big->amountCedis)->toBeGreaterThan(0)->and($big->amountCedis)->toBeLessThan(PHP_INT_MAX);
});

it('refuses a weighing so small the amount is nothing', function () {
    expect(fn () => GoldCalculator::refined(sheetFormula(), '0.01', '1', '1'))->toThrow(CalculationError::class, 'amount works out to nothing');
});

// ------------------------------------------------- the weight without particles ("big")

it('works the pounds from all the gold and the density from the weight without particles', function () {
    // 2,094.25 g on the scale. With the particles taken out, 2,000.00 g goes with the 114.56 g of water.
    $figures = GoldCalculator::raw(sheetFormula(), '2094.25', '114.56', '10600', '2000.00');

    // 2000.00 / 114.56 = 17.45 (cut off), not the 18.28 that the whole weight would give.
    expect($figures->poundsH)->toBe(27022)                    // still from all the gold: 2094.25 / 7.75
        ->and($figures->densityH)->toBe(1745)
        ->and($figures->karatH)->toBe(2101)
        ->and($figures->bigH)->toBe(200000)
        ->and($figures->weightH)->toBe(209425)
        ->and($figures->amountCedis)->toBe(intdiv(2101 * 1060000 * 27022, 23 * 1_000_000))
        ->and($figures->toArray()['big_weight'])->toBe('2000.00');
});

it('gives exactly the old answer when there are no particles, blank or the same as all the gold', function (?string $big) {
    $old = GoldCalculator::raw(sheetFormula(), '2094.25', '114.56', '10600');
    $new = GoldCalculator::raw(sheetFormula(), '2094.25', '114.56', '10600', $big);

    expect([$new->poundsH, $new->densityH, $new->karatH, $new->amountCedis])->toBe([$old->poundsH, $old->densityH, $old->karatH, $old->amountCedis]);
})->with([null, '', '  ', '2094.25']);

it('keeps blank as "no particles" and a typed weight as typed', function () {
    expect(GoldCalculator::raw(sheetFormula(), '2094.25', '114.56', '10600')->bigH)->toBeNull()
        ->and(GoldCalculator::raw(sheetFormula(), '2094.25', '114.56', '10600', '')->bigH)->toBeNull()
        ->and(GoldCalculator::raw(sheetFormula(), '2094.25', '114.56', '10600', '2094.25')->bigH)->toBe(209425);
});

it('refuses a weight without particles that is more than all the gold, or not a number', function (string $big, string $message) {
    expect(fn () => GoldCalculator::raw(sheetFormula(), '2094.25', '114.56', '10600', $big))
        ->toThrow(CalculationError::class, $message);
})->with([
    'more than all' => ['2094.26', 'cannot be more than the full weight'],
    'zero' => ['0', 'must be more than zero'],
    'text' => ['lots', 'must be a number'],
    'negative' => ['-5', 'must be a number'],
]);

it('says which box is wrong when the weight without particles is', function () {
    try {
        GoldCalculator::raw(sheetFormula(), '2094.25', '114.56', '10600', '3000');
        $this->fail('Expected a CalculationError');
    } catch (CalculationError $error) {
        expect($error->field)->toBe('big_weight');
    }
});

it('leaves refined gold alone, which has its karat typed in and no density step', function () {
    expect(GoldCalculator::refined(sheetFormula(), '1000.00', '22.45', '10600')->bigH)->toBeNull();
});

it('accepts amounts typed with a thousands separator', function () {
    expect(GoldCalculator::raw(sheetFormula(), '2,094.25', '114.56', '10,600')->amountCedis)->toBe(2795837);
});
