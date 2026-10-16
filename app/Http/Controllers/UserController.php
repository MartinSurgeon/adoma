<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Tenancy\BranchAccess;
use App\Domain\Tenancy\Role;
use App\Domain\Tenancy\RoleGuide;
use App\Http\Requests\SaveUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin-created accounts. There is no public registration.
 * A new or reset account gets a temporary password that is shown once;
 * the person must choose their own at first sign-in.
 */
class UserController extends Controller
{
    /** Owners first, then admins, then everyone who works in chosen branches; by name within each. */
    private const ORDER = [Role::Owner->value => 0, Role::Admin->value => 1];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $actor = $request->user();

        $users = User::where('tenant_id', $actor->tenant_id)
            ->with(['branches' => fn ($q) => $q->withoutGlobalScopes()->orderByDesc('is_head_office')->orderBy('name')])
            ->get()
            ->sortBy(fn (User $user) => [self::ORDER[$user->tenant_role] ?? 2, mb_strtolower($user->name)])
            ->values();

        $lastSignIn = $this->lastSignIns($users->pluck('id')->all());

        return Inertia::render('Settings/Users/Index', [
            'users' => $users->map(fn (User $user) => [
                ...$this->summary($user, $lastSignIn),
                'is_self' => $actor->is($user),
                'can_manage' => $actor->can('manage', $user),
            ]),
            'branches' => $actor->accessibleBranches()->map->only(['id', 'name'])->values(),
            'roleGuide' => RoleGuide::rows(),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', User::class);

        return Inertia::render('Settings/Users/Create', $this->formOptions($request));
    }

    public function store(SaveUserRequest $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        $temporary = $this->temporaryPassword();

        $user = new User();
        $user->forceFill([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => $temporary,
            'tenant_id' => $request->user()->tenant_id,
            'tenant_role' => $request->validated('tenant_role'),
            'can_manage_branches' => $this->branchManagement($request, false),
            'is_active' => true,
            'must_change_password' => true,
            'email_verified_at' => now(),
        ])->save();

        BranchAccess::set($user, $this->offices($request));

        return redirect()->route('users.index')
            ->with('success', "Account created for {$user->name}.")
            ->with('credentials', ['name' => $user->name, 'email' => $user->email, 'password' => $temporary]);
    }

    public function edit(Request $request, int $user): Response
    {
        $target = $this->managed($request, $user);

        $target->load(['branches' => fn ($q) => $q->withoutGlobalScopes()->orderByDesc('is_head_office')->orderBy('name')]);

        return Inertia::render('Settings/Users/Edit', [
            ...$this->formOptions($request),
            'user' => [
                ...$this->summary($target, $this->lastSignIns([$target->id])),
                'offices' => $target->branches->map(fn ($b) => ['branch_id' => $b->id, 'role' => $b->pivot->role])->values(),
            ],
        ]);
    }

    public function update(SaveUserRequest $request, int $user): RedirectResponse
    {
        $target = $this->managed($request, $user);

        $target->forceFill([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'tenant_role' => $request->validated('tenant_role'),
            'can_manage_branches' => $this->branchManagement($request, $target->can_manage_branches),
        ])->save();

        // Which branches someone can use, and in what role, is the most sensitive thing on this screen.
        BranchAccess::set($target, $this->offices($request));

        return redirect()->route('users.index')->with('success', "Saved changes for {$target->name}.");
    }

    /** Switch an account off or on. Switching off also ends every open session. */
    public function toggle(Request $request, int $user): RedirectResponse
    {
        $target = $this->managed($request, $user);

        $target->forceFill(['is_active' => ! $target->is_active])->save();

        if (! $target->is_active) {
            $this->endSessions($target);
        }

        return back()->with('success', $target->is_active
            ? "{$target->name} can sign in again."
            : "{$target->name} is switched off and signed out everywhere.");
    }

    public function resetPassword(Request $request, int $user): RedirectResponse
    {
        $target = $this->managed($request, $user);
        $temporary = $this->temporaryPassword();

        $target->forceFill(['password' => $temporary, 'must_change_password' => true])->save();
        $this->endSessions($target);

        return back()
            ->with('success', "Password reset for {$target->name}.")
            ->with('credentials', ['name' => $target->name, 'email' => $target->email, 'password' => $temporary]);
    }

    /** A user of this business, or 404. Other businesses' users are never revealed. */
    private function managed(Request $request, int $id): User
    {
        $target = User::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);

        $this->authorize('manage', $target);

        return $target;
    }

    /** [branch id => role] from the form. Owners and admins keep none: BranchAccess drops them. */
    private function offices(SaveUserRequest $request): array
    {
        return collect($request->validated('offices', []))
            ->mapWithKeys(fn ($o) => [(int) $o['branch_id'] => $o['role']])
            ->all();
    }

    /**
     * May this person add and edit branches? Only an admin can be allowed, and only an owner decides. Anyone else
     * saving the form leaves it as it was, and someone who stops being an admin loses it.
     */
    private function branchManagement(SaveUserRequest $request, bool $current): bool
    {
        if ($request->validated('tenant_role') !== Role::Admin->value) {
            return false;
        }

        return $request->user()->can('grantBranchManagement', User::class)
            ? $request->boolean('can_manage_branches')
            : $current;
    }

    /** What the list and the edit page show about someone. */
    private function summary(User $user, array $lastSignIn): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => $user->is_active,
            'must_change_password' => $user->must_change_password,
            'tenant_role' => $user->tenant_role,
            'can_manage_branches' => $user->canManageBranches(),
            'offices' => $user->branches->map(fn ($b) => [
                'branch_id' => $b->id, 'name' => $b->name, 'code' => $b->code, 'role' => $b->pivot->role,
            ])->values(),
            'last_sign_in' => $lastSignIn[$user->id] ?? null,
        ];
    }

    /** When each person last signed in, from the activity record: [user id => ISO time]. */
    private function lastSignIns(array $ids): array
    {
        return AuditLog::query()
            ->where('event', 'login')
            ->whereIn('user_id', $ids)
            ->groupBy('user_id')
            ->selectRaw('user_id, MAX(created_at) as at')
            ->pluck('at', 'user_id')
            ->map(fn ($at) => Carbon::parse($at)->toIso8601String())
            ->all();
    }

    private function endSessions(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();
    }

    private function temporaryPassword(): string
    {
        // 12 letters and digits, no symbols, easy to read out over the phone.
        return Str::password(12, symbols: false);
    }

    private function formOptions(Request $request): array
    {
        $actor = $request->user();

        return [
            'branches' => $actor->accessibleBranches()->map->only(['id', 'name', 'code', 'is_head_office'])->values(),
            'branchRoles' => array_map(fn (Role $r) => $r->value, Role::branchRoles()),
            'canAssignAdminRoles' => $actor->can('assignAdminRoles', User::class),
            'canGrantBranchManagement' => $actor->can('grantBranchManagement', User::class),
            'roleSummaries' => RoleGuide::summaries(),
            'roleGuide' => RoleGuide::rows(),
        ];
    }
}
