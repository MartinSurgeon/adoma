<?php

use App\Domain\Audit\Audit;
use App\Domain\Audit\AuditLog;
use App\Domain\Ledgers\ChartOfAccounts;
use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\World;

beforeEach(function () {
    $this->w = World::build();
    $this->ctx = app(TenantContext::class);
});

/** Entries for one business, read without needing a context. */
function entries(int $tenantId, ?string $event = null)
{
    return AuditLog::withoutGlobalScopes()->where('tenant_id', $tenantId)
        ->when($event, fn ($q) => $q->where('event', $event))->orderBy('id')->get();
}

function makeLedger(World $w, string $name = 'Passion Gold'): Ledger
{
    return Ledger::create([
        'tenant_id' => $w->adoma->id, 'branch_id' => $w->kumasi->id, 'is_shared' => false,
        'group_id' => ChartOfAccounts::groupFor($w->adoma->id, LedgerKind::Customer)->id,
        'kind' => LedgerKind::Customer, 'name' => $name,
    ]);
}

// ------------------------------------------------------------- what is recorded

it('records who created a ledger, in which business and office', function () {
    $this->actingAs($this->w->kumasiClerk);
    $this->ctx->set($this->w->adoma, $this->w->kumasi);

    $ledger = makeLedger($this->w);

    $log = entries($this->w->adoma->id, 'created')->last();

    expect($log->auditable_type)->toBe(Ledger::class)
        ->and($log->auditable_id)->toBe($ledger->id)
        ->and($log->label)->toBe('Passion Gold')
        ->and($log->user_id)->toBe($this->w->kumasiClerk->id)
        ->and($log->actor_name)->toBe($this->w->kumasiClerk->name)
        ->and($log->tenant_id)->toBe($this->w->adoma->id)
        ->and($log->branch_id)->toBe($this->w->kumasi->id)
        ->and($log->new_values['name'])->toBe('Passion Gold')
        ->and($log->describe())->toBe('added ledger “Passion Gold”');
});

it('records what changed, with the old and the new value', function () {
    $ledger = makeLedger($this->w);
    $this->actingAs($this->w->kumasiManager);

    $ledger->update(['name' => 'Passion Gold Ltd', 'phone' => '0244000000']);

    $log = entries($this->w->adoma->id, 'updated')->last();

    expect($log->old_values)->toBe(['name' => 'Passion Gold', 'phone' => null])
        ->and($log->new_values)->toBe(['name' => 'Passion Gold Ltd', 'phone' => '0244000000'])
        ->and($log->describe())->toBe('changed the name and phone of ledger “Passion Gold Ltd”');
});

it('says in plain words when a ledger is switched off and on', function () {
    $ledger = makeLedger($this->w);

    $ledger->forceFill(['is_active' => false])->save();
    expect(entries($this->w->adoma->id, 'updated')->last()->describe())->toBe('switched off ledger “Passion Gold”');

    $ledger->forceFill(['is_active' => true])->save();
    expect(entries($this->w->adoma->id, 'updated')->last()->describe())->toBe('switched on ledger “Passion Gold”');
});

it('records a branch being renamed', function () {
    $this->w->kumasi->update(['name' => 'Kumasi Market']);

    $log = entries($this->w->adoma->id, 'updated')->last();

    expect($log->auditable_id)->toBe($this->w->kumasi->id)
        ->and($log->old_values)->toBe(['name' => 'Kumasi'])
        ->and($log->describe())->toBe('changed the name of branch “Kumasi Market”');
});

it('records a deletion with what was deleted', function () {
    $ledger = makeLedger($this->w);
    $ledger->delete();

    $log = entries($this->w->adoma->id, 'deleted')->last();

    expect($log->label)->toBe('Passion Gold')
        ->and($log->old_values['name'])->toBe('Passion Gold')
        ->and($log->describe())->toBe('removed ledger “Passion Gold”');
});

it('does not record a change that only moved a timestamp', function () {
    $ledger = makeLedger($this->w);
    $before = entries($this->w->adoma->id)->count();

    $ledger->touch();

    expect(entries($this->w->adoma->id)->count())->toBe($before);
});

it('records changes made by the system with no person', function () {
    $log = entries($this->w->adoma->id, 'created')->first();

    expect($log->user_id)->toBeNull()->and($log->actor_name)->toBeNull();
});

// -------------------------------------------------------------------- passwords

