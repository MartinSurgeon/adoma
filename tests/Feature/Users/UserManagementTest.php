<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\World;

beforeEach(function () {
    $this->w = World::build();
});

/** A valid "add user" request: an ordinary clerk in Kumasi. */
function newUser(World $w, array $overrides = []): array
{
    return array_merge([
        'name' => 'New Person',
        'email' => 'new.person@adoma.test',
        'tenant_role' => null,
        'offices' => [['branch_id' => $w->kumasi->id, 'role' => 'clerk']],
    ], $overrides);
}

/** Create a user as $actor and return the one-time credentials the screen would show. */
function createAs(User $actor, array $payload): array
{
    $credentials = null;

    test()->actingAs($actor)->post(route('users.store'), $payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('users.index'))
        ->assertSessionHas('credentials', function ($c) use (&$credentials) {
            $credentials = $c;

            return true;
        });

    return $credentials;
}

// ------------------------------------------------------- no public registration

it('has no public registration', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', ['name' => 'x', 'email' => 'x@x.test', 'password' => 'password12', 'password_confirmation' => 'password12'])
        ->assertNotFound();
});

// --------------------------------------------------------------------- who can see

it('lets owners and admins open the user list', function (string $who) {
    $this->actingAs($this->w->{$who})->get(route('users.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Settings/Users/Index'));
})->with(['owner', 'admin']);

it('keeps managers and clerks out of the user screens', function (string $who) {
    $this->actingAs($this->w->{$who});

    $this->get(route('users.index'))->assertForbidden();
    $this->get(route('users.create'))->assertForbidden();
    $this->post(route('users.store'), newUser($this->w))->assertForbidden();
    $this->put(route('users.update', $this->w->centralManager->id), newUser($this->w))->assertForbidden();
    $this->patch(route('users.toggle', $this->w->centralManager->id))->assertForbidden();
    $this->post(route('users.reset', $this->w->centralManager->id))->assertForbidden();
})->with(['kumasiClerk', 'centralManager']);

it('lists only the people of the signed-in business', function () {
    $this->actingAs($this->w->owner)->get(route('users.index'))
        ->assertInertia(fn ($page) => $page
            ->has('users', 6)
            ->where('users', fn ($users) => collect($users)->pluck('email')->doesntContain($this->w->otherAdmin->email)));
});

it('never reveals or touches a user of another business', function () {
    $other = $this->w->otherAdmin->id;
    $this->actingAs($this->w->owner);

    $this->get(route('users.edit', $other))->assertNotFound();
    $this->put(route('users.update', $other), newUser($this->w))->assertNotFound();
    $this->patch(route('users.toggle', $other))->assertNotFound();
    $this->post(route('users.reset', $other))->assertNotFound();

    expect($this->w->otherAdmin->fresh()->is_active)->toBeTrue();
});

// ---------------------------------------------------------------- creating users

it('creates an ordinary user with a temporary password and an office role', function () {
    $credentials = createAs($this->w->owner, newUser($this->w));

    $user = User::where('email', 'new.person@adoma.test')->firstOrFail();

    expect($user->tenant_id)->toBe($this->w->adoma->id)
        ->and($user->tenant_role)->toBeNull()
        ->and($user->is_active)->toBeTrue()
        ->and($user->must_change_password)->toBeTrue()
        ->and($user->roleIn($this->w->kumasi)?->value)->toBe('clerk')
        ->and($user->roleIn($this->w->central))->toBeNull()
        ->and(strlen($credentials['password']))->toBe(12)
        ->and($credentials['password'])->toMatch('/^[A-Za-z0-9]+$/')
        ->and(Hash::check($credentials['password'], $user->password))->toBeTrue()
        ->and($user->password)->not->toBe($credentials['password']);
});

it('lets the new person sign in, but only to choose their own password', function () {
    $credentials = createAs($this->w->owner, newUser($this->w));

    $this->post('/logout');
    $this->post('/login', ['email' => $credentials['email'], 'password' => $credentials['password']])
        ->assertRedirect();
    $this->assertAuthenticated();

    $this->get(route('dashboard'))->assertRedirect(route('password.force.edit'));
    $this->get(route('users.index'))->assertRedirect(route('password.force.edit'));
});

it('shows the temporary password only once', function () {
    createAs($this->w->owner, newUser($this->w));

    // The next page load after the redirect has no credentials in the session.
    $this->get(route('users.index'))->assertSessionMissing('credentials');
});

it('makes an owner-created admin without needing office rows', function () {
    createAs($this->w->owner, newUser($this->w, ['tenant_role' => 'admin', 'offices' => []]));

    $user = User::where('email', 'new.person@adoma.test')->firstOrFail();

    expect($user->tenant_role)->toBe('admin')
        ->and($user->branches()->count())->toBe(0)
        ->and($user->accessibleBranches()->pluck('code')->all())->toBe(['CEN', 'KUM']);
});

