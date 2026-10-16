<?php

use App\Domain\Audit\AuditLog;
use App\Domain\Documents\NumberSequence;
use App\Domain\Ledgers\Ledger;
use App\Domain\Tenancy\Branch;
use App\Domain\Tenancy\Role;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Tests\Support\World;

/*
 * Branches, and who works in each. Owners and admins see them and give people roles. Adding and editing a branch
 * is for an owner, or an admin the owner has allowed.
 */

beforeEach(function () {
    $this->w = World::build();
});

/** A valid "add branch" request. */
function newBranch(array $overrides = []): array
{
    return array_merge(['name' => 'Takoradi', 'code' => 'TAK', 'region' => 'Western', 'is_head_office' => false], $overrides);
}

/** Read without the request's scopes, as the test sees the database. */
function branchNamed(string $name): ?Branch
{
    return Branch::withoutGlobalScopes()->where('name', $name)->first();
}

function auditEntries(int $tenantId, string $event)
{
    return app(TenantContext::class)->run(
        App\Domain\Tenancy\Tenant::find($tenantId), null,
        fn () => AuditLog::where('event', $event)->orderBy('id')->get(),
        allBranches: true,
    );
}

/** Someone who works in Kumasi as a clerk and in Central as a viewer. */
function twoBranchClerk(World $w): User
{
    $user = User::factory()->make();
    $user->forceFill(['tenant_id' => $w->adoma->id])->save();
    $user->branches()->attach([$w->kumasi->id => ['role' => 'clerk'], $w->central->id => ['role' => 'viewer']]);

    return $user;
}

// ---------------------------------------------------------------- the list

it('shows the branches and how many people work in each, to owners and admins', function (string $who) {
    $this->actingAs($this->w->{$who})->get(route('branches.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings/Branches/Index')
            ->has('branches', 2)
            ->where('branches.0.name', 'Central')
            ->where('branches.0.is_head_office', true)
            ->where('branches.1.people', ['manager' => 1, 'clerk' => 1, 'viewer' => 1])
            ->has('admins', 2));
})->with(['owner', 'admin']);

it('keeps managers, clerks and viewers out of the branch screens', function (string $who) {
    $this->actingAs($this->w->{$who})->get(route('branches.index'))->assertForbidden();
    $this->actingAs($this->w->{$who})->get(route('branches.show', $this->w->kumasi))->assertForbidden();
})->with(['kumasiManager', 'kumasiClerk', 'kumasiViewer']);

it('never shows another business\'s branch', function () {
    $this->actingAs($this->w->owner)->get(route('branches.show', $this->w->otherBranch))->assertNotFound();
    $this->actingAs($this->w->owner)->get(route('branches.edit', $this->w->otherBranch))->assertNotFound();
    $this->actingAs($this->w->owner)->put(route('branches.access', $this->w->otherBranch), ['roles' => []])->assertNotFound();
});

// ---------------------------------------------------------------- adding a branch

it('lets the owner add a branch, with its own account and voucher numbers', function () {
    $this->actingAs($this->w->owner)->post(route('branches.store'), newBranch(['code' => 'tak ']))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('branches.show', branchNamed('Takoradi')));

    $branch = branchNamed('Takoradi');

    expect($branch->code)->toBe('TAK')
        ->and($branch->tenant_id)->toBe($this->w->adoma->id)
        ->and(Ledger::withoutGlobalScopes()->where('linked_branch_id', $branch->id)->value('name'))->toBe('Takoradi')
        ->and(NumberSequence::where('branch_id', $branch->id)->count())->toBeGreaterThan(0)
        ->and(auditEntries($this->w->adoma->id, 'created')->where('auditable_type', Branch::class)->last()->describe())->toBe('added branch “Takoradi”');

    // Owners reach it at once, as every branch.
    expect($this->w->owner->fresh()->accessibleBranches()->pluck('name'))->toContain('Takoradi');
});

it('checks the name and code of a new branch', function (array $input, string $field) {
    $this->actingAs($this->w->owner)->post(route('branches.store'), newBranch($input))->assertSessionHasErrors($field);
    expect(branchNamed('Takoradi'))->toBeNull();
})->with([
    'code too short' => [['code' => 'T'], 'code'],
    'code too long' => [['code' => 'TAKORA'], 'code'],
    'code with a sign' => [['code' => 'T-K'], 'code'],
    'code already used' => [['code' => 'KUM'], 'code'],
    'no name' => [['name' => ' '], 'name'],
]);

it('refuses a name another branch already has', function () {
    $this->actingAs($this->w->owner)->post(route('branches.store'), newBranch(['name' => 'Kumasi']))
        ->assertSessionHasErrors(['name' => 'Another branch already has this name.']);
});