it('never stores a password, only that it changed', function () {
    $user = $this->w->kumasiClerk;
    $user->forceFill(['password' => 'a-brand-new-secret-1', 'must_change_password' => true])->save();

    $log = entries($this->w->adoma->id, 'updated')->last();

    expect($log->new_values['password'])->toBe('***')
        ->and($log->old_values['password'])->toBe('***')
        ->and($log->describe())->toBe("changed the password of user “{$user->name}”");

    $everything = AuditLog::withoutGlobalScopes()->get()->map(fn ($l) => json_encode([$l->old_values, $l->new_values]))->implode(' ');
    expect($everything)->not->toContain('a-brand-new-secret-1')->not->toContain('$2y$');
});

it('does not store a new user\'s password hash', function () {
    $log = entries($this->w->adoma->id, 'created')->where('auditable_type', User::class)->first();

    expect($log->new_values['password'])->toBe('***')->and($log->new_values)->not->toHaveKey('remember_token');
});

// ---------------------------------------------------------------------- sign-ins

it('records a sign-in and a sign-out', function () {
    $user = $this->w->kumasiClerk;

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
    $this->post('/logout');

    $logins = entries($this->w->adoma->id, 'login');
    $logouts = entries($this->w->adoma->id, 'logout');

    expect($logins)->toHaveCount(1)->and($logins->first()->user_id)->toBe($user->id)
        ->and($logins->first()->describe())->toBe('signed in')
        ->and($logouts)->toHaveCount(1)->and($logouts->first()->describe())->toBe('signed out');
});

it('records a failed sign-in against the person it was for, without the password', function () {
    $user = $this->w->kumasiClerk;

    $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);

    $log = entries($this->w->adoma->id, 'login_failed')->first();

    expect($log->new_values)->toBe(['email' => $user->email])
        ->and($log->describe())->toBe('failed to sign in')
        ->and(json_encode($log->toArray()))->not->toContain('wrong-password');
});

it('records a failed sign-in for an unknown email, kept out of every business\'s view', function () {
    $this->post('/login', ['email' => 'nobody@nowhere.test', 'password' => 'x']);

    $log = AuditLog::withoutGlobalScopes()->where('event', 'login_failed')->latest('id')->first();

    expect($log->tenant_id)->toBeNull()->and($log->actor_name)->toContain('nobody@nowhere.test');

    $this->ctx->set($this->w->adoma, null, allBranches: true);
    expect(AuditLog::where('event', 'login_failed')->count())->toBe(0);
});

it('records a switched-off account trying to sign in', function () {
    $clerk = $this->w->kumasiClerk;
    $clerk->forceFill(['is_active' => false])->save();

    $this->post('/login', ['email' => $clerk->email, 'password' => 'password'])->assertSessionHasErrors('email');

    expect(entries($this->w->adoma->id, 'login_failed'))->toHaveCount(1);
});

// ------------------------------------------------------------------ access changes

it('records who is given which branches and roles', function () {
    $this->actingAs($this->w->owner)->post(route('users.store'), [
        'name' => 'New Person', 'email' => 'new.person@adoma.test', 'tenant_role' => null,
        'offices' => [['branch_id' => $this->w->kumasi->id, 'role' => 'clerk']],
    ])->assertSessionHasNoErrors();

    $log = entries($this->w->adoma->id, 'access_changed')->last();

    expect($log->label)->toBe('New Person')
        ->and($log->actor_name)->toBe($this->w->owner->name)
        ->and($log->new_values)->toBe(['offices' => ['Kumasi' => 'clerk']])
        ->and($log->describe())->toBe('changed which branches and roles “New Person” has');
});

it('records a branch or role change with before and after, and nothing when it did not change', function () {
    $clerk = $this->w->kumasiClerk;
    $payload = fn (array $offices) => ['name' => $clerk->name, 'email' => $clerk->email, 'tenant_role' => null, 'offices' => $offices];
    $this->actingAs($this->w->admin);

    $this->put(route('users.update', $clerk->id), $payload([['branch_id' => $this->w->kumasi->id, 'role' => 'clerk']]));
    expect(entries($this->w->adoma->id, 'access_changed'))->toHaveCount(0);

    $this->put(route('users.update', $clerk->id), $payload([['branch_id' => $this->w->central->id, 'role' => 'manager']]));

    $log = entries($this->w->adoma->id, 'access_changed')->last();
    expect($log->old_values)->toBe(['offices' => ['Kumasi' => 'clerk']])
        ->and($log->new_values)->toBe(['offices' => ['Central' => 'manager']])
        ->and($log->actor_name)->toBe($this->w->admin->name);
});

it('records an admin switching an account off', function () {
    $this->actingAs($this->w->admin)->patch(route('users.toggle', $this->w->kumasiClerk->id));

    $log = entries($this->w->adoma->id, 'updated')->last();

    expect($log->describe())->toBe("switched off user “{$this->w->kumasiClerk->name}”")
        ->and($log->actor_name)->toBe($this->w->admin->name);
});

