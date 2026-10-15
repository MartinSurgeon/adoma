<?php

use App\Domain\Documents\Document;
use App\Domain\Documents\JournalLine;
use App\Domain\Documents\PostingError;
use App\Domain\Documents\PostingService;
use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use App\Domain\Stock\StockMovement;
use App\Domain\Support\Money;
use App\Domain\Tenancy\Branch;
use App\Domain\Transfers\PendingTransfers;
use Illuminate\Support\Str;
use Tests\Support\World;

// A transfer between branches is a sale: priced per pound, with a profit or loss for the sender and a price owed
// by the receiver. These use the helpers in TransferTest.php, which every run of the suite loads.

beforeEach(function () {
    $this->w = World::build();
    $this->supplier = trLedger($this->w->kumasi, 'Bright', LedgerKind::Supplier);
});

function tpNet(object $t, string $systemKey, Branch $branch): int
{
    $ledger = Ledger::withoutGlobalScopes()->where('tenant_id', $t->w->adoma->id)->where('system_key', $systemKey)->firstOrFail();
    $lines = JournalLine::withoutGlobalScopes()->where('ledger_id', $ledger->id)->where('branch_id', $branch->id)->get();

    return Money::fromDb($lines->sum('debit')) - Money::fromDb($lines->sum('credit'));
}

function tpCost(Document $out): int
{
    return abs(Money::fromDb(StockMovement::withoutGlobalScopes()->where('document_id', $out->id)->sum('cost')));
}

// ------------------------------------------------------------------------- the price

it('needs a price, and says in words what is wrong with one', function (mixed $price, string $message) {
    trBuy($this);

    $form = trSendForm($this);

    if ($price === null) {
        unset($form['price']);
    } else {
        $form['price'] = $price;
    }

    $this->actingAs($this->w->kumasiManager)->post(route('transfers.store'), $form)->assertSessionHasErrors(['price' => $message]);

    expect(Document::withoutGlobalScopes()->where('type', 'transfer')->count())->toBe(0);
})->with([
    'missing' => [null, 'Enter the price per pound the gold is being sold to the other branch at.'],
    'zero' => ['0', 'The price must be more than zero.'],
    'text' => ['cheap', 'Enter the price as a number, for example 10600.'],
    'a typing mistake' => ['1000000.01', 'The price is more than 1,000,000, which looks like a typing mistake.'],
]);

it('refuses to post a transfer without a price, even called directly', function () {
    trBuy($this);
    $this->actingAs($this->w->kumasiManager);

    expect(fn () => app(PostingService::class)->postTransfer($this->w->kumasi, $this->w->central, now()->toDateString(), 100000, 0, null, null, $this->w->kumasiManager))
        ->toThrow(PostingError::class, 'Enter the price per pound');
});

it('tells the form what price it is quoted at, and the base karat', function () {
    trBuy($this);

    $props = trProps($this->actingAs($this->w->kumasiManager)->get(route('transfers.create'))->assertOk());

    expect($props['baseKarat'])->toBe(23);
});

it('quotes the amount and the profit while a price is typed, and only the cost before it', function () {
    trBuy($this);
    $this->actingAs($this->w->kumasiManager);

    $withoutPrice = $this->postJson(route('transfers.quote'), ['weight' => '1000'])->json();
    $withPrice = $this->postJson(route('transfers.quote'), ['weight' => '1000', 'price' => '12000'])->json();
    $loss = $this->postJson(route('transfers.quote'), ['weight' => '1000', 'price' => '1000'])->json();
    $bad = $this->postJson(route('transfers.quote'), ['weight' => '1000', 'price' => 'free'])->json();

    expect($withoutPrice['amount'])->toBeNull()->and($withoutPrice['cost'])->toStartWith('GH₵ ')
        ->and($withPrice['amount'])->toBe('GH₵ 1,511,334.00')->and($withPrice['is_loss'])->toBeFalse()
        ->and($loss['is_loss'])->toBeTrue()
        ->and($bad)->toMatchArray(['ok' => false, 'field' => 'price']);
});

// -------------------------------------------------------------------------- profit and loss

