<?php

use App\Domain\Documents\Document;
use App\Domain\Ledgers\ChartOfAccounts;
use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use App\Domain\Tenancy\Branch;
use Illuminate\Support\Str;
use Tests\Support\World;

// The ledger report: one account's entries for a period, a balance brought forward, a running balance, and totals.
//
// Bright is a supplier in Kumasi:
//   5 Mar  bought 2,094.25 g   GH₵ 2,795,837   (we owe Bright: a credit)
//   2 Apr  bought   599.15 g   GH₵   805,129   (a credit)

beforeEach(function () {
    $this->w = World::build();

    $this->bright = lrLedger($this->w->kumasi, 'Bright', LedgerKind::Supplier);
    $this->centralCustomer = lrLedger($this->w->central, 'Central Buyer', LedgerKind::Customer);

    $this->p1 = lrBuy($this, '2026-03-05', '2094.25', '114.56');
    $this->p2 = lrBuy($this, '2026-04-02', '599.15', '32.61');
});

function lrLedger(Branch $branch, string $name, LedgerKind $kind): Ledger
{
    return Ledger::create([
        'tenant_id' => $branch->tenant_id, 'branch_id' => $branch->id, 'is_shared' => false,
        'group_id' => ChartOfAccounts::groupFor($branch->tenant_id, $kind)->id, 'kind' => $kind, 'name' => $name,
    ]);
}

function lrBuy(object $t, string $date, string $weight, string $water): Document
{
    $t->actingAs($t->w->kumasiManager)->post(route('purchases.store'), [
        'date' => $date, 'ledger_id' => $t->bright->id, 'form' => 'raw', 'weight' => $weight, 'water_weight' => $water,
        'karat' => '', 'price' => '10600', 'note' => '', 'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();

    return Document::withoutGlobalScopes()->where('type', 'purchase')->latest('id')->firstOrFail();
}

function lrProps(object $t, array $query, $user = null): array
{
    return $t->actingAs($user ?? $t->w->kumasiClerk)->get(route('reports.ledger', $query))->assertOk()->viewData('page')['props'];
}

it('asks which ledger before showing anything', function () {
    $props = lrProps($this, ['from' => '2026-03-01', 'to' => '2026-03-31']);

    expect($props['title'])->toBe('Ledger report')->and($props['person'])->toBeNull()->and($props['tables'])->toBe([]);
});

it('lists the entries of the period after a balance brought forward, with a running balance and totals', function () {
    $props = lrProps($this, ['ledger_id' => $this->bright->id, 'from' => '2026-03-01', 'to' => '2026-03-31']);
    $table = $props['tables'][0];

    expect($props['person'])->toMatchArray(['id' => $this->bright->id, 'name' => 'Bright'])
        ->and(collect($table['columns'])->pluck('label')->all())->toBe(['Date', 'Number', 'Type', 'Note', 'Debit', 'Credit', 'Balance'])
        ->and($table['rows'])->toBe([
            ['', '', '', 'Balance brought forward', '', '', '0.00'],
            ['5 Mar 2026', $this->p1->number, 'Purchase', '', '', '2,795,837.00', '2,795,837.00 Cr'],
        ])
        ->and($table['totals'])->toBe(['Total', '', '', '', '0.00', '2,795,837.00', '2,795,837.00 Cr'])
        ->and(collect($props['stats'])->pluck('value', 'label')->all())->toBe([
            'Opening balance' => '0.00', 'Debits' => '0.00', 'Credits' => '2,795,837.00', 'Closing balance' => '2,795,837.00 Cr',
        ]);
});

it('carries everything before the period into the balance brought forward', function () {
    $props = lrProps($this, ['ledger_id' => $this->bright->id, 'from' => '2026-04-01', 'to' => '2026-04-30']);

    expect($props['tables'][0]['rows'])->toBe([
        ['', '', '', 'Balance brought forward', '', '', '2,795,837.00 Cr'],
        ['2 Apr 2026', $this->p2->number, 'Purchase', '', '', '805,129.00', '3,600,966.00 Cr'],
    ])->and(collect($props['stats'])->pluck('value', 'label')['Opening balance'])->toBe('2,795,837.00 Cr');
});

it('includes the first and last day of the period, and nothing outside it', function () {
    $numbers = fn (string $from, string $to) => collect(lrProps($this, ['ledger_id' => $this->bright->id, 'from' => $from, 'to' => $to])['tables'][0]['rows'])
        ->skip(1)->pluck(1)->all();

    expect($numbers('2026-03-05', '2026-03-05'))->toBe([$this->p1->number])
        ->and($numbers('2026-04-02', '2026-04-02'))->toBe([$this->p2->number])
        ->and($numbers('2026-03-06', '2026-04-01'))->toBe([])
        ->and($numbers('2026-03-01', '2026-04-30'))->toBe([$this->p1->number, $this->p2->number]);
});

it('shows an empty period as just the balance brought forward', function () {
    $props = lrProps($this, ['ledger_id' => $this->bright->id, 'from' => '2026-05-01', 'to' => '2026-05-31']);

    expect($props['tables'][0]['rows'])->toHaveCount(1)
        ->and($props['tables'][0]['totals'])->toBe(['Total', '', '', '', '0.00', '0.00', '3,600,966.00 Cr']);
});

it('does not show another branch\'s ledger', function () {
    $props = lrProps($this, ['ledger_id' => $this->centralCustomer->id, 'from' => '2026-03-01', 'to' => '2026-03-31']);

    expect($props['person'])->toBeNull()->and($props['tables'])->toBe([]);
});

it('ignores a ledger that does not exist', function () {
    $props = lrProps($this, ['ledger_id' => 999999, 'from' => '2026-03-01', 'to' => '2026-03-31']);

    expect($props['person'])->toBeNull()->and($props['tables'])->toBe([]);
});

it('downloads the same report for a spreadsheet', function () {
    $response = $this->actingAs($this->w->kumasiClerk)
        ->get(route('reports.ledger', ['ledger_id' => $this->bright->id, 'from' => '2026-03-01', 'to' => '2026-03-31', 'export' => 'csv']))
        ->assertOk();

    expect($response->headers->get('content-type'))->toStartWith('text/csv')
        ->and($response->streamedContent())->toContain('Bright')->toContain('Balance brought forward')->toContain($this->p1->number);
});

it('needs a signed-in user', function () {
    auth()->logout();

    $this->get(route('reports.ledger'))->assertRedirect(route('login'));
});
