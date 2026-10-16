<?php

use App\Domain\Audit\AuditLog;
use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentType;
use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use App\Domain\Tenancy\Branch;
use App\Domain\Tenancy\RoleGuide;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\Support\World;

/*
 * The table "What each role can do" is what owners read before giving someone a role. Every tick and every blank
 * in it is checked here against the rules the app really applies, for someone working in Kumasi.
 */

beforeEach(function () {
    $this->w = World::build();
    app(TenantContext::class)->set($this->w->adoma, $this->w->kumasi);
});

/** Does the app let this person do the thing the table's row describes? */
function guideAllows(string $key, User $user, World $w): bool
{
    $can = fn (string $ability, mixed $arguments = []) => Gate::forUser($user)->allows($ability, $arguments);
    $sale = (new Document())->forceFill(['status' => Document::POSTED, 'type' => DocumentType::Sale, 'branch_id' => $w->kumasi->id]);
    $customer = (new Ledger())->forceFill(['branch_id' => $w->kumasi->id, 'is_shared' => false, 'is_system' => false, 'kind' => LedgerKind::Customer]);

    return match ($key) {
        'read' => $can('viewAny', Document::class) && $can('viewAny', Ledger::class),
        'record' => $can('create', [Document::class, 'sale']) && $can('create', [Document::class, 'receipt']),
        'people' => $can('create', [Ledger::class, 'customer']) && $can('create', [Ledger::class, 'supplier']),
        'accounts' => $can('create', [Ledger::class, 'bank']) && $can('create', [Ledger::class, 'expense']),
        'transfer' => $can('create', [Document::class, 'transfer']) && $can('receiveTransfer', Document::class),
        'reverse' => $can('reverse', $sale),
        'toggle' => $can('toggle', $customer),
        // Looking at all branches is decided when the branch is chosen: owners and admins only.
        'all' => $user->isTenantAdmin(),
        'users' => $can('viewAny', User::class),
        'activity' => $can('viewAny', AuditLog::class),
        'branches' => $can('create', Branch::class),
        'admins' => $can('assignAdminRoles', User::class),
    };
}

it('says exactly what each role can do', function () {
    $people = [
        'viewer' => $this->w->kumasiViewer,
        'clerk' => $this->w->kumasiClerk,
        'manager' => $this->w->kumasiManager,
        'admin' => $this->w->admin,
        'owner' => $this->w->owner,
    ];

    foreach (RoleGuide::rows() as $row) {
        foreach ($people as $role => $user) {
            expect(guideAllows($row['key'], $user, $this->w))
                ->toBe(in_array($role, $row['roles'], true), "“{$row['can']}” for a $role");
        }
    }
});

it('is right about the admin the owner allows to add branches', function () {
    $this->w->admin->forceFill(['can_manage_branches' => true])->save();

    expect(guideAllows('branches', $this->w->admin, $this->w))->toBeTrue()
        ->and(collect(RoleGuide::rows())->firstWhere('key', 'branches')['note'])->toBe('An admin can, once the owner allows it.');
});

it('has a line for every role on the forms', function () {
    expect(array_keys(RoleGuide::summaries()))->toBe(RoleGuide::ROLES);
});
