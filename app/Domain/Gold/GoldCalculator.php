<?php

namespace App\Domain\Gold;

use App\Domain\Support\Money;
use InvalidArgumentException;

/**
 * The client's Excel formulas, in whole-number arithmetic.
 *
 *   pounds  = TRUNC(weight / 7.75, 2)             the whole gold, particles and all
 *   density = TRUNC(big weight / water weight, 2)   big weight = the gold with the particles taken out;
 *                                                   when there are none it is the same as the weight
 *   karat   = TRUNC(((density - 10.51) * 52.838) / density, 2)
 *   amount  = TRUNC((karat / 23) * price * pounds, 0)
 *
 * Every step is cut off, never rounded, exactly as the sheet does, and each step uses the
 * already-cut figure from the step before. The constants come from a FormulaSet, so they can
 * change without touching this code.
 *
 * Everything runs on integers. The amount is multiplied out first and divided once at the end.
 * Dividing early in decimals loses a hair, and one of the client's own rows has an exact answer
 * of 4,147,515 that early division would turn into 4,147,514.
 *
 * Refined gold has its karat typed in, because it is known, so there is no density step.
 * The pounds and amount steps are the same.
 */
final class GoldCalculator
{
    /** Limits that keep every product inside a PHP integer with room to spare. */
    public const MAX_WEIGHT_G = 100_000;

    public const MAX_PRICE = 1_000_000;

    public static function raw(FormulaSet $formula, string $weight, string $waterWeight, string $price, ?string $bigWeight = null): GoldFigures
    {
        $weightH = self::positive($weight, 'The weight', self::MAX_WEIGHT_G, 'weight');
        $waterH = self::positive($waterWeight, 'The water weight', self::MAX_WEIGHT_G, 'water_weight');
        $priceH = self::positive($price, 'The price', self::MAX_PRICE, 'price');

        // The client weighs all the gold (that gives the pounds), takes out the particles they cannot find a volume for,
        // and weighs again (the big weight). Only the big weight goes with the water weight. No particles: the same weight.
        $bigH = null;

        if ($bigWeight !== null && trim($bigWeight) !== '') {
            $bigH = self::positive($bigWeight, 'The weight without particles', self::MAX_WEIGHT_G, 'big_weight');

            if ($bigH > $weightH) {
                throw new CalculationError('The weight without particles cannot be more than the full weight.', 'big_weight');
            }
        }

        $densityH = intdiv(($bigH ?? $weightH) * 100, $waterH);        // TRUNC(big weight / water, 2)

        $offsetH = self::hundredths($formula->density_offset);
        $factorK = self::thousandths($formula->karat_factor);

        if ($densityH <= 0) {
            throw new CalculationError('The density works out to nothing. Check the weight and the water weight.', 'water_weight');
        }

        // TRUNC(((density - offset) * factor) / density, 2), with density in hundredths and factor in thousandths.
        $karatH = intdiv(($densityH - $offsetH) * $factorK, 10 * $densityH);

        self::assertKarat($karatH, $densityH);

        return self::finish($formula, 'raw', $weightH, $waterH, $densityH, $karatH, $priceH, $bigH);
    }

    public static function refined(FormulaSet $formula, string $weight, string $karat, string $price): GoldFigures
    {
        $weightH = self::positive($weight, 'The weight', self::MAX_WEIGHT_G, 'weight');
        $karatH = self::positive($karat, 'The karat', 24, 'karat');
        $priceH = self::positive($price, 'The price', self::MAX_PRICE, 'price');

        self::assertKarat($karatH, null);

        return self::finish($formula, 'refined', $weightH, null, null, $karatH, $priceH);
    }

    private static function finish(FormulaSet $formula, string $form, int $weightH, ?int $waterH, ?int $densityH, int $karatH, int $priceH, ?int $bigH = null): GoldFigures
    {
        $poundsH = self::poundsFor($formula, $weightH);
        $amountCedis = self::amountFor($formula, $weightH, $karatH, $priceH);

        if ($amountCedis <= 0) {
            throw new CalculationError('The amount works out to nothing. Check the weight and the price.', 'price');
        }

        return new GoldFigures($form, $weightH, $waterH, $poundsH, $densityH, $karatH, $priceH, $amountCedis, $formula, $bigH);
    }

    /** TRUNC(weight / 7.75, 2), in hundredths. */
    public static function poundsFor(FormulaSet $formula, int $weightH): int
    {
        return intdiv($weightH * 100, self::hundredths($formula->grams_per_pound));
    }

    /**
     * What a weight of gold of a given karat comes to at a price per pound, in whole cedis:
     * TRUNC((karat / base) * price * pounds, 0), all three in hundredths, so divided by 100 three times.
     * A sale of this gold, and a transfer of it between branches, are priced by this one rule.
     */
    public static function amountFor(FormulaSet $formula, int $weightH, int $karatH, int $priceH): int
    {
        return intdiv($karatH * $priceH * self::poundsFor($formula, $weightH), $formula->karat_base * 1_000_000);
    }

    /** Karat is a share of 24. Outside 0 to 24 means the weights are wrong, so the answer is refused. */
    private static function assertKarat(int $karatH, ?int $densityH): void
    {
        if ($karatH > 0 && $karatH <= 2400) {
            return;
        }

        $karat = Money::format($karatH);

        throw $densityH === null
            ? new CalculationError("Karat must be more than 0 and no more than 24, and {$karat} is not.", 'karat')
            : new CalculationError(sprintf(
                'The density works out to %s, which gives %s karat. Karat must be more than 0 and no more than 24, so check the weight and the water weight.',
                Money::format($densityH),
                $karat,
            ), 'water_weight');
    }

    /** A plain number above zero and up to a limit, in hundredths. */
    private static function positive(string $value, string $label, int $max, string $field): int
    {
        try {
            $hundredths = Money::cents($value);
        } catch (InvalidArgumentException) {
            throw new CalculationError("{$label} must be a number, for example 2094.25.", $field);
        }

        if ($hundredths <= 0) {
            throw new CalculationError("{$label} must be more than zero.", $field);
        }

        if ($hundredths > $max * 100) {
            throw new CalculationError("{$label} is more than ".number_format($max).', which looks like a typing mistake.', $field);
        }

        return $hundredths;
    }

    private static function hundredths(string|float $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    private static function thousandths(string|float $value): int
    {
        return (int) round(((float) $value) * 1000);
    }
}
