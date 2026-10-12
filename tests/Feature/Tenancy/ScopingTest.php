<?php

use App\Domain\Tenancy\TenancyViolation;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ScopeProbe;
use Tests\Support\World;

beforeEach(function () {
    Schema::create('scope_probes', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('tenant_id');
        $table->unsignedBigInteger('branch_id');
        $table->string('name');
    });

    $this->w = World::build();
    $this->w->seedProbes();
    $this->ctx = app(TenantContext::class);
});

function names(): array
{
    return ScopeProbe::orderBy('id')->pluck('name')->all();
}

// ---------------------------------------------------------------- model scopes

it('returns nothing when no business is in context', function () {
    expect(ScopeProbe::count())->toBe(0);
});

it('shows only the active office of the active business', function () {
    $this->ctx->set($this->w->adoma, $this->w->kumasi);

    expect(names())->toBe(['kumasi-a', 'kumasi-b']);
});

it('hides another office of the same business', function () {
    $this->ctx->set($this->w->adoma, $this->w->central);

    expect(names())->not->toContain('kumasi-a')->toBe(['central-a', 'central-b']);
});

it('lets all-offices view see every office of one business and nothing else', function () {
    $this->ctx->set($this->w->adoma, null, allBranches: true);

    expect(names())->toBe(['central-a', 'central-b', 'kumasi-a', 'kumasi-b'])
        ->and(names())->not->toContain('other-a');
});

it('keeps another business out even when its office id is used', function () {
    // Adoma's context, Other Co's office id: nothing may match.
    $this->ctx->set($this->w->adoma, $this->w->kumasi);

    expect(ScopeProbe::where('branch_id', $this->w->otherBranch->id)->count())->toBe(0);
});

it('refuses an office that belongs to a different business', function () {
    $this->ctx->set($this->w->adoma, $this->w->otherBranch);
})->throws(TenancyViolation::class);

it('restores the previous context after run()', function () {
    $this->ctx->set($this->w->adoma, $this->w->central);

    $inside = $this->ctx->run($this->w->adoma, $this->w->kumasi, fn () => names());

    expect($inside)->toBe(['kumasi-a', 'kumasi-b'])
        ->and(names())->toBe(['central-a', 'central-b']);
});

// -------------------------------------------------------------------- writing

it('stamps the business and office on new records', function () {
    $this->ctx->set($this->w->adoma, $this->w->kumasi);

    $row = ScopeProbe::create(['name' => 'new']);

    expect($row->tenant_id)->toBe($this->w->adoma->id)
        ->and($row->branch_id)->toBe($this->w->kumasi->id);
});

it('refuses to create a record with no business in context', function () {
    ScopeProbe::create(['name' => 'orphan']);
})->throws(TenancyViolation::class);

it('refuses to create a record for a different business', function () {
    $this->ctx->set($this->w->adoma, $this->w->kumasi);

    ScopeProbe::create(['name' => 'x', 'tenant_id' => $this->w->other->id, 'branch_id' => $this->w->otherBranch->id]);
})->throws(TenancyViolation::class);

it('refuses to write into another office of the same business', function () {
    $this->ctx->set($this->w->adoma, $this->w->kumasi);

    ScopeProbe::create(['name' => 'x', 'branch_id' => $this->w->central->id]);
})->throws(TenancyViolation::class);

it('lets an admin in all-offices view write to a named office of their business', function () {
    $this->ctx->set($this->w->adoma, null, allBranches: true);

    $row = ScopeProbe::create(['name' => 'x', 'branch_id' => $this->w->central->id]);

    expect($row->branch_id)->toBe($this->w->central->id);
});

it('stops an admin in all-offices view writing into another business office', function () {
    $this->ctx->set($this->w->adoma, null, allBranches: true);

    ScopeProbe::create(['name' => 'x', 'branch_id' => $this->w->otherBranch->id]);
})->throws(TenancyViolation::class);

it('asks for an office when an admin saves in all-offices view without choosing one', function () {
    $this->ctx->set($this->w->adoma, null, allBranches: true);

    ScopeProbe::create(['name' => 'x']);
})->throws(TenancyViolation::class);

// ------------------------------------------------------------ users and access

it('gives owners every office and clerks only their own', function () {
    expect($this->w->owner->accessibleBranches()->pluck('code')->all())->toBe(['CEN', 'KUM'])
        ->and($this->w->kumasiClerk->accessibleBranches()->pluck('code')->all())->toBe(['KUM'])
        ->and($this->w->otherAdmin->accessibleBranches()->pluck('code')->all())->toBe(['MAIN']);
});

it('reports the role a user holds in each office', function () {
    expect($this->w->kumasiClerk->roleIn($this->w->kumasi)?->value)->toBe('clerk')
        ->and($this->w->kumasiClerk->roleIn($this->w->central))->toBeNull()
        ->and($this->w->owner->roleIn($this->w->central)?->value)->toBe('owner');
});

it('cannot make a user an admin through mass assignment', function () {
    $user = new App\Models\User();
    $user->fill(['name' => 'x', 'email' => 'x@x.test', 'tenant_role' => 'owner', 'tenant_id' => 1]);

    expect($user->tenant_role)->toBeNull()->and($user->tenant_id)->toBeNull();
});

// ------------------------------------------------------ middleware and requests

