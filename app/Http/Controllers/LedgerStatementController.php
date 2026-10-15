<?php

namespace App\Http\Controllers;

use App\Domain\Documents\JournalLine;
use App\Domain\Documents\LedgerBalances;
use App\Domain\Ledgers\Ledger;
use App\Domain\Support\Money;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * A ledger's statement: every entry, with a running balance, for a chosen period.
 * It covers the branches in context, so a branch sees its own dealings with a ledger.
 */
class LedgerStatementController extends Controller
{
    private const LIMIT = 1000;

    public function __invoke(Request $request, Ledger $ledger): Response
    {
        $this->authorize('view', $ledger);

        $from = $this->date($request->query('from'));
        $to = $this->date($request->query('to'));

        $base = JournalLine::query()->where('ledger_id', $ledger->id);

        // What the ledger stood at when the period began.
        $opening = $from
            ? Money::fromDb((clone $base)->where('date', '<', $from)->sum('debit')) - Money::fromDb((clone $base)->where('date', '<', $from)->sum('credit'))
            : 0;

        $lines = (clone $base)
            ->with('document')
            ->when($from, fn ($q) => $q->where('date', '>=', $from))
            ->when($to, fn ($q) => $q->where('date', '<=', $to))
            ->orderBy('date')->orderBy('id')
            ->limit(self::LIMIT + 1)
            ->get();

        $truncated = $lines->count() > self::LIMIT;
        $lines = $lines->take(self::LIMIT);

        $running = $opening;
        $debits = 0;
        $credits = 0;

        $rows = $lines->map(function (JournalLine $line) use (&$running, &$debits, &$credits) {
            $debit = Money::fromDb($line->debit);
            $credit = Money::fromDb($line->credit);
            $running += $debit - $credit;
            $debits += $debit;
            $credits += $credit;

            return [
                'id' => $line->id,
                'date' => $line->date->toDateString(),
                'document_id' => $line->document_id,
                'number' => $line->document?->number,
                'type_label' => $line->document?->type->label(),
                'note' => $line->document?->note,
                'debit' => $debit > 0 ? Money::format($debit) : '',
                'credit' => $credit > 0 ? Money::format($credit) : '',
                'balance' => Money::drCr($running),
            ];
        })->values();

        $context = app(TenantContext::class);

        return Inertia::render('Ledgers/Statement', [
            'ledger' => [
                'id' => $ledger->id,
                'name' => $ledger->name,
                'kind_label' => $ledger->kind->label(),
                'office' => $ledger->is_shared ? 'All branches' : $ledger->branch?->name,
            ],
            'covers' => $context->viewingAllBranches() ? 'All branches' : $context->branch()?->name,
            'filters' => ['from' => $from, 'to' => $to],
            'opening' => Money::drCr($opening),
            'rows' => $rows,
            'totals' => ['debit' => Money::format($debits), 'credit' => Money::format($credits)],
            'closing' => Money::drCr($running),
            'closing_words' => LedgerBalances::describe($ledger->kind, $running),
            'truncated' => $truncated,
            'limit' => self::LIMIT,
        ]);
    }

    private function date(mixed $value): string
    {
        try {
            return $value ? CarbonImmutable::createFromFormat('Y-m-d', (string) $value)->toDateString() : '';
        } catch (Throwable) {
            return '';
        }
    }
}
