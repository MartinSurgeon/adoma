<?php

namespace App\Domain\Documents;

use App\Domain\Ledgers\LedgerKind;
use App\Domain\Support\Money;

/**
 * Ledger balances, always worked out from the journal lines and never stored.
 * A balance is debits minus credits, in pesewas: positive is a debit balance.
 * It covers the branches in the current context, so a branch sees its own books.
 */
final class LedgerBalances
{
    /** @return array<int, int> ledger id => balance in pesewas */
    public static function cents(array $ledgerIds): array
    {
        if ($ledgerIds === []) {
            return [];
        }

        $balances = array_fill_keys($ledgerIds, 0);

        JournalLine::query()
            ->whereIn('ledger_id', $ledgerIds)
            ->selectRaw('ledger_id, SUM(debit) as debits, SUM(credit) as credits')
            ->groupBy('ledger_id')
            ->get()
            ->each(function ($row) use (&$balances) {
                $balances[(int) $row->ledger_id] = Money::fromDb($row->debits) - Money::fromDb($row->credits);
            });

        return $balances;
    }

    /** A balance in plain words: "GH₵ 1,200.00 · owes you". */
    public static function describe(LedgerKind $kind, int $cents): string
    {
        if ($cents === 0) {
            return 'Settled';
        }

        $amount = Money::cedis(abs($cents));
        $debit = $cents > 0;

        return match ($kind) {
            LedgerKind::Customer, LedgerKind::Branch => $amount.($debit ? ' · owes you' : ' · you owe them'),
            LedgerKind::Supplier => $amount.($debit ? ' · they owe you' : ' · you owe them'),
            LedgerKind::Bank, LedgerKind::Cash => $amount.($debit ? ' · available' : ' · overdrawn'),
            LedgerKind::Expense => $amount.($debit ? ' · spent' : ' · credit'),
            LedgerKind::Account => $amount.($debit ? ' Dr' : ' Cr'),
        };
    }
}
