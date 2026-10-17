<?php

use App\Domain\Documents\NumberSequence;
use App\Domain\Gold\FormulaSet;
use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use App\Domain\Tenancy\Branch;
use App\Domain\Tenancy\Tenant;
use App\Models\User;

/*
 * The first run on a new server: php artisan adoma:setup makes the business, its branches and the owner,
 * and never a second business.
 */

function setUpAdoma(array $overrides = []): Illuminate\Testing\PendingCommand
{
    return test()->artisan('adoma:setup', array_merge([
        '--business' => 'Passion Gold Ltd',
        '--branch' => ['Central:CEN', 'Kumasi:kum'],
        '--owner-name' => 'Ama Owusu',
        '--owner-email' => 'Ama@Passion.test',
    ], $overrides));
}

it('creates the business, its branches with their accounts, and the owner', function () {
    setUpAdoma()
        ->expectsOutputToContain('Passion Gold Ltd is set up, with 2 branches.')
        ->expectsOutputToContain('ama@passion.test')
        ->assertSuccessful();

    $tenant = Tenant::sole();
    $branches = Branch::withoutGlobalScopes()->orderBy('id')->get();

    expect($tenant->name)->toBe('Passion Gold Ltd')
        ->and($tenant->isActive())->toBeTrue()
        ->and($branches->pluck('code')->all())->toBe(['CEN', 'KUM'])
        ->and($branches->pluck('is_head_office')->all())->toBe([true, false])
        ->and(FormulaSet::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1)
        ->and(NumberSequence::where('branch_id', $branches[1]->id)->count())->toBeGreaterThan(0)
        ->and(Ledger::withoutGlobalScopes()->where('kind', LedgerKind::Cash)->pluck('name')->sort()->values()->all())->toBe(['Cash Central', 'Cash Kumasi']);

    $owner = User::where('email', 'ama@passion.test')->sole();
    expect($owner->tenant_role)->toBe('owner')
        ->and($owner->must_change_password)->toBeTrue()
        ->and($owner->accessibleBranches()->pluck('name')->all())->toBe(['Central', 'Kumasi']);
});

it('lets the owner sign in with the temporary password, straight to choosing their own', function () {
    setUpAdoma()->assertSuccessful();

    // The real password is only ever on screen, so give the account a known one, still marked temporary.
    $owner = User::where('email', 'ama@passion.test')->sole();
    $owner->forceFill(['password' => 'Temporary-123'])->save();

    $this->post(route('login'), ['email' => 'ama@passion.test', 'password' => 'Temporary-123'])
        ->assertRedirect();
    $this->get(route('dashboard'))->assertRedirect(route('password.force.edit'));
});

it('refuses to make a second business', function () {
    setUpAdoma()->assertSuccessful();

    setUpAdoma(['--business' => 'Another', '--owner-email' => 'other@passion.test'])
        ->expectsOutputToContain('This server already has a business.')
        ->assertFailed();

    expect(Tenant::count())->toBe(1);
});

it('checks everything before writing anything', function (array $overrides, string $message) {
    setUpAdoma($overrides)->expectsOutputToContain($message)->assertFailed();

    expect(Tenant::count())->toBe(0)->and(Branch::withoutGlobalScopes()->count())->toBe(0)->and(User::count())->toBe(0);
})->with([
    'a bad code' => [['--branch' => ['Central:C']], 'must be 2 to 5 letters or numbers'],
    'the same code twice' => [['--branch' => ['Central:CEN', 'Kumasi:CEN']], 'Two branches have the same code.'],
    'the same name twice' => [['--branch' => ['Central:CEN', 'central:KUM']], 'Two branches have the same name.'],
    'a bad email' => [['--owner-email' => 'not-an-email'], 'email'],
]);
