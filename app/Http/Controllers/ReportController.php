<?php

namespace App\Http\Controllers;

use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentType;
use App\Domain\Documents\JournalLine;
use App\Domain\Documents\LedgerBalances;
use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use App\Domain\Reports\ReportPeriod;
use App\Domain\Reports\ReportTable;
use App\Domain\Stock\StockMovement;
use App\Domain\Support\Money;
use App\Domain\Tenancy\Role;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The reports the client asked for: purchases, sales, one person's supplies, and a summary of opening and closing
 * balances. Each covers a period and the branch in view (or every branch, for an owner or admin viewing them all),
 * reads through the same scopes as everything else, and can be printed or downloaded for a spreadsheet.
 *
 * Reversed entries, and the reversals that cancel them, are left out of the lists, so a list shows what stands.
 */
class ReportController extends Controller
{
    /** Rows shown on screen. The totals always cover everything in the period, however many rows there are. */
    private const LIMIT = 2000;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Document::class);

        return Inertia::render('Reports/Index', ['covers' => $this->covers()]);
    }

    public function purchases(Request $request): Response|StreamedResponse
    {
        $this->authorize('viewAny', Document::class);

        $period = ReportPeriod::fromRequest($request);
        $all = $this->viewingAll();

        $documents = fn () => $this->standing(DocumentType::Purchase, $period);

        $rows = $documents()->with(['ledger', 'branch', 'goldLine'])->orderBy('documents.date')->orderBy('documents.id')->limit(self::LIMIT + 1)->get();
        $truncated = $rows->count() > self::LIMIT;

        $totals = $documents()->join('gold_lines', 'gold_lines.document_id', '=', 'documents.id')
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(documents.total), 0) as amount, COALESCE(SUM(gold_lines.weight_g), 0) as weight')->first();

        $columns = array_values(array_filter([
            ['label' => 'Date'], ['label' => 'Number'], ['label' => 'Supplier'],
            $all ? ['label' => 'Branch'] : null,
            ['label' => 'Form'], ['label' => 'Weight (g)', 'number' => true], ['label' => 'Karat', 'number' => true],
            ['label' => 'Price per pound', 'number' => true], ['label' => 'Amount (GH₵)', 'number' => true],
        ]));

        $table = new ReportTable('Purchases', $columns, $rows->take(self::LIMIT)->map(fn (Document $d) => array_values(array_filter([
            $this->day($d->date), $d->number, $d->ledger?->name,
            $all ? $d->branch?->name : null,
            ucfirst((string) $d->goldLine?->form), $this->grams($d->goldLine?->weight_g), $this->figure($d->goldLine?->karat),
            $this->figure($d->goldLine?->price), Money::format(Money::fromDb($d->total)),
        ], fn ($cell) => $cell !== null)))->values()->all(), array_values(array_filter([
            'Total', '', '', $all ? '' : null, '', $this->grams($totals->weight), '', '', Money::format(Money::fromDb($totals->amount)),
        ], fn ($cell) => $cell !== null)));

        return $this->respond($request, 'purchases', 'Purchases', $period, [$table], [
            ['label' => 'Purchases', 'value' => (string) (int) $totals->n],
            ['label' => 'Gold bought', 'value' => $this->grams($totals->weight), 'unit' => 'g'],
            ['label' => 'Total amount', 'value' => Money::cedis(Money::fromDb($totals->amount))],
        ], ['truncated' => $truncated]);
    }

    public function sales(Request $request): Response|StreamedResponse
    {
        $this->authorize('viewAny', Document::class);

        $period = ReportPeriod::fromRequest($request);
        $all = $this->viewingAll();
        $showCost = $this->canSeeCost($request);

        $documents = fn () => $this->standing(DocumentType::Sale, $period);

        $rows = $documents()->with(['ledger', 'branch', 'goldLine'])->orderBy('documents.date')->orderBy('documents.id')->limit(self::LIMIT + 1)->get();
        $truncated = $rows->count() > self::LIMIT;

        $totals = $documents()->join('gold_lines', 'gold_lines.document_id', '=', 'documents.id')
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(documents.total), 0) as amount, COALESCE(SUM(gold_lines.weight_g), 0) as weight')->first();

        // What each sale's gold had cost. A margin is sensitive, so only managers and above get these columns.
        $costs = [];
        $costTotal = 0;

        if ($showCost) {
            $costLedger = Ledger::withoutGlobalScopes()->where('tenant_id', app(TenantContext::class)->tenantId())->where('system_key', 'cost_of_sales')->first();

            if ($costLedger !== null) {
                $costs = JournalLine::query()->where('ledger_id', $costLedger->id)->whereIn('document_id', $rows->take(self::LIMIT)->pluck('id'))
                    ->selectRaw('document_id, SUM(debit) as cost')->groupBy('document_id')->pluck('cost', 'document_id')->map(fn ($v) => Money::fromDb($v))->all();
                $costTotal = Money::fromDb(JournalLine::query()->where('ledger_id', $costLedger->id)->whereIn('document_id', $documents()->select('documents.id'))->sum('debit'));
            }
        }

        $amountTotal = Money::fromDb($totals->amount);

        $columns = array_values(array_filter([
            ['label' => 'Date'], ['label' => 'Number'], ['label' => 'Customer'],
            $all ? ['label' => 'Branch'] : null,
            ['label' => 'Form'], ['label' => 'Weight (g)', 'number' => true], ['label' => 'Karat', 'number' => true],
            ['label' => 'Amount (GH₵)', 'number' => true],
            $showCost ? ['label' => 'Cost (GH₵)', 'number' => true] : null,
            $showCost ? ['label' => 'Profit (GH₵)', 'number' => true] : null,
        ]));

        $table = new ReportTable('Sales', $columns, $rows->take(self::LIMIT)->map(function (Document $d) use ($all, $showCost, $costs) {
            $amount = Money::fromDb($d->total);
            $cost = $costs[$d->id] ?? 0;

            return array_values(array_filter([
                $this->day($d->date), $d->number, $d->ledger?->name,
                $all ? $d->branch?->name : null,
                ucfirst((string) $d->goldLine?->form), $this->grams($d->goldLine?->weight_g), $this->figure($d->goldLine?->karat),
                Money::format($amount),
                $showCost ? Money::format($cost) : null,
                $showCost ? $this->signed($amount - $cost) : null,
            ], fn ($cell) => $cell !== null));
        })->values()->all(), array_values(array_filter([
            'Total', '', '', $all ? '' : null, '', $this->grams($totals->weight), '', Money::format($amountTotal),
            $showCost ? Money::format($costTotal) : null,
            $showCost ? $this->signed($amountTotal - $costTotal) : null,
        ], fn ($cell) => $cell !== null)));

        return $this->respond($request, 'sales', 'Sales', $period, [$table], array_values(array_filter([
            ['label' => 'Sales', 'value' => (string) (int) $totals->n],
            ['label' => 'Gold sold', 'value' => $this->grams($totals->weight), 'unit' => 'g'],
            ['label' => 'Total amount', 'value' => Money::cedis($amountTotal)],
            $showCost ? ['label' => 'Profit', 'value' => Money::cedis($amountTotal - $costTotal)] : null,
        ])), ['truncated' => $truncated, 'notes' => ['Gold sent to another branch is a transfer, and is not counted here.']]);
    }

    /** What one person, supplier or customer, supplied in a period. */
    public function supplier(Request $request): Response|StreamedResponse
    {
        $this->authorize('viewAny', Document::class);

        $period = ReportPeriod::fromRequest($request);
        $all = $this->viewingAll();

        // Found through the usual scopes, so only a ledger this branch can see.
        $person = $request->integer('ledger_id') > 0
            ? Ledger::query()->whereIn('kind', [LedgerKind::Supplier->value, LedgerKind::Customer->value])->find($request->integer('ledger_id'))
            : null;

        $extra = ['person' => $person ? ['id' => $person->id, 'name' => $person->name, 'kind_label' => $person->kind->label()] : null];

        if ($person === null) {
            return $this->respond($request, 'supplier', 'Supplier report', $period, [], [], $extra);
        }

        $documents = fn () => $this->standing(DocumentType::Purchase, $period)->where('documents.ledger_id', $person->id);

        $rows = $documents()->with(['branch', 'goldLine'])->orderBy('documents.date')->orderBy('documents.id')->limit(self::LIMIT + 1)->get();
        $truncated = $rows->count() > self::LIMIT;

        $totals = $documents()->join('gold_lines', 'gold_lines.document_id', '=', 'documents.id')
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(documents.total), 0) as amount, COALESCE(SUM(gold_lines.weight_g), 0) as weight')->first();

        $columns = array_values(array_filter([
            ['label' => 'Date'], ['label' => 'Number'],
            $all ? ['label' => 'Branch'] : null,
            ['label' => 'Form'], ['label' => 'Weight (g)', 'number' => true], ['label' => 'Karat', 'number' => true],
            ['label' => 'Price per pound', 'number' => true], ['label' => 'Amount (GH₵)', 'number' => true],
        ]));

        $table = new ReportTable("Gold supplied by {$person->name}", $columns, $rows->take(self::LIMIT)->map(fn (Document $d) => array_values(array_filter([
            $this->day($d->date), $d->number,
            $all ? $d->branch?->name : null,
            ucfirst((string) $d->goldLine?->form), $this->grams($d->goldLine?->weight_g), $this->figure($d->goldLine?->karat),
            $this->figure($d->goldLine?->price), Money::format(Money::fromDb($d->total)),
        ], fn ($cell) => $cell !== null)))->values()->all(), array_values(array_filter([
            'Total', '', $all ? '' : null, '', $this->grams($totals->weight), '', '', Money::format(Money::fromDb($totals->amount)),
        ], fn ($cell) => $cell !== null)));

        $balance = LedgerBalances::describe($person->kind, LedgerBalances::cents([$person->id])[$person->id]);

        return $this->respond($request, 'supplier', 'Supplier report', $period, [$table], [
            ['label' => 'Supplies', 'value' => (string) (int) $totals->n],
            ['label' => 'Gold supplied', 'value' => $this->grams($totals->weight), 'unit' => 'g'],
            ['label' => 'Total amount', 'value' => Money::cedis(Money::fromDb($totals->amount))],
        ], $extra + ['truncated' => $truncated, 'notes' => ["{$person->name} now: {$balance}."]]);
    }

    /** Every account's opening and closing balance for the period, and the gold in stock. */
    public function summary(Request $request): Response|StreamedResponse
    {
        $this->authorize('viewAny', Document::class);

        $period = ReportPeriod::fromRequest($request);

        $balances = JournalLine::query()
            ->whereDate('date', '<=', $period->to)
            ->selectRaw(
                'ledger_id,
                 SUM(CASE WHEN date < ? THEN debit - credit ELSE 0 END) AS opening,
                 SUM(CASE WHEN date >= ? THEN debit ELSE 0 END) AS debits,
                 SUM(CASE WHEN date >= ? THEN credit ELSE 0 END) AS credits',
                [$period->from, $period->from, $period->from],
            )
            ->groupBy('ledger_id')->get()
            ->map(fn ($row) => [
                'ledger_id' => (int) $row->ledger_id,
                'opening' => Money::fromDb($row->opening),
                'debits' => Money::fromDb($row->debits),
                'credits' => Money::fromDb($row->credits),
            ])
            ->filter(fn (array $r) => $r['opening'] !== 0 || $r['debits'] !== 0 || $r['credits'] !== 0)
            ->values();

        $ledgers = Ledger::withoutGlobalScopes()->whereIn('id', $balances->pluck('ledger_id'))->get()->keyBy('id');

        $groups = [
            'customer' => 'Customers', 'supplier' => 'Suppliers', 'bank' => 'Banks', 'cash' => 'Cash accounts',
            'expense' => 'Expenses', 'branch' => 'Branch accounts', 'account' => 'Business accounts',
        ];

        $rows = [];
        $debits = 0;
        $credits = 0;

        foreach ($groups as $kind => $heading) {
            $inGroup = $balances->filter(fn (array $r) => $ledgers[$r['ledger_id']]->kind->value === $kind)
                ->sortBy(fn (array $r) => mb_strtolower($ledgers[$r['ledger_id']]->name));

            if ($inGroup->isEmpty()) {
                continue;
            }

            $rows[] = ['heading' => $heading];

            foreach ($inGroup as $r) {
                $closing = $r['opening'] + $r['debits'] - $r['credits'];
                $debits += $r['debits'];
                $credits += $r['credits'];

                $rows[] = [
                    $ledgers[$r['ledger_id']]->name, Money::drCr($r['opening']),
                    Money::format($r['debits']), Money::format($r['credits']), Money::drCr($closing),
                ];
            }
        }

        $accounts = new ReportTable('Balances', [
            ['label' => 'Account'], ['label' => 'Opening', 'number' => true], ['label' => 'Debits', 'number' => true],
            ['label' => 'Credits', 'number' => true], ['label' => 'Closing', 'number' => true],
        ], $rows, ['Total', '', Money::format($debits), Money::format($credits), '']);

        // The gold in stock: what it was, what came in and went out by kind of transaction, and what it is.
        $moved = StockMovement::query()
            ->join('documents', 'documents.id', '=', 'stock_movements.document_id')
            ->whereDate('stock_movements.date', '<=', $period->to)
            ->selectRaw(
                'documents.type as kind,
                 SUM(CASE WHEN stock_movements.date < ? THEN stock_movements.weight_g ELSE 0 END) AS before_weight,
                 SUM(CASE WHEN stock_movements.date >= ? THEN stock_movements.weight_g ELSE 0 END) AS during_weight',
                [$period->from, $period->from],
            )
            ->groupBy('documents.type')->get()->keyBy('kind');

        $opening = Money::fromDb($moved->sum('before_weight'));
        $during = fn (string $type) => Money::fromDb($moved[$type]->during_weight ?? 0);
        $closing = $opening + Money::fromDb($moved->sum('during_weight'));

        $stock = new ReportTable('Gold in stock', [['label' => 'Item'], ['label' => 'Grams', 'number' => true]], [
            ['Opening', Money::format($opening)],
            ['Purchases', $this->signed($during('purchase'))],
            ['Received from other branches', $this->signed($during('transfer_in'))],
            ['Sales', $this->signed($during('sale'))],
            ['Sent to other branches', $this->signed($during('transfer'))],
        ], ['Closing', Money::format($closing)]);

        return $this->respond($request, 'summary', 'Balances summary', $period, [$accounts, $stock], [
            ['label' => 'Gold at the start', 'value' => Money::format($opening), 'unit' => 'g'],
            ['label' => 'Gold at the end', 'value' => Money::format($closing), 'unit' => 'g'],
        ], ['notes' => ['Dr means debit, Cr means credit. A customer who owes you has a debit balance.']]);
    }

    // ------------------------------------------------------------------------------------------ shared

    /** Documents of one kind in the period that still stand: not reversed, and not themselves a reversal. */
    private function standing(DocumentType $type, ReportPeriod $period): Builder
    {
        return Document::query()
            ->where('documents.type', $type->value)
            ->where('documents.status', Document::POSTED)
            ->whereNull('documents.reverses_id')
            ->whereDate('documents.date', '>=', $period->from)
            ->whereDate('documents.date', '<=', $period->to);
    }

    /**
     * @param  list<ReportTable>  $tables
     * @param  list<array{label: string, value: string, unit?: string}>  $stats
     * @param  array<string, mixed>  $extra
     */
    private function respond(Request $request, string $key, string $title, ReportPeriod $period, array $tables, array $stats, array $extra = []): Response|StreamedResponse
    {
        if ($request->query('export') === 'csv' && $tables !== []) {
            return $this->download($key, $title, $period, $tables);
        }

        return Inertia::render('Reports/Show', array_merge([
            'report' => $key,
            'title' => $title,
            'covers' => $this->covers(),
            'period' => ['from' => $period->from, 'to' => $period->to, 'label' => $period->label()],
            'stats' => $stats,
            'tables' => array_map(fn (ReportTable $t) => $t->toArray(), $tables),
            'limit' => self::LIMIT,
            'truncated' => false,
            'notes' => [],
            'person' => null,
        ], $extra));
    }

    /** A spreadsheet file: opens in Excel with the accents and the cedi sign intact. */
    private function download(string $key, string $title, ReportPeriod $period, array $tables): StreamedResponse
    {
        $name = sprintf('%s-%s-to-%s.csv', $key, $period->from, $period->to);

        return response()->streamDownload(function () use ($title, $period, $tables) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [$title]);
            fputcsv($out, [$this->covers().', '.$period->label()]);

            foreach ($tables as $table) {
                fputcsv($out, []);
                fputcsv($out, [$table->title]);

                foreach ($table->csvRows() as $line) {
                    fputcsv($out, $line);
                }
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function covers(): string
    {
        $context = app(TenantContext::class);

        return $context->viewingAllBranches() ? 'All branches' : (string) $context->branch()?->name;
    }

    private function viewingAll(): bool
    {
        return app(TenantContext::class)->viewingAllBranches();
    }

    /** Cost shows what the gold cost, which reveals the margin, so managers and above only. */
    private function canSeeCost(Request $request): bool
    {
        $user = $request->user();
        $context = app(TenantContext::class);

        return $user->isTenantAdmin()
            || ($context->branchId() !== null && ($user->roleIn($context->branchId())?->atLeast(Role::Manager) ?? false));
    }

    private function day(mixed $date): string
    {
        return $date->format('j M Y');
    }

    private function grams(mixed $dbValue): string
    {
        return $dbValue === null ? '' : Money::format(Money::fromDb($dbValue));
    }

    private function figure(mixed $dbValue): string
    {
        return $dbValue === null ? '' : Money::format(Money::fromDb($dbValue));
    }

    /** A figure with a minus sign when it is below nothing, for profits and gold that went out. */
    private function signed(int $hundredths): string
    {
        return ($hundredths < 0 ? '-' : '').Money::format(abs($hundredths));
    }
}
