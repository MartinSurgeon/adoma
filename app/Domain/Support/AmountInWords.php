<?php

namespace App\Domain\Support;

/**
 * An amount in words for printed receipts, in the style the business already uses:
 * "Thirty Thousand Three Hundred Seventy Two Ghana Cedis, No Pesewas".
 */
final class AmountInWords
{
    private const ONES = [
        '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve',
        'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen',
    ];

    private const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    private const SCALES = [
        1_000_000_000_000 => 'Trillion', 1_000_000_000 => 'Billion', 1_000_000 => 'Million', 1_000 => 'Thousand',
    ];

    public static function cedis(int $cents): string
    {
        $cents = abs($cents);
        $cedis = intdiv($cents, 100);
        $pesewas = $cents % 100;

        $cedisText = $cedis === 0 ? 'No Ghana Cedis' : self::words($cedis).($cedis === 1 ? ' Ghana Cedi' : ' Ghana Cedis');
        $pesewasText = $pesewas === 0 ? 'No Pesewas' : self::words($pesewas).($pesewas === 1 ? ' Pesewa' : ' Pesewas');

        return "$cedisText, $pesewasText";
    }

    /** 21598 into "Twenty One Thousand Five Hundred Ninety Eight". */
    public static function words(int $number): string
    {
        if ($number === 0) {
            return 'Zero';
        }

        $parts = [];

        foreach (self::SCALES as $size => $name) {
            if ($number >= $size) {
                $parts[] = self::underThousand(intdiv($number, $size)).' '.$name;
                $number %= $size;
            }
        }

        if ($number > 0) {
            $parts[] = self::underThousand($number);
        }

        return implode(' ', $parts);
    }

    private static function underThousand(int $number): string
    {
        $parts = [];

        if ($number >= 100) {
            $parts[] = self::ONES[intdiv($number, 100)].' Hundred';
            $number %= 100;
        }

        if ($number >= 20) {
            $parts[] = self::TENS[intdiv($number, 10)];
            $number %= 10;
        }

        if ($number > 0) {
            $parts[] = self::ONES[$number];
        }

        return implode(' ', $parts);
    }
}
