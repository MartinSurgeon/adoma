<?php

namespace App\Domain\Documents;

enum DocumentType: string
{
    case Purchase = 'purchase';
    case Sale = 'sale';
    case Receipt = 'receipt';
    case Payment = 'payment';
    /** Gold sent to another branch: the sending branch's half of a transfer. */
    case Transfer = 'transfer';
    /** Gold arriving from another branch: the receiving branch's half of the same transfer. */
    case TransferIn = 'transfer_in';
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
            self::TransferIn => 'TRI',
            self::Journal => 'JNL',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Transfer => 'Transfer out',
            self::TransferIn => 'Transfer in',
            default => ucfirst($this->value),
        };
    }

    /** For the filter on the transactions list: "Purchases", "Transfers out". */
    public function pluralLabel(): string
    {
        return match ($this) {
            self::Transfer => 'Transfers out',
            self::TransferIn => 'Transfers in',
            default => $this->label().'s',
        };
    }

    /** Gold bought or sold, weighed and priced with the client's formulas. */
    public function isGold(): bool
    {
        return $this === self::Purchase || $this === self::Sale;
    }

    /** Gold moving between two branches, at what it cost. */
    public function isTransfer(): bool
    {
        return $this === self::Transfer || $this === self::TransferIn;
    }

    /** Documents that move gold in or out of stock, and so can be put right only by moving it back. */
    public function movesStock(): bool
    {
        return $this->isGold() || $this->isTransfer();
    }

    /** The types a person can record on a screen. A transfer in is made by the sending branch, never typed in. */
    public function isRecordable(): bool
    {
        return $this->isCash() || $this->isGold() || $this === self::Transfer;
    }

    /** Every type that appears in the transactions list. */
    public function isListed(): bool
    {
        return $this->isRecordable() || $this === self::TransferIn;
    }

    /** Money in or out against a ledger and a cash or bank account, with no gold involved. */
    public function isCash(): bool
    {
        return $this === self::Receipt || $this === self::Payment;
    }
}
