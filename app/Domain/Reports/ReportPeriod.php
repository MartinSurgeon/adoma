<?php

namespace App\Domain\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Throwable;

/**
 * The period a report covers, from one day to another, both included.
 *
 * With nothing chosen it is the month so far, which is what people look at most. A date that cannot be read is
 * ignored rather than refused, and dates chosen the wrong way round are put the right way round.
 */
final readonly class ReportPeriod
{
    public function __construct(public string $from, public string $to)
    {
    }

    public static function fromRequest(Request $request): self
    {
        $today = CarbonImmutable::today();

        $from = self::date($request->query('from')) ?? $today->startOfMonth()->toDateString();
        $to = self::date($request->query('to')) ?? $today->toDateString();

        return $from <= $to ? new self($from, $to) : new self($to, $from);
    }

    /** "1 Oct 2026 to 15 Oct 2026", or "15 Oct 2026" for a single day. */
    public function label(): string
    {
        $format = fn (string $d) => CarbonImmutable::parse($d)->format('j M Y');

        return $this->from === $this->to ? $format($this->from) : $format($this->from).' to '.$format($this->to);
    }

    private static function date(mixed $value): ?string
    {
        try {
            return $value ? CarbonImmutable::createFromFormat('Y-m-d', (string) $value)->toDateString() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
