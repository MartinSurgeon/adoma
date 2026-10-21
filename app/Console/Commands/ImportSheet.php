<?php

namespace App\Console\Commands;

use App\Domain\Audit\Audit;
use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentType;
use App\Domain\Documents\PostingService;
use App\Domain\Gold\FormulaSet;
use App\Domain\Gold\GoldCalculator;
use App\Domain\Ledgers\ChartOfAccounts;
use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use App\Domain\Support\Money;
use App\Domain\Tenancy\Branch;
use App\Domain\Tenancy\Role;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Brings the client's purchases sheet in, once, and takes it out again cleanly.
 *
 *     php artisan adoma:import-sheet "storage/app/imports/2010 (current).xlsx"
 *     php artisan adoma:import-sheet --remove
 *
 * The sheet has a Date, Supplier, Weight_Both, Volume (the water weight) and Price on each row; Karl works out the rest
 * with the business's own formula, so every purchase reads exactly like one entered by hand. Each is posted at the head
 * office on its own date, and marked with a key ("sheet-2010:row-12"): running the import again adds nothing twice, and
 * --remove finds exactly what it added. Suppliers it had to create are noted, and removed with it if nothing else uses them.
 */
class ImportSheet extends Command
{
    protected $signature = 'adoma:import-sheet
        {file? : The .xlsx file}
        {--remove : Take out everything this import added}
        {--key=sheet-2010 : Marks the purchases of this import, so it can be run again or removed}
        {--branch= : The code of the branch to import into (default: the head office)}
        {--split= : The code of a second branch to take about half the gold, by weight}
        {--business= : The name of the business, when the server has more than one}
        {--as= : Email of the person the purchases are recorded as entered by (default: the owner)}
        {--force : Remove without asking}';

    protected $description = 'Import the client\'s purchases sheet as head-office purchases, or remove that import';

    /** The columns the sheet must have, in its first row. */
    private const HEADINGS = ['A' => 'Date', 'B' => 'Supplier', 'C' => 'Weight_Both', 'D' => 'Volume', 'E' => 'Price'];

    public function handle(TenantContext $context, PostingService $posting): int
    {
        $tenant = $this->option('business')
            ? Tenant::where('name', $this->option('business'))->first()
            : (Tenant::query()->count() === 1 ? Tenant::query()->first() : null);

        if ($tenant === null) {
            $this->error($this->option('business') ? 'There is no business by that name.' : 'This server has more than one business: say which with --business.');

            return self::FAILURE;
        }

        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)
            ->when($this->option('branch'), fn ($q, $code) => $q->where('code', strtoupper($code)), fn ($q) => $q->where('is_head_office', true))
            ->first();

        if ($branch === null) {
            $this->error($this->option('branch') ? 'There is no branch with that code.' : 'The business has no head office. Mark one under Admin > Branches, or choose a branch with --branch=CODE.');

            return self::FAILURE;
        }

        $context->set($tenant, $branch);

