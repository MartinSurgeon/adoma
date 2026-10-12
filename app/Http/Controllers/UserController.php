<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\Role;
use App\Http\Requests\SaveUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $actor = $request->user();

        $users = User::where('tenant_id', $actor->tenant_id)
            ->with(['branches' => fn ($q) => $q->withoutGlobalScopes()])
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'must_change_password' => $user->must_change_password,
                'tenant_role' => $user->tenant_role,
                'offices' => $user->branches->map(fn ($b) => ['name' => $b->name, 'role' => $b->pivot->role])->values(),
                'can_manage' => $actor->can('manage', $user),
            ]);

        return Inertia::render('Settings/Users/Index', ['users' => $users]);
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
            'is_active' => true,
            'must_change_password' => true,
            'email_verified_at' => now(),
        ])->save();

        $this->syncOffices($user, $request);

        return redirect()->route('users.index')
            ->with('success', "Account created for {$user->name}.")
            ->with('credentials', ['name' => $user->name, 'email' => $user->email, 'password' => $temporary]);
    }

    public function edit(Request $request, int $user): Response
    {
        $target = $this->managed($request, $user);

        return Inertia::render('Settings/Users/Edit', [
            ...$this->formOptions($request),
            'user' => [
                'id' => $target->id,
                'name' => $target->name,
                'email' => $target->email,
                'tenant_role' => $target->tenant_role,
                'offices' => $target->branches()->withoutGlobalScopes()->get()
                    ->map(fn ($b) => ['branch_id' => $b->id, 'role' => $b->pivot->role])->values(),
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
        ])->save();

        $this->syncOffices($target, $request);

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

    private function syncOffices(User $user, SaveUserRequest $request): void
    {
        // Owners and admins reach every office without rows of their own.
        $offices = $user->isTenantAdmin() ? [] : collect($request->validated('offices', []))
            ->mapWithKeys(fn ($o) => [(int) $o['branch_id'] => ['role' => $o['role']]])
            ->all();

        $user->branches()->sync($offices);
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
            'branches' => $actor->accessibleBranches()->map->only(['id', 'name', 'code'])->values(),
            'branchRoles' => array_map(fn (Role $r) => $r->value, Role::branchRoles()),
            'canAssignAdminRoles' => $actor->can('assignAdminRoles', User::class),
        ];
    }
}