it('stops an admin creating owners or admins', function (string $role) {
    $this->actingAs($this->w->admin)
        ->post(route('users.store'), newUser($this->w, ['tenant_role' => $role]))
        ->assertSessionHasErrors('tenant_role');

    expect(User::where('email', 'new.person@adoma.test')->exists())->toBeFalse();
})->with(['owner', 'admin']);

it('lets an admin create ordinary users', function () {
    createAs($this->w->admin, newUser($this->w));

    expect(User::where('email', 'new.person@adoma.test')->value('tenant_id'))->toBe($this->w->adoma->id);
});

it('cannot give someone an office from another business', function () {
    $this->actingAs($this->w->owner)
        ->post(route('users.store'), newUser($this->w, ['offices' => [['branch_id' => $this->w->otherBranch->id, 'role' => 'clerk']]]))
        ->assertSessionHasErrors('offices.0.branch_id');
});

it('needs at least one office for an ordinary user', function () {
    $this->actingAs($this->w->owner)
        ->post(route('users.store'), newUser($this->w, ['offices' => []]))
        ->assertSessionHasErrors('offices');
});

it('only accepts manager, clerk or viewer as an office role', function () {
    $this->actingAs($this->w->owner)
        ->post(route('users.store'), newUser($this->w, ['offices' => [['branch_id' => $this->w->kumasi->id, 'role' => 'owner']]]))
        ->assertSessionHasErrors('offices.0.role');
});

it('rejects an email address that is already used, even by another business', function () {
    $this->actingAs($this->w->owner)
        ->post(route('users.store'), newUser($this->w, ['email' => $this->w->otherAdmin->email]))
        ->assertSessionHasErrors('email');
});

it('cannot set access fields by sending extra data', function () {
    createAs($this->w->owner, newUser($this->w) + ['tenant_id' => $this->w->other->id, 'is_active' => false]);

    $user = User::where('email', 'new.person@adoma.test')->firstOrFail();

    expect($user->tenant_id)->toBe($this->w->adoma->id)->and($user->is_active)->toBeTrue();
});

// ------------------------------------------------------------------- editing users

it('changes a user\'s name, email and offices', function () {
    $clerk = $this->w->kumasiClerk;

    $this->actingAs($this->w->admin)->put(route('users.update', $clerk->id), newUser($this->w, [
        'name' => 'Renamed',
        'email' => 'renamed@adoma.test',
        'offices' => [['branch_id' => $this->w->central->id, 'role' => 'manager']],
    ]))->assertSessionHasNoErrors()->assertRedirect(route('users.index'));

    $clerk->refresh();

    expect($clerk->name)->toBe('Renamed')
        ->and($clerk->email)->toBe('renamed@adoma.test')
        ->and($clerk->roleIn($this->w->central)?->value)->toBe('manager')
        ->and($clerk->roleIn($this->w->kumasi))->toBeNull();
});

it('lets a user keep their own email when saving', function () {
    $clerk = $this->w->kumasiClerk;

    $this->actingAs($this->w->admin)
        ->put(route('users.update', $clerk->id), newUser($this->w, ['email' => $clerk->email]))
        ->assertSessionHasNoErrors();
});

it('lets an owner promote a clerk to admin, but not an admin', function () {
    $clerk = $this->w->kumasiClerk;

    $this->actingAs($this->w->admin)
        ->put(route('users.update', $clerk->id), newUser($this->w, ['email' => $clerk->email, 'tenant_role' => 'admin']))
        ->assertSessionHasErrors('tenant_role');

    $this->actingAs($this->w->owner)
        ->put(route('users.update', $clerk->id), newUser($this->w, ['email' => $clerk->email, 'tenant_role' => 'admin', 'offices' => []]))
        ->assertSessionHasNoErrors();

    expect($clerk->fresh()->tenant_role)->toBe('admin');
});

it('stops an admin editing an owner or another admin', function () {
    $this->actingAs($this->w->admin);

    $this->get(route('users.edit', $this->w->owner->id))->assertForbidden();
    $this->put(route('users.update', $this->w->owner->id), newUser($this->w))->assertForbidden();
    $this->patch(route('users.toggle', $this->w->owner->id))->assertForbidden();
    $this->post(route('users.reset', $this->w->owner->id))->assertForbidden();
});

it('stops anyone managing themselves on this screen', function (string $who) {
    $me = $this->w->{$who};
    $this->actingAs($me);

    $this->get(route('users.edit', $me->id))->assertForbidden();
    $this->patch(route('users.toggle', $me->id))->assertForbidden();
    $this->post(route('users.reset', $me->id))->assertForbidden();

    expect($me->fresh()->is_active)->toBeTrue();
})->with(['owner', 'admin']);

