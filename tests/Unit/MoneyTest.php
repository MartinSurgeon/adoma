<?php

use App\Domain\Support\AmountInWords;
use App\Domain\Support\Money;

// ------------------------------------------------------------------------ Money

it('turns an amount typed by a person into whole pesewas', function (string|int|float $input, int $cents) {
    expect(Money::cents($input))->toBe($cents);
})->with([
    'plain' => ['1250', 125000],
    'with pesewas' => ['1250.50', 125050],
    'one digit of pesewas' => ['1250.5', 125050],
    'thousands separator' => ['1,250.50', 125050],
    'spaces' => [' 12 ', 1200],
    'zero' => ['0', 0],
    'an integer' => [75, 7500],
    'a float' => [10.25, 1025],
    'small' => ['0.05', 5],
    'large' => ['9999999999999.99', 999999999999999],
]);

it('refuses anything that is not a plain amount', function (string $input) {
    expect(fn () => Money::cents($input))->toThrow(InvalidArgumentException::class);
})->with(['', 'abc', '-5', '1.234', '1e3', '12.', '.5', '1,2,3.4x', '14 cedis', '12345678901234']);

it('never drifts when adding many small amounts', function () {
    $total = 0;
    foreach (range(1, 1000) as $i) {
        $total += Money::cents('0.10');
    }

    expect($total)->toBe(10000)->and(Money::decimal($total))->toBe('100.00');
});

it('turns pesewas into a decimal for the database', function () {
    expect(Money::decimal(125050))->toBe('1250.50')
        ->and(Money::decimal(5))->toBe('0.05')
        ->and(Money::decimal(0))->toBe('0.00')
        ->and(Money::decimal(-5))->toBe('-0.05')
        ->and(Money::decimal(-125050))->toBe('-1250.50');
});

it('reads back what the database returns, even as a float', function () {
    expect(Money::fromDb('1250.50'))->toBe(125050)
        ->and(Money::fromDb(1250.5))->toBe(125050)
        ->and(Money::fromDb('0.30'))->toBe(30)
        ->and(Money::fromDb(0.1 + 0.2))->toBe(30)
        ->and(Money::fromDb(null))->toBe(0);
});

it('shows amounts for people', function () {
    expect(Money::format(125050))->toBe('1,250.50')
        ->and(Money::format(5))->toBe('0.05')
        ->and(Money::format(-125050))->toBe('-1,250.50')
        ->and(Money::cedis(125050))->toBe('GH₵ 1,250.50')
        ->and(Money::drCr(125050))->toBe('1,250.50 Dr')
        ->and(Money::drCr(-125050))->toBe('1,250.50 Cr')
        ->and(Money::drCr(0))->toBe('0.00');
});

// ---------------------------------------------------------------- AmountInWords

it('writes amounts in words the way the business already prints them', function (int $cents, string $words) {
    expect(AmountInWords::cedis($cents))->toBe($words);
})->with([
    'from a real FileMaker receipt' => [2159800, 'Twenty One Thousand Five Hundred Ninety Eight Ghana Cedis, No Pesewas'],
    'from another real receipt' => [3037200, 'Thirty Thousand Three Hundred Seventy Two Ghana Cedis, No Pesewas'],
    'nothing' => [0, 'No Ghana Cedis, No Pesewas'],
    'one cedi' => [100, 'One Ghana Cedi, No Pesewas'],
    'one pesewa' => [1, 'No Ghana Cedis, One Pesewa'],
    'two cedis' => [200, 'Two Ghana Cedis, No Pesewas'],
    'with pesewas' => [100050, 'One Thousand Ghana Cedis, Fifty Pesewas'],
    'teens' => [11900, 'One Hundred Nineteen Ghana Cedis, No Pesewas'],
    'round hundred' => [10000, 'One Hundred Ghana Cedis, No Pesewas'],
    'twenty' => [2000, 'Twenty Ghana Cedis, No Pesewas'],
    'a million' => [100000000, 'One Million Ghana Cedis, No Pesewas'],
    'millions with pesewas' => [1234567899, 'Twelve Million Three Hundred Forty Five Thousand Six Hundred Seventy Eight Ghana Cedis, Ninety Nine Pesewas'],
    'a billion' => [100000000000, 'One Billion Ghana Cedis, No Pesewas'],
    'every scale' => [123456789012300, 'One Trillion Two Hundred Thirty Four Billion Five Hundred Sixty Seven Million Eight Hundred Ninety Thousand One Hundred Twenty Three Ghana Cedis, No Pesewas'],
    'a thousand exactly' => [100000, 'One Thousand Ghana Cedis, No Pesewas'],
    'skips an empty thousand' => [100000100, 'One Million One Ghana Cedis, No Pesewas'],
]);

it('writes the same words for a negative amount', function () {
    expect(AmountInWords::cedis(-2000))->toBe('Twenty Ghana Cedis, No Pesewas');
});
