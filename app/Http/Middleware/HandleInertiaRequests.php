<?php

namespace App\Http\Middleware;

use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use App\Domain\Tenancy\Role;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            // Closures run when the page renders, after the context has been set.
            'tenancy' => fn () => $this->tenancy($request),
            // One-time messages. "credentials" holds a temporary password, shown once.
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'credentials' => fn () => $request->session()->get('credentials'),
            ],
        ];
    }

    /** What the top bar needs: the business, the active office and the offices to switch to. */
    private function tenancy(Request $request): ?array
    {
        $user = $request->user();

        if (! $user || $user->tenant_id === null) {
            return null;
        }

        $context = app(TenantContext::class);

        return [
            'tenant' => $context->tenant()?->name,
            'branch' => $context->branch()?->only(['id', 'code', 'name']),
            'all_branches' => $context->viewingAllBranches(),
            'can_view_all' => $user->isTenantAdmin(),
            // Recording a transaction needs one office and at least a clerk's role in it.
            'can_record' => ! $context->viewingAllBranches()
                && $context->branchId() !== null
                && ($user->roleIn($context->branchId())?->atLeast(Role::Clerk) ?? false),
            'branches' => $user->accessibleBranches()->map->only(['id', 'code', 'name'])->values(),
            // The kinds of ledger this person may add here, so a form can offer "add new" only when it will work.
            'addable_ledger_kinds' => collect(LedgerKind::creatable())
                ->filter(fn (LedgerKind $kind) => $user->can('create', [Ledger::class, $kind->value]))
                ->map(fn (LedgerKind $kind) => ['value' => $kind->value, 'label' => $kind->label()])
                ->values(),
        ];
    }
}