it('lets an owner edit an admin', function () {
    $this->actingAs($this->w->owner)->get(route('users.edit', $this->w->admin->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Settings/Users/Edit')->where('user.tenant_role', 'admin'));
});

// ------------------------------------------------- switching off and resetting

function sessionFor(User $user): void
{
    DB::table('sessions')->insert([
        'id' => 'session-'.$user->id,
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'payload' => '',
        'last_activity' => now()->timestamp,
    ]);
}

it('switches a user off, signs them out everywhere and blocks sign-in', function () {
    $clerk = $this->w->kumasiClerk;
    sessionFor($clerk);

    $this->actingAs($this->w->admin)->patch(route('users.toggle', $clerk->id))->assertRedirect();
    $this->post('/logout');

    expect($clerk->fresh()->is_active)->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $clerk->id)->count())->toBe(0);

    $this->post('/login', ['email' => $clerk->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('lets a switched-off user sign in again once switched back on', function () {
    $clerk = $this->w->kumasiClerk;
    $clerk->forceFill(['is_active' => false])->save();

    $this->actingAs($this->w->admin)->patch(route('users.toggle', $clerk->id));
    $this->post('/logout');

    expect($clerk->fresh()->is_active)->toBeTrue();

    $this->post('/login', ['email' => $clerk->email, 'password' => 'password'])->assertRedirect();
    $this->assertAuthenticated();
});

it('resets a password, ends sessions and forces a new choice', function () {
    $clerk = $this->w->kumasiClerk;
    sessionFor($clerk);

    $credentials = null;
    $this->actingAs($this->w->admin)->post(route('users.reset', $clerk->id))
        ->assertSessionHas('credentials', function ($c) use (&$credentials) {
            $credentials = $c;

            return true;
        });

    $clerk->refresh();

    expect(Hash::check('password', $clerk->password))->toBeFalse()
        ->and(Hash::check($credentials['password'], $clerk->password))->toBeTrue()
        ->and($clerk->must_change_password)->toBeTrue()
        ->and(DB::table('sessions')->where('user_id', $clerk->id)->count())->toBe(0);
});

// ----------------------------------------------------------- forced password change

function mustChange(World $w): User
{
    $w->kumasiClerk->forceFill(['must_change_password' => true])->save();

    return $w->kumasiClerk;
}

it('keeps a user with a temporary password on the change-password page', function () {
    $this->actingAs(mustChange($this->w));

    $this->get(route('dashboard'))->assertRedirect(route('password.force.edit'));
    $this->post(route('branch.switch'), ['branch' => $this->w->kumasi->id])->assertRedirect(route('password.force.edit'));
    $this->getJson(route('dashboard'))->assertForbidden()->assertJson(['message' => 'You must choose a new password first.']);
    $this->get(route('password.force.edit'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Auth/ForcePasswordChange'));
});

it('lets a user with a temporary password sign out', function () {
    $this->actingAs(mustChange($this->w))->post('/logout')->assertRedirect('/');
    $this->assertGuest();
});

it('rejects a wrong temporary password, weak passwords and an unchanged password', function (array $input, string $error) {
    $this->actingAs(mustChange($this->w))
        ->put(route('password.force.update'), $input)
        ->assertSessionHasErrors($error);

    expect($this->w->kumasiClerk->fresh()->must_change_password)->toBeTrue();
})->with([
    'wrong current password' => [['current_password' => 'nope', 'password' => 'GoodPass12345', 'password_confirmation' => 'GoodPass12345'], 'current_password'],
    'too short' => [['current_password' => 'password', 'password' => 'Short1', 'password_confirmation' => 'Short1'], 'password'],
    'no numbers' => [['current_password' => 'password', 'password' => 'OnlyLettersHere', 'password_confirmation' => 'OnlyLettersHere'], 'password'],
    'not confirmed' => [['current_password' => 'password', 'password' => 'GoodPass12345', 'password_confirmation' => 'Different12345'], 'password'],
    'same as temporary' => [['current_password' => 'password', 'password' => 'password', 'password_confirmation' => 'password'], 'password'],
]);

it('saves a good new password, clears the flag and unlocks the app', function () {
    $clerk = mustChange($this->w);

    $this->actingAs($clerk)->put(route('password.force.update'), [
        'current_password' => 'password',
        'password' => 'MyOwnPass2026',
        'password_confirmation' => 'MyOwnPass2026',
    ])->assertSessionHasNoErrors()->assertRedirect(route('dashboard'));

    $clerk->refresh();

    expect($clerk->must_change_password)->toBeFalse()
        ->and(Hash::check('MyOwnPass2026', $clerk->password))->toBeTrue();

    $this->get(route('dashboard'))->assertOk();
    $this->get(route('password.force.edit'))->assertRedirect(route('dashboard'));
});

it('does not refuse a normal user the page they asked for', function () {
    $this->actingAs($this->w->kumasiClerk)->get(route('dashboard'))->assertOk();
});
