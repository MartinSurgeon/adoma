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
        public ?int $bigH = null,         // grams x 100: the weight with the particles taken out, which goes with the water weight
        public ?string $overrideReason = null,   // why an admin allowed a karat above 24
        public ?int $overriddenBy = null,        // the admin's user id
        public ?int $paidKaratH = null,          // karat x 100 a purchase is paid at (karat less the deduction); null for a sale
    ) {
    }

    /** True when the karat is above the usual 24 maximum, which only an admin can allow. */
    public function isAboveMaxKarat(): bool
    {
        return $this->karatH > GoldCalculator::MAX_KARAT * 100;
    }

    /** The same figures, with the admin who allowed an unusual karat and why. */
    public function withOverride(string $reason, int $userId): self
    {
        return new self(
            $this->form, $this->weightH, $this->waterH, $this->poundsH, $this->densityH, $this->karatH,
            $this->priceH, $this->amountCedis, $this->formula, $this->bigH, $reason, $userId, $this->paidKaratH,
        );
    }

    /**
     * The gold's purity in percent, x 100: the karat it is paid or sold at, out of 24, cut off as the sheet's
     * Purity (%) column shows it to two places (its I / 0.24).
     */
    public function purityH(): int
    {
        return intdiv(($this->paidKaratH ?? $this->karatH) * 100, 24);
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
            'big_weight' => $this->bigH === null ? null : Money::decimal($this->bigH),
            'water_weight' => $this->waterH === null ? null : Money::decimal($this->waterH),
            'pounds' => Money::decimal($this->poundsH),
            'density' => $this->densityH === null ? null : Money::decimal($this->densityH),
            'karat' => Money::decimal($this->karatH),
            'paid_karat' => $this->paidKaratH === null ? null : Money::decimal($this->paidKaratH),
            'karat_deduction' => $this->paidKaratH === null ? null : Money::decimal($this->karatH - $this->paidKaratH),
            'purity' => Money::decimal($this->purityH()),
            'above_max_karat' => $this->isAboveMaxKarat(),
            'price' => Money::decimal($this->priceH),
            'amount' => Money::decimal($this->amountCents()),
            'amount_text' => Money::cedis($this->amountCents()),
            'formula_version' => $this->formula->version,
        ];
    }
}
