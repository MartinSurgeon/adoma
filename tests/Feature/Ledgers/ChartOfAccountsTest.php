<?php

use App\Domain\Ledgers\ChartOfAccounts;
use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerGroup;
use App\Domain\Ledgers\LedgerKind;
use App\Domain\Tenancy\Branch;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use Tests\Support\World;

beforeEach(function () {
    $this->w = World::build();
});

function group(int $tenantId, string $key): LedgerGroup
{
    return LedgerGroup::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('key', $key)->firstOrFail();
}

it('gives every new business the standard chart of accounts', function () {
    $tenantId = $this->w->adoma->id;

    foreach (['debtors', 'creditors', 'cash_bank', 'gold_stock', 'sales', 'cost_of_sales', 'operating_expenses', 'inter_office', 'prepaid', 'accruals', 'capital'] as $key) {
        expect(LedgerGroup::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('key', $key)->exists())->toBeTrue("missing group $key");
    }

    // Relations are scoped like everything else, so they need the business in context.
    app(TenantContext::class)->set($this->w->adoma, null, allBranches: true);

    expect(group($tenantId, 'debtors')->nature)->toBe('asset')
        ->and(group($tenantId, 'creditors')->nature)->toBe('liability')
        ->and(group($tenantId, 'sales')->nature)->toBe('income')
        ->and(group($tenantId, 'operating_expenses')->nature)->toBe('expense')
        ->and(group($tenantId, 'capital')->nature)->toBe('equity')
        ->and(group($tenantId, 'debtors')->parent->key)->toBe('current_assets')
        ->and(group($tenantId, 'debtors')->path())->toBe('Current Assets / Debtors');
});

it('adds the system accounts that posting will use', function () {
    foreach (['gold_stock', 'sales', 'cost_of_sales'] as $key) {
        $ledger = Ledger::withoutGlobalScopes()->where('tenant_id', $this->w->adoma->id)->where('system_key', $key)->firstOrFail();

        expect($ledger->is_shared)->toBeTrue()
            ->and($ledger->is_system)->toBeTrue()
            ->and($ledger->branch_id)->toBeNull()
            ->and($ledger->kind)->toBe(LedgerKind::Account);
    }
});

it('creates an account for each office', function () {
    app(TenantContext::class)->set($this->w->adoma, null, allBranches: true);
    $ledgers = Ledger::where('kind', 'branch')->get();

    expect($ledgers->pluck('name')->sort()->values()->all())->toBe(['Central', 'Kumasi'])
        ->and($ledgers->pluck('linked_branch_id')->sort()->values()->all())->toBe([$this->w->central->id, $this->w->kumasi->id])
        ->and($ledgers->every(fn ($l) => $l->is_shared && $l->is_system && $l->branch_id === null))->toBeTrue()
        ->and($ledgers->first()->group->key)->toBe('inter_office');
});

it('creates an account for an office added later', function () {
    $branch = Branch::create(['tenant_id' => $this->w->adoma->id, 'code' => 'TAM', 'name' => 'Tamale']);

    $ledger = Ledger::withoutGlobalScopes()->where('linked_branch_id', $branch->id)->firstOrFail();

    expect($ledger->name)->toBe('Tamale')->and($ledger->tenant_id)->toBe($this->w->adoma->id);
});

it('renames the office account when the office is renamed', function () {
    $this->w->kumasi->update(['name' => 'Kumasi Central Market']);

    expect(Ledger::withoutGlobalScopes()->where('linked_branch_id', $this->w->kumasi->id)->value('name'))->toBe('Kumasi Central Market');
});

it('is safe to run again', function () {
    $before = [LedgerGroup::withoutGlobalScopes()->count(), Ledger::withoutGlobalScopes()->count()];

    ChartOfAccounts::seedFor($this->w->adoma);
    ChartOfAccounts::seedFor($this->w->adoma);
    ChartOfAccounts::ensureBranchLedger($this->w->kumasi);

    expect([LedgerGroup::withoutGlobalScopes()->count(), Ledger::withoutGlobalScopes()->count()])->toBe($before);
});

it('builds the missing chart for a business that was created before it existed', function () {
    LedgerGroup::withoutGlobalScopes()->where('tenant_id', $this->w->other->id)->update(['parent_id' => null]);
    Ledger::withoutGlobalScopes()->where('tenant_id', $this->w->other->id)->delete();
    LedgerGroup::withoutGlobalScopes()->where('tenant_id', $this->w->other->id)->delete();

    ChartOfAccounts::seedFor($this->w->other);

    expect(group($this->w->other->id, 'debtors')->exists)->toBeTrue()
        ->and(Ledger::withoutGlobalScopes()->where('tenant_id', $this->w->other->id)->where('kind', 'branch')->count())->toBe(1);
});

it('keeps each business\'s chart separate', function () {
    expect(group($this->w->adoma->id, 'debtors')->id)->not->toBe(group($this->w->other->id, 'debtors')->id)
        ->and(Ledger::withoutGlobalScopes()->where('tenant_id', $this->w->other->id)->where('kind', 'branch')->pluck('name')->all())->toBe(['Main']);
});

it('maps each kind of ledger to a group', function () {
    $tenantId = $this->w->adoma->id;

    expect(ChartOfAccounts::groupFor($tenantId, LedgerKind::Customer)->key)->toBe('debtors')
        ->and(ChartOfAccounts::groupFor($tenantId, LedgerKind::Supplier)->key)->toBe('creditors')
        ->and(ChartOfAccounts::groupFor($tenantId, LedgerKind::Bank)->key)->toBe('cash_bank')
        ->and(ChartOfAccounts::groupFor($tenantId, LedgerKind::Cash)->key)->toBe('cash_bank')
        ->and(ChartOfAccounts::groupFor($tenantId, LedgerKind::Expense)->key)->toBe('operating_expenses')
        ->and(ChartOfAccounts::groupFor($tenantId, LedgerKind::Account))->toBeNull();
});

it('does not touch another business when it is seeded', function () {
    $count = LedgerGroup::withoutGlobalScopes()->where('tenant_id', $this->w->other->id)->count();

    $new = Tenant::create(['name' => 'Third Co']);

    expect(LedgerGroup::withoutGlobalScopes()->where('tenant_id', $this->w->other->id)->count())->toBe($count)
        ->and(LedgerGroup::withoutGlobalScopes()->where('tenant_id', $new->id)->count())->toBe($count);
});
