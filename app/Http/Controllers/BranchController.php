<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\Branch;
use App\Domain\Tenancy\BranchAccess;
use App\Domain\Tenancy\Role;
use App\Domain\Tenancy\RoleGuide;
use App\Http\Requests\SaveBranchRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The branches of the business, and who works in each, in what role.
 * Rules live in BranchPolicy: owners and admins look and give roles; adding and editing a branch is for an owner,
 * or an admin the owner has allowed.
 */
class BranchController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Branch::class);

        $actor = $request->user();
        $roles = DB::table('branch_user')
            ->join('users', 'users.id', '=', 'branch_user.user_id')
            ->where('users.tenant_id', $actor->tenant_id)
            ->where('users.is_active', true)
            ->select('branch_user.branch_id', 'branch_user.role', DB::raw('COUNT(*) as people'))
            ->groupBy('branch_user.branch_id', 'branch_user.role')
            ->get()
            ->groupBy('branch_id');

        return Inertia::render('Settings/Branches/Index', [
            'branches' => $actor->accessibleBranches()->map(fn (Branch $branch) => [
                ...$this->details($branch),
                // How many people work here in each role. Owners and admins work everywhere, so they are counted once, below.
                'people' => collect(Role::branchRoles())->mapWithKeys(fn (Role $role) => [
                    $role->value => (int) ($roles->get($branch->id)?->firstWhere('role', $role->value)?->people ?? 0),
                ]),
            ])->values(),
            'admins' => $this->admins($actor),
            'canCreate' => $actor->can('create', Branch::class),
            'canEdit' => $actor->canManageBranches(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Branch::class);

        return Inertia::render('Settings/Branches/Create', [
            'headOffice' => Branch::where('is_head_office', true)->value('name'),
            'codes' => Branch::pluck('code'),
        ]);
    }

    public function store(SaveBranchRequest $request): RedirectResponse
    {
        $branch = DB::transaction(function () use ($request) {
            $this->clearHeadOffice($request);

            // Adding a branch also opens its account (other branches trade with it) and its voucher numbers.
            return Branch::create([
                'tenant_id' => $request->user()->tenant_id,
                ...$request->safe()->only(['name', 'code', 'region', 'is_head_office']),
            ]);
        });

        return redirect()->route('branches.show', $branch)
            ->with('success', "{$branch->name} is added. Now choose who works there, and in what role.");
    }

    /** The branch, and the people who work there with their roles, which an owner or admin can change here. */
    public function show(Request $request, Branch $branch): Response
    {
        $this->authorize('view', $branch);

        $actor = $request->user();

        $staff = User::where('tenant_id', $actor->tenant_id)
            ->whereNull('tenant_role')
            ->with(['branches' => fn ($q) => $q->withoutGlobalScopes()])
            ->orderBy('name')
            ->get();

        return Inertia::render('Settings/Branches/Show', [
            'branch' => $this->details($branch),
            'staff' => $staff->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'role' => $user->branches->firstWhere('id', $branch->id)?->pivot->role,
                // Taking away someone's only branch would leave them able to sign in to nothing.
                'only_here' => $user->branches->count() === 1 && $user->branches->first()->id === $branch->id,
                'elsewhere' => $user->branches->where('id', '!=', $branch->id)->pluck('name')->values(),
                'can_manage' => $actor->can('manage', $user),
            ]),
            'admins' => $this->admins($actor),
            'branchRoles' => array_map(fn (Role $r) => $r->value, Role::branchRoles()),
            'roleSummaries' => RoleGuide::summaries(),
            'roleGuide' => RoleGuide::rows(),
            'canEdit' => $actor->can('update', $branch),
        ]);
    }

    public function edit(Branch $branch): Response
    {
        $this->authorize('update', $branch);

        return Inertia::render('Settings/Branches/Edit', [
            'branch' => $this->details($branch),
            'headOffice' => Branch::where('is_head_office', true)->whereKeyNot($branch->id)->value('name'),
        ]);
    }

    public function update(SaveBranchRequest $request, Branch $branch): RedirectResponse
    {
        DB::transaction(function () use ($request, $branch) {
            $this->clearHeadOffice($request, $branch);
            $branch->update($request->safe()->only(['name', 'region', 'is_head_office']));
        });

        return redirect()->route('branches.show', $branch)->with('success', "Saved changes to {$branch->name}.");
    }

    /**
     * Give people a role in this branch, change it, or take it away: { roles: { user id: role or "" } }.
     * Only the people listed change, and only in this branch. Owners and admins work everywhere already.
     */
    public function updateAccess(Request $request, Branch $branch): RedirectResponse
    {
        $this->authorize('manageAccess', $branch);

        $actor = $request->user();
        $data = $request->validate([
            'roles' => ['present', 'array'],
            'roles.*' => ['nullable', Rule::in(array_map(fn (Role $r) => $r->value, Role::branchRoles()))],
        ]);

        $people = User::where('tenant_id', $actor->tenant_id)
            ->whereIn('id', array_keys($data['roles']))
            ->with(['branches' => fn ($q) => $q->withoutGlobalScopes()])
            ->get()
            ->keyBy('id');

        $changes = collect($data['roles'])
            ->map(fn ($role) => $role ?: null)
            ->filter(fn ($role, $id) => $people->has($id)
                && $people[$id]->branches->firstWhere('id', $branch->id)?->pivot->role !== $role);

        // Check every change before making any, so a refused one leaves nothing half done.
        $errors = [];
        foreach ($changes as $id => $role) {
            $person = $people[$id];

            if ($person->isTenantAdmin() || ! $actor->can('manage', $person)) {
                $errors["roles.$id"] = "You cannot change {$person->name}'s access here.";
            } elseif ($role === null && $person->branches->count() === 1 && $person->branches->first()->id === $branch->id) {
                $errors["roles.$id"] = "{$branch->name} is {$person->name}'s only branch. Give them another branch first, or switch their account off.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(fn () => $changes->each(fn ($role, $id) => BranchAccess::setIn($branch, $people[$id], $role)));

        return redirect()->route('branches.show', $branch)->with('success', match ($changes->count()) {
            0 => 'Nothing changed.',
            1 => "Saved. {$people[$changes->keys()->first()]->name}'s access to {$branch->name} is changed.",
            default => "Saved. {$changes->count()} people's access to {$branch->name} is changed.",
        });
    }

    /** Only one head office: choosing a new one takes it from the old. */
    private function clearHeadOffice(SaveBranchRequest $request, ?Branch $except = null): void
    {
        if ($request->boolean('is_head_office')) {
            Branch::where('is_head_office', true)
                ->when($except, fn ($q) => $q->whereKeyNot($except->id))
                ->get()
                ->each(fn (Branch $b) => $b->update(['is_head_office' => false]));
        }
    }

    private function details(Branch $branch): array
    {
        return [
            'id' => $branch->id,
            'name' => $branch->name,
            'code' => $branch->code,
            'region' => $branch->region,
            'is_head_office' => (bool) $branch->is_head_office,
            'added' => $branch->created_at?->toDateString(),
        ];
    }

    /** Owners and admins: they work in every branch without being given a role in each. */
    private function admins(User $actor): array
    {
        return User::where('tenant_id', $actor->tenant_id)
            ->whereIn('tenant_role', [Role::Owner->value, Role::Admin->value])
            ->where('is_active', true)
            ->orderByRaw("CASE WHEN tenant_role = 'owner' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'role' => $user->tenant_role])
            ->all();
    }
}