function probeRoute(): void
{
    Route::middleware('web')->get('/_probe', fn () => names());
}

it('scopes a request to the clerk\'s own office by default', function () {
    probeRoute();

    $this->actingAs($this->w->kumasiClerk)->getJson('/_probe')
        ->assertOk()->assertExactJson(['kumasi-a', 'kumasi-b']);
});

it('rejects an office header for an office the user does not belong to', function () {
    probeRoute();

    $this->actingAs($this->w->kumasiClerk)
        ->withHeader('X-Branch-Id', (string) $this->w->central->id)
        ->getJson('/_probe')
        ->assertForbidden()
        ->assertJson(['message' => 'You do not have access to this office.']);
});

it('rejects another business office id in the header', function () {
    probeRoute();

    $this->actingAs($this->w->owner)
        ->withHeader('X-Branch-Id', (string) $this->w->otherBranch->id)
        ->getJson('/_probe')
        ->assertForbidden()
        ->assertJson(['message' => 'You do not have access to this office.']);
});

it('lets an owner pick an office with the header', function () {
    probeRoute();

    $this->actingAs($this->w->owner)
        ->withHeader('X-Branch-Id', (string) $this->w->kumasi->id)
        ->getJson('/_probe')->assertOk()->assertExactJson(['kumasi-a', 'kumasi-b']);
});

it('lets an owner view all offices but never another business', function () {
    probeRoute();

    $this->actingAs($this->w->owner)
        ->withHeader('X-Branch-Id', 'all')
        ->getJson('/_probe')->assertOk()
        ->assertExactJson(['central-a', 'central-b', 'kumasi-a', 'kumasi-b']);
});

it('does not let a clerk view all offices', function () {
    probeRoute();

    $this->actingAs($this->w->kumasiClerk)
        ->withHeader('X-Branch-Id', 'all')
        ->getJson('/_probe')
        ->assertForbidden()
        ->assertJson(['message' => 'Only admins can view all offices.']);
});

it('blocks a switched-off user', function () {
    probeRoute();
    $this->w->kumasiClerk->forceFill(['is_active' => false])->save();

    $this->actingAs($this->w->kumasiClerk)->getJson('/_probe')
        ->assertForbidden()
        ->assertJson(['message' => 'This account is switched off.']);
});

it('blocks users of a suspended business', function () {
    probeRoute();
    $this->w->adoma->update(['status' => 'suspended']);

    $this->actingAs($this->w->owner)->getJson('/_probe')
        ->assertForbidden()
        ->assertJson(['message' => 'This business account is not active.']);
});

it('shows nothing to a signed-in user who belongs to no business', function () {
    probeRoute();
    $stray = App\Models\User::factory()->create();

    $this->actingAs($stray)->getJson('/_probe')->assertOk()->assertExactJson([]);
});

// ------------------------------------------------------------- office switcher

it('lets a clerk switch to an office they belong to', function () {
    $this->actingAs($this->w->kumasiClerk)
        ->post(route('branch.switch'), ['branch' => $this->w->kumasi->id])
        ->assertRedirect()
        ->assertSessionHas('active_branch_id', $this->w->kumasi->id);
});

it('forbids switching to an office the user does not belong to', function () {
    $this->actingAs($this->w->kumasiClerk)
        ->post(route('branch.switch'), ['branch' => $this->w->central->id])
        ->assertForbidden();
});

it('forbids switching to another business office', function () {
    $this->actingAs($this->w->owner)
        ->post(route('branch.switch'), ['branch' => $this->w->otherBranch->id])
        ->assertForbidden();
});

it('forbids a clerk switching to all offices but allows an admin', function () {
    $this->actingAs($this->w->kumasiClerk)
        ->post(route('branch.switch'), ['branch' => 'all'])->assertForbidden();

    $this->actingAs($this->w->owner)
        ->post(route('branch.switch'), ['branch' => 'all'])
        ->assertRedirect()->assertSessionHas('active_branch_id', 'all');
});

it('applies the chosen office on the next request', function () {
    probeRoute();

    $this->actingAs($this->w->owner)
        ->withSession(['active_branch_id' => $this->w->kumasi->id])
        ->getJson('/_probe')->assertExactJson(['kumasi-a', 'kumasi-b']);
});

it('gives the page only the offices a clerk may switch to', function () {
    $this->actingAs($this->w->kumasiClerk)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tenancy.tenant', 'Adoma')
            ->where('tenancy.branch.code', 'KUM')
            ->where('tenancy.can_view_all', false)
            ->where('tenancy.all_branches', false)
            ->has('tenancy.branches', 1));
});

it('gives an owner every office plus the all-offices option, but not another business', function () {
    $this->actingAs($this->w->owner)
        ->withSession(['active_branch_id' => 'all'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tenancy.can_view_all', true)
            ->where('tenancy.all_branches', true)
            ->has('tenancy.branches', 2)
            ->where('tenancy.branches.0.code', 'CEN'));
});

it('ignores a stale office in the session and falls back safely', function () {
    probeRoute();

    // The clerk once had access to Central; the session still remembers it.
    $this->actingAs($this->w->kumasiClerk)
        ->withSession(['active_branch_id' => $this->w->central->id])
        ->getJson('/_probe')->assertOk()->assertExactJson(['kumasi-a', 'kumasi-b']);
});