        return $this->option('remove') ? $this->remove($tenant, $branch) : $this->import($tenant, $branch, $posting);
    }

    private function import(Tenant $tenant, Branch $branch, PostingService $posting): int
    {
        $file = (string) $this->argument('file');

        if ($file === '' || ! is_file($file)) {
            $this->error($file === '' ? 'Give the .xlsx file to import.' : "There is no file at {$file}.");

            return self::FAILURE;
        }

        $user = $this->option('as')
            ? User::where('tenant_id', $tenant->id)->where('email', strtolower((string) $this->option('as')))->first()
            : User::where('tenant_id', $tenant->id)->where('tenant_role', Role::Owner->value)->orderBy('id')->first();

        if ($user === null) {
            $this->error('No such person in this business to record the purchases as entered by.');

            return self::FAILURE;
        }

        $sheet = IOFactory::load($file)->getSheet(0);

        foreach (self::HEADINGS as $column => $heading) {
            if (trim((string) $sheet->getCell("{$column}1")->getValue()) !== $heading) {
                $this->error("Column {$column} should be headed \"{$heading}\". Is this the right sheet?");

                return self::FAILURE;
            }
        }

        $key = (string) $this->option('key');
        $formula = FormulaSet::currentFor($tenant->id);
        $manifest = $this->manifest($key);
        $done = ['added' => 0, 'already' => 0, 'skipped' => 0, 'grams' => 0, 'cents' => 0, 'match' => 0, 'compared' => 0];
        $problems = [];
        $large = [];

        // Splitting with a second branch: each row goes to one of the two, so each holds about half the gold.
        $split = null;
        if ($this->option('split')) {
            $split = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('code', strtoupper((string) $this->option('split')))->first();

            if ($split === null || $split->is($branch)) {
                $this->error($split === null ? 'There is no branch with the --split code. Add it under Admin > Branches first.' : 'Split with a different branch.');

                return self::FAILURE;
            }
        }
        $assigned = $split ? $this->halves($sheet, $branch, $split) : [];
        $byBranch = [];

        for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
            $cells = fn (string $c) => $sheet->getCell("{$c}{$row}")->getValue();
            $supplier = trim((string) $cells('B'));

            // No supplier: an empty line, or the sheet's own totals row (=SUM(...)). Not a purchase.
            if ($supplier === '') {
                $done['skipped'] += $cells('C') === null ? 0 : 1;

                continue;
            }

            $rowKey = "{$key}:row-{$row}";

            if (Document::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('idempotency_key', $rowKey)->exists()) {
                $done['already']++;

                continue;
            }

            try {
                $date = $this->date($cells('A'));
                $weight = $this->number($cells('C'));
                $figures = GoldCalculator::raw($formula, $weight, $this->number($cells('D')), $this->number($cells('E')),
                    null, true, purchase: true, maxWeightG: GoldCalculator::MAX_IMPORT_WEIGHT_G);

                if ($figures->isAboveMaxKarat()) {
                    $figures = $figures->withOverride('Imported from the client\'s spreadsheet.', $user->id);
                }

                $target = $assigned[$row] ?? $branch;
                app(TenantContext::class)->set($tenant, $target);

                $party = $this->supplier($tenant, $target, $supplier, $manifest);
                $document = $posting->postPurchase($target, $date, $party, $figures, 'From '.basename($file).", row {$row}", $rowKey, $user);
            } catch (Throwable $e) {
                $problems[] = [$row, $supplier, $e->getMessage()];

                continue;
            }

            $done['added']++;
            $byBranch[$target->name][0] = ($byBranch[$target->name][0] ?? 0) + 1;
            $byBranch[$target->name][1] = ($byBranch[$target->name][1] ?? 0) + $figures->weightH;
            $done['grams'] += $figures->weightH;
            $done['cents'] += $figures->amountCents();

            if ($figures->weightH > GoldCalculator::MAX_WEIGHT_G * 100) {
                $large[] = [$row, $supplier, Money::format($figures->weightH).' g', $document->number];
            }

            // The sheet's own amount, where it has one, as a check on the formula.
            $sheetAmount = $sheet->getCell("K{$row}")->getCalculatedValue();
            if (is_numeric($sheetAmount)) {
                $done['compared']++;
                $done['match'] += (int) round((float) $sheetAmount) === $figures->amountCedis ? 1 : 0;
            }
        }

        $this->saveManifest($key, $manifest + ['file' => basename($file), 'branch' => $branch->name]);

        Audit::record('sheet_imported', null, [], [
            'file' => basename($file), 'purchases' => $done['added'], 'branch' => $branch->name,
        ], $user);

        $this->newLine();
        $this->info("Imported {$done['added']} purchases into {$branch->name}".($split ? " and {$split->name}" : '').($done['already'] ? ", {$done['already']} were already in" : '').'.');
        $this->line('  Gold:     '.Money::format($done['grams']).' g');
        foreach ($byBranch as $name => [$count, $grams]) {
            $this->line("    {$name}: {$count} purchases, ".Money::format($grams).' g');
        }
        $this->line('  Amount:   GH₵ '.Money::format($done['cents']));
        $this->line('  Suppliers added: '.count($manifest['ledgers'] ?? []));
        if ($done['skipped'] > 0) {
            $this->line("  Left out: {$done['skipped']} row(s) with figures but no supplier, such as the totals row");
        }
        if ($done['compared'] > 0) {
            $this->line("  Same amount as the sheet: {$done['match']} of {$done['compared']} rows");
        }

        if ($large !== []) {
            $this->newLine();
            $this->warn('Imported although over 100 kg (normally refused as a likely typing mistake):');
            $this->table(['Row', 'Supplier', 'Weight', 'Number'], $large);
        }

        if ($problems !== []) {
            $this->newLine();
            $this->error('Not imported:');
            $this->table(['Row', 'Supplier', 'Why'], $problems);
        }

        $this->newLine();
        $this->line("To take it all out again: php artisan adoma:import-sheet --remove --key={$key}");

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Takes out every purchase the import added, with its stock and its entries in the books, and the suppliers it created
     * if nothing else uses them. Refused while any of that gold has been sold, sent or reversed since: those records would
     * be left pointing at nothing.
     */
    private function remove(Tenant $tenant, Branch $branch): int
    {
        $key = (string) $this->option('key');
        $ids = Document::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('idempotency_key', 'like', "{$key}:row-%")->pluck('id');

        if ($ids->isEmpty()) {
            $this->info('Nothing from this import is in Karl.');

            return self::SUCCESS;
        }

        $branches = Document::withoutGlobalScopes()->whereIn('id', $ids)->distinct()->pluck('branch_id');
        $goldLines = DB::table('gold_lines')->whereIn('document_id', $ids)->pluck('id');
        $lots = DB::table('stock_lots')->whereIn('gold_line_id', $goldLines)->pluck('id');

        $usedBy = DB::table('stock_movements')->whereIn('lot_id', $lots)->whereNotIn('document_id', $ids)->distinct()->pluck('document_id')
            ->merge(DB::table('stock_lots')->whereIn('source_lot_id', $lots)->whereNotNull('document_id')->pluck('document_id'))
            ->merge(DB::table('documents')->whereIn('reverses_id', $ids)->pluck('id'))
            ->unique();

        if ($usedBy->isNotEmpty()) {
            $numbers = DB::table('documents')->whereIn('id', $usedBy)->orderBy('id')->limit(10)->pluck('number')->join(', ');
            $this->error("Some imported gold has since been sold, sent or reversed ({$numbers}). Reverse those first, then remove the import.");

            return self::FAILURE;
        }

        $this->line("This takes out {$ids->count()} imported purchases, their stock and their entries in the books.");

        if (! $this->option('force') && ! $this->confirm('Take them out?')) {
            return self::FAILURE;
        }

        $manifest = $this->manifest($key);

        DB::transaction(function () use ($ids, $goldLines, $lots, $manifest, $branches) {
            DB::table('stock_movements')->whereIn('document_id', $ids)->delete();
            DB::table('stock_lots')->whereIn('id', $lots)->delete();
            DB::table('gold_lines')->whereIn('id', $goldLines)->delete();
            DB::table('journal_lines')->whereIn('document_id', $ids)->delete();
            DB::table('documents')->whereIn('id', $ids)->delete();

            // Suppliers the import created, if nothing else uses them now.
            foreach ($manifest['ledgers'] ?? [] as $ledgerId) {
                $inUse = DB::table('journal_lines')->where('ledger_id', $ledgerId)->exists()
                    || DB::table('documents')->where('ledger_id', $ledgerId)->orWhere('cash_ledger_id', $ledgerId)->exists();

                if (! $inUse) {
                    Ledger::withoutGlobalScopes()->find($ledgerId)?->delete();
                }
            }

            // Purchase numbers carry on from the last one left, so no gap is left where the import was.
            foreach ($branches as $branchId) {
                $last = Document::withoutGlobalScopes()->where('branch_id', $branchId)->where('type', DocumentType::Purchase)->pluck('number')
                    ->map(fn ($n) => (int) Str::afterLast($n, '-'))->max() ?? 0;
                DB::table('number_sequences')->where('branch_id', $branchId)->where('type', DocumentType::Purchase->value)->update(['next_value' => $last + 1]);
            }
        });

        Audit::record('sheet_removed', null, [], ['file' => $manifest['file'] ?? $key, 'purchases' => $ids->count()]);
        Storage::delete("imports/{$key}.json");

        $this->info("Removed {$ids->count()} imported purchases.");

        return self::SUCCESS;
    }

    /**
     * Which branch each row goes to, so the two hold about the same weight of gold: the heaviest rows first, each to the
     * branch with less so far. The same sheet always splits the same way, so running it again still adds nothing twice.
     *
     * @return array<int, Branch>  row number => branch
     */
    private function halves($sheet, Branch $first, Branch $second): array
    {
        $weights = [];
        for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
            $weight = $sheet->getCell("C{$row}")->getValue();
            if (trim((string) $sheet->getCell("B{$row}")->getValue()) !== '' && is_numeric($weight)) {
                $weights[$row] = (float) $weight;
            }
        }

        uksort($weights, fn ($a, $b) => [$weights[$b], $a] <=> [$weights[$a], $b]);

        $held = [0.0, 0.0];
        $assigned = [];
        foreach ($weights as $row => $weight) {
            $side = $held[0] <= $held[1] ? 0 : 1;
            $held[$side] += $weight;
            $assigned[$row] = $side === 0 ? $first : $second;
        }

        return $assigned;
    }

    /** A supplier of the head office (or shared) by name, any case; created if there is none, and noted for --remove. */
    private function supplier(Tenant $tenant, Branch $branch, string $name, array &$manifest): Ledger
    {
        $name = Str::title(mb_strtolower(preg_replace('/\s+/', ' ', $name)));

        $found = Ledger::withoutGlobalScopes()->where('tenant_id', $tenant->id)
            ->whereIn('kind', [LedgerKind::Supplier, LedgerKind::Customer])
            ->where(fn ($q) => $q->where('branch_id', $branch->id)->orWhereNull('branch_id'))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($found !== null) {
            return $found;
        }

        $ledger = Ledger::create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'is_shared' => false,
            'group_id' => ChartOfAccounts::groupFor($tenant->id, LedgerKind::Supplier)->id,
            'kind' => LedgerKind::Supplier, 'name' => $name,
        ]);

        $manifest['ledgers'][] = $ledger->id;

        return $ledger;
    }

    private function date(mixed $value): string
    {
        if (is_numeric($value)) {
            return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }

        return \Carbon\CarbonImmutable::parse((string) $value)->toDateString();
    }

    /** A cell as a plain number with two places, as typed on the scale or the price list. */
    private function number(mixed $value): string
    {
        if (! is_numeric($value)) {
            throw new \InvalidArgumentException('A weight or price is missing or is not a number.');
        }

        return number_format(floor((float) $value * 100 + 1e-6) / 100, 2, '.', '');
    }

    private function manifest(string $key): array
    {
        $path = "imports/{$key}.json";

        return Storage::exists($path) ? (json_decode(Storage::get($path), true) ?: []) : [];
    }

    private function saveManifest(string $key, array $manifest): void
    {
        Storage::put("imports/{$key}.json", json_encode($manifest, JSON_PRETTY_PRINT));
    }
}