it('lets another business use the same code', function () {
    Branch::withoutGlobalScopes()->create(['tenant_id' => $this->w->other->id, 'code' => 'TAK', 'name' => 'Their Takoradi']);

    $this->actingAs($this->w->owner)->post(route('branches.store'), newBranch())->assertSessionHasNoErrors();
});

it('keeps one head office: choosing a new one takes it from the old', function () {
    $this->actingAs($this->w->owner)->post(route('branches.store'), newBranch(['is_head_office' => true]))->assertSessionHasNoErrors();

    expect(branchNamed('Takoradi')->is_head_office)->toBeTrue()
        ->and($this->w->central->fresh()->is_head_office)->toBeFalse();
});

// ---------------------------------------------------------------- who may add and edit

it('lets an admin add branches only once the owner allows it', function () {
    $this->actingAs($this->w->admin)->get(route('branches.create'))->assertForbidden();
    $this->actingAs($this->w->admin)->post(route('branches.store'), newBranch())->assertForbidden();
    $this->actingAs($this->w->admin)->put(route('branches.update', $this->w->kumasi), ['name' => 'Kumasi Main'])->assertForbidden();

    // The owner allows it, on the admin's page.
    $admin = $this->w->admin;
    $this->actingAs($this->w->owner)->put(route('users.update', $admin->id), [
        'name' => $admin->name, 'email' => $admin->email, 'tenant_role' => 'admin', 'can_manage_branches' => true, 'offices' => [],
    ])->assertSessionHasNoErrors();

    expect($admin->fresh()->canManageBranches())->toBeTrue()
        ->and(auditEntries($this->w->adoma->id, 'updated')->where('auditable_type', User::class)->last()->describe())
        ->toBe("changed the permission to add and edit branches of user “{$admin->name}”");

    $this->actingAs($admin->fresh())->post(route('branches.store'), newBranch())->assertSessionHasNoErrors();
    expect(branchNamed('Takoradi'))->not->toBeNull();
});

it('lets only the owner allow an admin to add branches', function () {
    $other = User::factory()->make();
    $other->forceFill(['tenant_id' => $this->w->adoma->id, 'tenant_role' => 'admin'])->save();

    // An admin cannot allow another admin.
    $this->actingAs($this->w->admin)->put(route('users.update', $other->id), [
        'name' => $other->name, 'email' => $other->email, 'tenant_role' => 'admin', 'can_manage_branches' => true,
    ])->assertSessionHasErrors('can_manage_branches');

    // Nor give it to someone they may manage, by sending the field.
    $clerk = $this->w->kumasiClerk;
    $this->actingAs($this->w->admin)->put(route('users.update', $clerk->id), [
        'name' => $clerk->name, 'email' => $clerk->email, 'tenant_role' => null, 'can_manage_branches' => true,
        'offices' => [['branch_id' => $this->w->kumasi->id, 'role' => 'clerk']],
    ])->assertSessionHasErrors(['can_manage_branches' => 'Only an owner can let an admin add and edit branches.']);

    expect($other->fresh()->can_manage_branches)->toBeFalse()->and($clerk->fresh()->can_manage_branches)->toBeFalse();
});

it('takes the permission away from someone who stops being an admin', function () {
    $admin = $this->w->admin;
    $admin->forceFill(['can_manage_branches' => true])->save();

    $this->actingAs($this->w->owner)->put(route('users.update', $admin->id), [
        'name' => $admin->name, 'email' => $admin->email, 'tenant_role' => null, 'can_manage_branches' => true,
        'offices' => [['branch_id' => $this->w->kumasi->id, 'role' => 'manager']],
    ])->assertSessionHasNoErrors();

    expect($admin->fresh()->can_manage_branches)->toBeFalse()->and($admin->fresh()->canManageBranches())->toBeFalse();
});

// ---------------------------------------------------------------- editing a branch

it('renames a branch and its account, but never changes its code', function () {
    $this->actingAs($this->w->owner)->put(route('branches.update', $this->w->kumasi), ['name' => 'Kumasi Main', 'region' => 'Ashanti'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('branches.show', $this->w->kumasi));

    expect($this->w->kumasi->fresh()->name)->toBe('Kumasi Main')
        ->and(Ledger::withoutGlobalScopes()->where('linked_branch_id', $this->w->kumasi->id)->value('name'))->toBe('Kumasi Main');

    $this->actingAs($this->w->owner)->put(route('branches.update', $this->w->kumasi), ['name' => 'Kumasi Main', 'code' => 'KMS'])
        ->assertSessionHasErrors('code');
    expect($this->w->kumasi->fresh()->code)->toBe('KUM');
});

it('moves the head office when another branch is made head office', function () {
    $this->actingAs($this->w->owner)->put(route('branches.update', $this->w->kumasi), ['name' => 'Kumasi', 'is_head_office' => true])
        ->assertSessionHasNoErrors();

    expect($this->w->kumasi->fresh()->is_head_office)->toBeTrue()->and($this->w->central->fresh()->is_head_office)->toBeFalse();
});

// ---------------------------------------------------------------- people and roles in a branch

it('lists every member of staff with their role in this branch', function () {
    $this->actingAs($this->w->admin)->get(route('branches.show', $this->w->kumasi))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings/Branches/Show')
            ->where('branch.code', 'KUM')
            ->has('staff', 4)
            ->where('staff', fn ($staff) => collect($staff)->firstWhere('id', $this->w->kumasiClerk->id)['role'] === 'clerk'
                && collect($staff)->firstWhere('id', $this->w->centralManager->id)['role'] === null
                && collect($staff)->firstWhere('id', $this->w->kumasiClerk->id)['only_here'] === true)
            ->has('admins', 2)
            ->where('canEdit', false));
});