// ----------------------------------------------------------------- tamper proof

it('cannot be changed or deleted through the app', function () {
    makeLedger($this->w);
    $log = entries($this->w->adoma->id)->first();

    expect(fn () => $log->update(['label' => 'Changed']))->toThrow(LogicException::class)
        ->and(fn () => $log->delete())->toThrow(LogicException::class);
});

it('is rolled back with the change it describes', function () {
    $before = AuditLog::withoutGlobalScopes()->count();

    try {
        DB::transaction(function () {
            makeLedger($this->w, 'Never Saved');
            throw new RuntimeException('failed');
        });
    } catch (RuntimeException) {
    }

    expect(AuditLog::withoutGlobalScopes()->count())->toBe($before)
        ->and(AuditLog::withoutGlobalScopes()->where('label', 'Never Saved')->exists())->toBeFalse();
});

it('outlives the user it is about', function () {
    $clerk = $this->w->kumasiClerk;
    $this->actingAs($clerk);
    $this->ctx->set($this->w->adoma, $this->w->kumasi);
    makeLedger($this->w);

    DB::table('users')->where('id', $clerk->id)->delete();

    $log = entries($this->w->adoma->id, 'created')->last();
    expect($log->actor_name)->toBe($clerk->name);
});

// -------------------------------------------------------------------- who can read

it('lets owners and admins read the activity page', function (string $who) {
    $this->actingAs($this->w->{$who})->get(route('activity.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Activity/Index'));
})->with(['owner', 'admin']);

it('keeps everyone else out of the activity page', function (string $who) {
    $this->actingAs($this->w->{$who})->get(route('activity.index'))->assertForbidden();
})->with(['kumasiManager', 'kumasiClerk', 'kumasiViewer', 'centralManager']);

it('shows a business only its own activity', function () {
    $this->ctx->set($this->w->other, null, allBranches: true);
    $this->actingAs($this->w->otherAdmin);
    Ledger::create([
        'tenant_id' => $this->w->other->id, 'branch_id' => $this->w->otherBranch->id, 'is_shared' => false,
        'group_id' => ChartOfAccounts::groupFor($this->w->other->id, LedgerKind::Customer)->id,
        'kind' => LedgerKind::Customer, 'name' => 'Other Co Secret',
    ]);
    $this->ctx->clear();
    makeLedger($this->w);

    $text = fn ($user) => json_encode($this->actingAs($user)->get(route('activity.index'))->viewData('page')['props']['logs']['data']);

    expect($text($this->w->owner))->toContain('Passion Gold')->not->toContain('Other Co Secret')
        ->and($text($this->w->otherAdmin))->toContain('Other Co Secret')->not->toContain('Passion Gold');
});

it('lists newest first with a plain sentence and details of what changed', function () {
    $ledger = makeLedger($this->w);
    $ledger->update(['name' => 'Passion Gold Ltd']);

    $this->actingAs($this->w->owner)->get(route('activity.index', ['type' => 'ledger']))
        ->assertInertia(fn ($page) => $page
            ->where('logs.data.0.description', 'changed the name of ledger “Passion Gold Ltd”')
            ->where('logs.data.0.changes.0.field', 'name')
            ->where('logs.data.0.changes.0.old', 'Passion Gold')
            ->where('logs.data.0.changes.0.new', 'Passion Gold Ltd')
            ->where('logs.data.1.description', 'added ledger “Passion Gold”'));
});

it('filters the activity by what it is about', function () {
    makeLedger($this->w);
    $this->post('/login', ['email' => $this->w->kumasiClerk->email, 'password' => 'password']);
    $this->post('/logout');

    $descriptions = fn (string $type) => collect(
        $this->actingAs($this->w->owner)->get(route('activity.index', ['type' => $type]))->viewData('page')['props']['logs']['data']
    )->pluck('description');

    expect($descriptions('signin')->all())->toBe(['signed out', 'signed in'])
        ->and($descriptions('ledger')->every(fn ($d) => str_contains($d, 'ledger')))->toBeTrue()
        ->and($descriptions('user')->contains('signed in'))->toBeFalse()
        ->and($descriptions('branch')->every(fn ($d) => str_contains($d, 'branch')))->toBeTrue();
});

it('pages the activity 25 at a time', function () {
    foreach (range(1, 30) as $i) {
        makeLedger($this->w, sprintf('Customer %02d', $i));
    }

    $this->actingAs($this->w->owner)->get(route('activity.index', ['type' => 'ledger']))
        ->assertInertia(fn ($page) => $page->has('logs.data', 25)->where('logs.last_page', 2));
});
