<?php

namespace App\Domain\Support;

use InvalidArgumentException;

/**
 * Money is handled as whole pesewas (integer cents) everywhere in code, so adding up
 * never drifts the way floating point numbers do. The database keeps DECIMAL(18,2).
 */
final class Money
{
    /** "1,250.50" or "1250.5" or 1250 into 125050. Anything that is not a plain amount is refused. */
    public static function cents(string|int|float|null $amount): int
    {
        $text = str_replace([',', ' '], '', trim((string) $amount));

        if (! preg_match('/^(\d{1,13})(?:\.(\d{1,2}))?$/', $text, $parts)) {
            throw new InvalidArgumentException("Not a valid amount: \"$amount\".");
        }

        return ((int) $parts[1]) * 100 + (int) str_pad($parts[2] ?? '0', 2, '0');
    }

    /** What the database hands back (a DECIMAL, or a float on SQLite) into whole pesewas. */
    public static function fromDb(string|int|float|null $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    /** 125050 into "1250.50", ready for a DECIMAL column. */
    public static function decimal(int $cents): string
    {
        return sprintf('%s%d.%02d', $cents < 0 ? '-' : '', intdiv(abs($cents), 100), abs($cents) % 100);
    }

    /** 125050 into "1,250.50". */
    public static function format(int $cents): string
    {
        return ($cents < 0 ? '-' : '').number_format(abs($cents) / 100, 2);
    }

    /** 125050 into "GH₵ 1,250.50". */
    public static function cedis(int $cents): string
    {
        return 'GH₵ '.self::format($cents);
    }

    /** For statements: debit balances end in Dr, credit balances in Cr. */
    public static function drCr(int $cents): string
    {
        return match (true) {
            $cents > 0 => self::format($cents).' Dr',
            $cents < 0 => self::format(abs($cents)).' Cr',
            default => '0.00',
        };
    }
}
