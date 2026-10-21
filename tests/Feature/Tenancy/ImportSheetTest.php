<?php

use App\Domain\Documents\Document;
use App\Domain\Documents\NumberSequence;
use App\Domain\Gold\GoldLine;
use App\Domain\Ledgers\ChartOfAccounts;
use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use App\Domain\Stock\StockLot;
use App\Domain\Tenancy\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\World;

/*
 * php artisan adoma:import-sheet brings the client's purchases sheet in as head-office purchases, and --remove takes
 * exactly that back out. The sheets here are made up: real figures from the client's sheet, invented names.
 */

beforeEach(function () {
    Storage::fake();
    $this->w = World::build();
});

/** A sheet in the client's layout. Rows: [date serial, supplier, weight, volume, price, sheet amount]. */
function importSheetFile(array $rows): string
{
    $book = new Spreadsheet();
    $sheet = $book->getActiveSheet();
    $sheet->fromArray([['Date', 'Supplier', 'Weight_Both', 'Volume', 'Price', 'Pounds', 'Density', 'Karat', 'Karat (-0.7%)', 'Purity (%)', 'Amount']]);

    foreach ($rows as $i => [$date, $supplier, $weight, $volume, $price, $amount]) {
        $r = $i + 2;
        $sheet->fromArray([[$date, $supplier, $weight, $volume, $price]], null, "A{$r}");
        $sheet->setCellValue("K{$r}", $amount);
    }

    $path = tempnam(sys_get_temp_dir(), 'sheet').'.xlsx';
    (new Xlsx($book))->save($path);

    return $path;
}

function importSheetRows(): array
{
    return [
        [45195, 'NANA KWAME', 519.47, 28.61, 5230, 336494],
        [45195, 'ASHISH', 1545.56, 85.2, 5230, 1000341],
        [45196, 'nana kwame', 9.4, 0.52, 5150, 5944],
        // Over 100 kg: normally refused as a typing mistake, brought in by the import.
        [45841, 'AARON', 206336.56, 11000.00, 10102, null],
    ];
}

it('imports every row as a head-office purchase on its own date, with the sheet\'s amounts', function () {
    $this->artisan('adoma:import-sheet', ['--business' => 'Adoma', 'file' => importSheetFile(importSheetRows())])
        ->expectsOutputToContain('Imported 4 purchases into Central')
        ->expectsOutputToContain('Same amount as the sheet: 3 of 3 rows')
        ->expectsOutputToContain('Imported although over 100 kg')
        ->assertSuccessful();

    $purchases = Document::withoutGlobalScopes()->where('type', 'purchase')->orderBy('id')->get();

    expect($purchases)->toHaveCount(4)
        ->and($purchases->pluck('branch_id')->unique()->all())->toBe([$this->w->central->id])
        ->and($purchases[0]->date->toDateString())->toBe('2023-09-26')
        ->and($purchases[0]->total)->toBe('336494.00')
        ->and($purchases[0]->idempotency_key)->toBe('sheet-2010:row-2')
        ->and($purchases[0]->number)->toBe('PCH-CEN-000001')
        ->and($purchases[0]->created_by)->toBe($this->w->owner->id)
        ->and(GoldLine::withoutGlobalScopes()->where('document_id', $purchases[3]->id)->value('weight_g'))->toBe('206336.56')
        ->and(StockLot::withoutGlobalScopes()->count())->toBe(4);

    // "NANA KWAME" and "nana kwame" are one supplier.
    expect(Ledger::withoutGlobalScopes()->where('kind', LedgerKind::Supplier)->pluck('name')->sort()->values()->all())->toBe(['Aaron', 'Ashish', 'Nana Kwame']);
});

it('adds nothing twice when run again', function () {
    $file = importSheetFile(importSheetRows());
    $this->artisan('adoma:import-sheet', ['--business' => 'Adoma', 'file' => $file])->assertSuccessful();

    $this->artisan('adoma:import-sheet', ['--business' => 'Adoma', 'file' => $file])->expectsOutputToContain('Imported 0 purchases into Central, 4 were already in')->assertSuccessful();

    expect(Document::withoutGlobalScopes()->count())->toBe(4);
});

it('uses a supplier that is already in Adoma, and leaves it in place on removal', function () {
    $existing = Ledger::create([
        'tenant_id' => $this->w->adoma->id, 'branch_id' => $this->w->central->id, 'is_shared' => false,
        'group_id' => ChartOfAccounts::groupFor($this->w->adoma->id, LedgerKind::Supplier)->id, 'kind' => LedgerKind::Supplier, 'name' => 'Ashish',
    ]);

    $this->artisan('adoma:import-sheet', ['--business' => 'Adoma', 'file' => importSheetFile(importSheetRows())])->assertSuccessful();
    expect(Document::withoutGlobalScopes()->where('ledger_id', $existing->id)->count())->toBe(1);

    $this->artisan('adoma:import-sheet', ['--business' => 'Adoma', '--remove' => true, '--force' => true])->assertSuccessful();
    expect(Ledger::withoutGlobalScopes()->whereKey($existing->id)->exists())->toBeTrue();
});

