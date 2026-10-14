<?php

namespace App\Domain\Gold;

use App\Domain\Support\Money;

/**
 * What a weighing works out to. Every figure is a whole number: weights, pounds, density, karat
 * and price in hundredths, and the amount in whole cedis. The amount is the money a supplier is
 * owed or a customer owes, and it never has pesewas, because the sheet cuts it to whole cedis.
 */
final readonly class GoldFigures
{
    public function __construct(
        public string $form,            // raw or refined
        public int $weightH,            // grams x 100
        public ?int $waterH,            // grams x 100, raw gold only
        public int $poundsH,            // pounds x 100
        public ?int $densityH,          // density x 100, raw gold only
        public int $karatH,             // karat x 100
        public int $priceH,             // cedis per pound x 100
        public int $amountCedis,        // whole cedis
        public FormulaSet $formula,
    ) {
    }

    /** The amount in pesewas, ready for the books. */
    public function amountCents(): int
    {
        return $this->amountCedis * 100;
    }

    /** Figures as text for screens and forms. */
    public function toArray(): array
    {
        return [
            'form' => $this->form,
            'weight' => Money::decimal($this->weightH),
            'water_weight' => $this->waterH === null ? null : Money::decimal($this->waterH),
            'pounds' => Money::decimal($this->poundsH),
            'density' => $this->densityH === null ? null : Money::decimal($this->densityH),
            'karat' => Money::decimal($this->karatH),
            'price' => Money::decimal($this->priceH),
            'amount' => Money::decimal($this->amountCents()),
            'amount_text' => Money::cedis($this->amountCents()),
            'formula_version' => $this->formula->version,
        ];
    }
}