it('shows the sending branch its profit on a transfer, to managers only', function () {
    trBuy($this);
    $out = trSend($this, ['weight' => '1000.00', 'price' => '12000'], accept: false);

    $manager = trProps($this->actingAs($this->w->kumasiManager)->get(route('documents.show', $out->id))->assertOk());
    $clerk = trProps($this->actingAs($this->w->kumasiClerk)->get(route('documents.show', $out->id))->assertOk());

    $cost = tpCost($out);
    $amount = Money::fromDb($out->total);

    expect($manager['profit'])->toBe([
        'cost' => Money::cedis($cost), 'profit' => Money::cedis($amount - $cost), 'is_loss' => false,
    ])->and($clerk['profit'])->toBeNull();
});

it('treats a transfer priced below cost as a loss, and still balances', function () {
    trBuy($this);
    $out = trSend($this, ['weight' => '1000.00', 'price' => '1000'], accept: false);

    $page = trProps($this->actingAs($this->w->kumasiManager)->get(route('documents.show', $out->id))->assertOk());

    expect($page['profit']['is_loss'])->toBeTrue()
        ->and(Money::fromDb($out->total))->toBeLessThan(tpCost($out))
        ->and(trBranchBalance($out)[0])->toBe(trBranchBalance($out)[1]);
});

it('keeps the sender\'s cost out of the receiving branch\'s view of the transfer', function () {
    trBuy($this);
    $out = trSend($this, ['weight' => '1000.00', 'price' => '12000']);

    $page = trProps($this->actingAs($this->w->centralManager)->get(route('documents.show', trIn($out)->id))->assertOk());

    expect($page['profit'])->toBeNull()
        ->and($page['document']['total'])->toBe('GH₵ 1,511,334.00')
        ->and(json_encode($page))->not->toContain((string) tpCost($out));
});

// ------------------------------------------------------------------------ putting it right

it('undoes the sale on both sides when the sending branch reverses an accepted transfer', function () {
    $purchase = trBuy($this);
    $out = trSend($this, ['weight' => '1000.00', 'price' => '12000']);

    $this->actingAs($this->w->kumasiManager)->post(route('documents.reverse', $out->id), ['reason' => 'Wrong price'])->assertSessionHasNoErrors();

    expect(tpNet($this, 'sales', $this->w->kumasi))->toBe(0)
        ->and(tpNet($this, 'cost_of_sales', $this->w->kumasi))->toBe(0)
        ->and(trStockBalance($this, $this->w->kumasi))->toBe(Money::fromDb($purchase->total))
        ->and(trStockBalance($this, $this->w->central))->toBe(0);
});

it('undoes the sale when the receiving branch declines, with nothing left in sales', function () {
    $purchase = trBuy($this);
    $out = trSend($this, ['weight' => '1000.00', 'price' => '12000'], accept: false);

    $this->actingAs($this->w->centralManager)->post(route('transfers.decline', $out->id), ['reason' => 'Wrong weight'])->assertSessionHasNoErrors();

    expect(tpNet($this, 'sales', $this->w->kumasi))->toBe(0)
        ->and(tpNet($this, 'cost_of_sales', $this->w->kumasi))->toBe(0)
        ->and(trStockBalance($this, $this->w->kumasi))->toBe(Money::fromDb($purchase->total));
});

// ---------------------------------------------------------------- transfers made before pricing

it('still accepts a waiting transfer that was sent before transfers had a price, at its cost', function () {
    trBuy($this);
    $out = trSend($this, ['weight' => '1000.00', 'price' => '12000'], accept: false);

    // Make it look like one sent in the old way: no price on its slices.
    Illuminate\Support\Facades\DB::table('stock_movements')->where('document_id', $out->id)->update(['value' => null]);

    $figures = PendingTransfers::figures([$out])[$out->id];
    expect($figures['valueCents'])->toBe($figures['costCents']);

    trAccept($this, $out);

    $in = trIn($out);

    expect(Money::fromDb($in->total))->toBe($figures['costCents'])
        ->and(trStockBalance($this, $this->w->central))->toBe($figures['costCents'])
        ->and(trBranchBalance($in)[0])->toBe(trBranchBalance($in)[1]);
});