it('takes out exactly what it added, with the suppliers it made, and numbers carry on with no gap', function () {
    $this->artisan('adoma:import-sheet', ['--business' => 'Adoma', 'file' => importSheetFile(importSheetRows())])->assertSuccessful();

    $this->artisan('adoma:import-sheet', ['--business' => 'Adoma', '--remove' => true, '--force' => true])
        ->expectsOutputToContain('Removed 4 imported purchases.')
        ->assertSuccessful();

    expect(Document::withoutGlobalScopes()->count())->toBe(0)
        ->and(GoldLine::withoutGlobalScopes()->count())->toBe(0)
        ->and(StockLot::withoutGlobalScopes()->count())->toBe(0)
        ->and(DB::table('stock_movements')->count())->toBe(0)
        ->and(DB::table('journal_lines')->count())->toBe(0)
        ->and(Ledger::withoutGlobalScopes()->where('kind', LedgerKind::Supplier)->count())->toBe(0)
        ->and(NumberSequence::where('branch_id', $this->w->central->id)->where('type', 'purchase')->value('next_value'))->toBe(1);
});

it('will not remove the import once some of its gold has been sold', function () {
    $this->artisan('adoma:import-sheet', ['--business' => 'Adoma', 'file' => importSheetFile(importSheetRows())])->assertSuccessful();

    $customer = Ledger::create([
        'tenant_id' => $this->w->adoma->id, 'branch_id' => $this->w->central->id, 'is_shared' => false,
        'group_id' => ChartOfAccounts::groupFor($this->w->adoma->id, LedgerKind::Customer)->id, 'kind' => LedgerKind::Customer, 'name' => 'Gold Mart',
    ]);
    $this->actingAs($this->w->centralManager)->withSession(['active_branch_id' => $this->w->central->id])->post(route('sales.store'), [
        'date' => now()->toDateString(), 'ledger_id' => $customer->id, 'form' => 'raw', 'weight' => '100.00', 'water_weight' => '5.47',
        'price' => '5000', 'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();

    $this->artisan('adoma:import-sheet', ['--business' => 'Adoma', '--remove' => true, '--force' => true])
        ->expectsOutputToContain('has since been sold, sent or reversed (SAL-CEN-000001)')
        ->assertFailed();

    expect(Document::withoutGlobalScopes()->where('type', 'purchase')->count())->toBe(4);
});

it('refuses a sheet that is not laid out like the client\'s', function () {
    $book = new Spreadsheet();
    $book->getActiveSheet()->fromArray([['Name', 'Phone']]);
    $path = tempnam(sys_get_temp_dir(), 'sheet').'.xlsx';
    (new Xlsx($book))->save($path);

    $this->artisan('adoma:import-sheet', ['--business' => 'Adoma', 'file' => $path])->expectsOutputToContain('Column A should be headed "Date"')->assertFailed();
    expect(Document::withoutGlobalScopes()->count())->toBe(0);
});

it('splits the rows between two branches so each holds about half the gold, and removes both halves', function () {
    $rows = [
        [45195, 'NANA KWAME', 519.47, 28.61, 5230, 336494],
        [45195, 'ASHISH', 1545.56, 85.2, 5230, 1000341],
        [45196, 'THEO', 2037.93, 108.82, 5215, 1371880],
        [45196, 'GOODS', 371.35, 21, 5160, 228513],
        [45196, 'MASTER ALEX', 125.1, 6.84, 5150, 80555],
    ];
    $file = importSheetFile($rows);

    $this->artisan('adoma:import-sheet', ['--business' => 'Adoma', 'file' => $file, '--branch' => 'KUM', '--split' => 'CEN'])
        ->expectsOutputToContain('Imported 5 purchases into Kumasi and Central')
        ->assertSuccessful();

    $grams = fn ($branch) => round((float) GoldLine::withoutGlobalScopes()->where('branch_id', $branch->id)->sum('weight_g'), 2);

    // Heaviest first, each to the lighter side: Kumasi 2,037.93 + 371.35; Central 1,545.56 + 519.47 + 125.10.
    expect($grams($this->w->kumasi))->toBe(2409.28)
        ->and($grams($this->w->central))->toBe(2190.13)
        ->and(Document::withoutGlobalScopes()->where('branch_id', $this->w->kumasi->id)->orderBy('id')->pluck('number')->all())->toBe(['PCH-KUM-000001', 'PCH-KUM-000002']);

    // Again: the same rows go to the same branches, so nothing is added twice.
    $this->artisan('adoma:import-sheet', ['--business' => 'Adoma', 'file' => $file, '--branch' => 'KUM', '--split' => 'CEN'])->assertSuccessful();
    expect(Document::withoutGlobalScopes()->count())->toBe(5);

    $this->artisan('adoma:import-sheet', ['--business' => 'Adoma', '--branch' => 'KUM', '--remove' => true, '--force' => true])->assertSuccessful();

    expect(Document::withoutGlobalScopes()->count())->toBe(0)
        ->and(NumberSequence::where('branch_id', $this->w->central->id)->where('type', 'purchase')->value('next_value'))->toBe(1)
        ->and(NumberSequence::where('branch_id', $this->w->kumasi->id)->where('type', 'purchase')->value('next_value'))->toBe(1);
});

it('will not split with a branch that does not exist', function () {
    $this->artisan('adoma:import-sheet', ['--business' => 'Adoma', 'file' => importSheetFile(importSheetRows()), '--branch' => 'KUM', '--split' => 'SUN'])
        ->expectsOutputToContain('There is no branch with the --split code')
        ->assertFailed();

    expect(Document::withoutGlobalScopes()->count())->toBe(0);
});
