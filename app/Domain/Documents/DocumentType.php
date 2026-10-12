<?php

namespace App\Domain\Documents;

enum DocumentType: string
{
    case Purchase = 'purchase';
    case Sale = 'sale';
    case Receipt = 'receipt';
    case Payment = 'payment';
    case Transfer = 'transfer';
    case Journal = 'journal';

    /** The letters at the front of a voucher number. */
    public function prefix(): string
    {
        return match ($this) {
            self::Purchase => 'PCH',
            self::Sale => 'SAL',
            self::Receipt => 'REC',
            self::Payment => 'PV',
            self::Transfer => 'TRF',
            self::Journal => 'JNL',
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