it('gives, changes and takes away roles in this branch only', function () {
    $both = twoBranchClerk($this->w);

    $this->actingAs($this->w->admin)->put(route('branches.access', $this->w->kumasi), ['roles' => [
        $this->w->centralManager->id => 'viewer',   // given
        $this->w->kumasiViewer->id => 'manager',    // changed
        $both->id => null,                         // taken away here; Central stays
    ]])->assertSessionHasNoErrors()->assertRedirect(route('branches.show', $this->w->kumasi));

    expect($this->w->centralManager->fresh()->roleIn($this->w->kumasi))->toBe(Role::Viewer)
        ->and($this->w->centralManager->fresh()->roleIn($this->w->central))->toBe(Role::Manager)
        ->and($this->w->kumasiViewer->fresh()->roleIn($this->w->kumasi))->toBe(Role::Manager)
        ->and($both->fresh()->roleIn($this->w->kumasi))->toBeNull()
        ->and($both->fresh()->roleIn($this->w->central))->toBe(Role::Viewer);

    $log = auditEntries($this->w->adoma->id, 'access_changed')->firstWhere('auditable_id', $both->id);
    expect($log->old_values)->toBe(['offices' => ['Central' => 'viewer', 'Kumasi' => 'clerk']])
        ->and($log->new_values)->toBe(['offices' => ['Central' => 'viewer']])
        ->and($log->actor_name)->toBe($this->w->admin->name);
});

it('will not take away someone\'s only branch, and changes nothing when it refuses', function () {
    $this->actingAs($this->w->owner)->put(route('branches.access', $this->w->kumasi), ['roles' => [
        $this->w->kumasiViewer->id => 'clerk',
        $this->w->kumasiClerk->id => null,
    ]])->assertSessionHasErrors(["roles.{$this->w->kumasiClerk->id}" => "Kumasi is {$this->w->kumasiClerk->name}'s only branch. Give them another branch first, or switch their account off."]);

    expect($this->w->kumasiClerk->fresh()->roleIn($this->w->kumasi))->toBe(Role::Clerk)
        ->and($this->w->kumasiViewer->fresh()->roleIn($this->w->kumasi))->toBe(Role::Viewer);
});

it('leaves owners and admins alone: they work in every branch', function () {
    $this->actingAs($this->w->owner)->put(route('branches.access', $this->w->kumasi), ['roles' => [$this->w->admin->id => 'viewer']])
        ->assertSessionHasErrors("roles.{$this->w->admin->id}");

    expect($this->w->admin->fresh()->branches()->count())->toBe(0);
});

it('only accepts manager, clerk or viewer, and ignores people of another business', function () {
    $this->actingAs($this->w->owner)->put(route('branches.access', $this->w->kumasi), ['roles' => [$this->w->centralManager->id => 'owner']])
        ->assertSessionHasErrors("roles.{$this->w->centralManager->id}");

    $this->actingAs($this->w->owner)->put(route('branches.access', $this->w->kumasi), ['roles' => [$this->w->otherAdmin->id => 'clerk']])
        ->assertSessionHasNoErrors();
    expect($this->w->otherAdmin->fresh()->branches()->count())->toBe(0);
});

it('keeps managers and clerks from giving roles', function () {
    $this->actingAs($this->w->kumasiManager)->put(route('branches.access', $this->w->kumasi), ['roles' => [$this->w->centralManager->id => 'clerk']])
        ->assertForbidden();
});

it('lets a new branch be worked in once someone is given a role there', function () {
    $this->actingAs($this->w->owner)->post(route('branches.store'), newBranch());
    $branch = branchNamed('Takoradi');

    $this->actingAs($this->w->owner)->put(route('branches.access', $branch), ['roles' => [$this->w->centralManager->id => 'clerk']])
        ->assertSessionHasNoErrors();

    $manager = $this->w->centralManager->fresh();
    expect($manager->accessibleBranches()->pluck('name')->all())->toBe(['Central', 'Takoradi']);

    $this->actingAs($manager)->post(route('branch.switch'), ['branch' => $branch->id])->assertSessionHas('active_branch_id', $branch->id);
});
