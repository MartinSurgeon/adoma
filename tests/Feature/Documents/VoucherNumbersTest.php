<?php

use App\Domain\Documents\DocumentType;
use App\Domain\Documents\NumberSequence;
use App\Domain\Documents\VoucherNumbers;
use App\Domain\Tenancy\Branch;
use App\Domain\Tenancy\TenancyViolation;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\World;

beforeEach(function () {
    $this->w = World::build();
});

it('gives every office a counter for every document type', function () {
    foreach ([$this->w->central, $this->w->kumasi, $this->w->otherBranch] as $branch) {
        expect(NumberSequence::where('branch_id', $branch->id)->count())->toBe(count(DocumentType::cases()));
    }
});

it('numbers documents one after another with the type, office and a running number', function () {
    $numbers = [
        VoucherNumbers::next($this->w->kumasi, DocumentType::Purchase),
        VoucherNumbers::next($this->w->kumasi, DocumentType::Purchase),
        VoucherNumbers::next($this->w->kumasi, DocumentType::Purchase),
    ];

    expect($numbers)->toBe(['PCH-KUM-000001', 'PCH-KUM-000002', 'PCH-KUM-000003']);
});

it('uses the right letters for each type', function () {
    $letters = collect(DocumentType::cases())
        ->mapWithKeys(fn ($type) => [$type->value => explode('-', VoucherNumbers::next($this->w->kumasi, $type))[0]])
        ->all();

    expect($letters)->toBe([
        'purchase' => 'PCH', 'sale' => 'SAL', 'receipt' => 'REC',
        'payment' => 'PV', 'transfer' => 'TRF', 'journal' => 'JNL',
    ]);
});

it('counts each office and each type separately', function () {
    VoucherNumbers::next($this->w->kumasi, DocumentType::Sale);
    VoucherNumbers::next($this->w->kumasi, DocumentType::Sale);

    expect(VoucherNumbers::next($this->w->central, DocumentType::Sale))->toBe('SAL-CEN-000001')
        ->and(VoucherNumbers::next($this->w->kumasi, DocumentType::Purchase))->toBe('PCH-KUM-000001')
        ->and(VoucherNumbers::next($this->w->kumasi, DocumentType::Sale))->toBe('SAL-KUM-000003');
});

it('never repeats a number, however many are issued', function () {
    $numbers = collect(range(1, 200))->map(fn () => VoucherNumbers::next($this->w->kumasi, DocumentType::Receipt));

    expect($numbers->unique()->count())->toBe(200)
        ->and($numbers->first())->toBe('REC-KUM-000001')
        ->and($numbers->last())->toBe('REC-KUM-000200');
});

it('gives the number back when the posting it belongs to fails, so there are no gaps', function () {
    VoucherNumbers::next($this->w->kumasi, DocumentType::Purchase); // 1

    try {
        DB::transaction(function () {
            VoucherNumbers::next($this->w->kumasi, DocumentType::Purchase); // 2, inside a posting
            throw new RuntimeException('the posting failed');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(VoucherNumbers::next($this->w->kumasi, DocumentType::Purchase))->toBe('PCH-KUM-000002');
});

it('keeps a number when its posting succeeds', function () {
    DB::transaction(fn () => VoucherNumbers::next($this->w->kumasi, DocumentType::Purchase));

    expect(VoucherNumbers::next($this->w->kumasi, DocumentType::Purchase))->toBe('PCH-KUM-000002');
});

it('builds a missing counter the first time it is needed', function () {
    NumberSequence::where('branch_id', $this->w->kumasi->id)->delete();

    expect(VoucherNumbers::next($this->w->kumasi, DocumentType::Payment))->toBe('PV-KUM-000001')
        ->and(VoucherNumbers::next($this->w->kumasi, DocumentType::Payment))->toBe('PV-KUM-000002');
});

it('can be asked to make the counters again without losing its place', function () {
    VoucherNumbers::next($this->w->kumasi, DocumentType::Purchase);

    VoucherNumbers::ensureFor($this->w->kumasi);

    expect(VoucherNumbers::next($this->w->kumasi, DocumentType::Purchase))->toBe('PCH-KUM-000002')
        ->and(NumberSequence::where('branch_id', $this->w->kumasi->id)->count())->toBe(count(DocumentType::cases()));
});

it('numbers a new office from one', function () {
    $branch = Branch::create(['tenant_id' => $this->w->adoma->id, 'code' => 'TAM', 'name' => 'Tamale']);

    expect(VoucherNumbers::next($branch, DocumentType::Sale))->toBe('SAL-TAM-000001');
});

it('lets a transfer be numbered in the other office of the same business', function () {
    app(TenantContext::class)->set($this->w->adoma, $this->w->kumasi);

    expect(VoucherNumbers::next($this->w->central, DocumentType::Transfer))->toBe('TRF-CEN-000001');
});

it('refuses to number a document for another business', function () {
    app(TenantContext::class)->set($this->w->adoma, $this->w->kumasi);

    VoucherNumbers::next($this->w->otherBranch, DocumentType::Sale);
})->throws(TenancyViolation::class);

it('lets the database refuse a second counter for the same office and type', function () {
    expect(fn () => NumberSequence::create([
        'tenant_id' => $this->w->adoma->id, 'branch_id' => $this->w->kumasi->id, 'type' => 'sale', 'next_value' => 1,
    ]))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});
