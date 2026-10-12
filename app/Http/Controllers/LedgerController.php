<?php

namespace App\Http\Controllers;

use App\Domain\Ledgers\ChartOfAccounts;
use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use App\Domain\Tenancy\TenantContext;
use App\Http\Requests\SaveLedgerRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LedgerController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Ledger::class);

        $user = $request->user();
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'kind' => (string) $request->query('kind', ''),
            'show_off' => $request->boolean('show_off'),
        ];

        $ledgers = Ledger::query()
            ->with(['branch', 'group.parent'])
            ->when($filters['q'] !== '', fn ($q) => $q->where(
                fn ($w) => $w->where('name', 'like', '%'.$filters['q'].'%')->orWhere('phone', 'like', '%'.$filters['q'].'%')
            ))
            ->when(LedgerKind::tryFrom($filters['kind']) !== null, fn ($q) => $q->where('kind', $filters['kind']))
            ->when(! $filters['show_off'], fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Ledger $ledger) => [
                'id' => $ledger->id,
                'name' => $ledger->name,
                'phone' => $ledger->phone,
                'kind' => $ledger->kind->value,
                'kind_label' => $ledger->kind->label(),
                'group' => $ledger->group->path(),
                'office' => $ledger->is_shared ? 'All offices' : $ledger->branch?->name,
                'is_active' => $ledger->is_active,
                'is_system' => $ledger->is_system,
                'can_edit' => $user->can('update', $ledger),
                'can_toggle' => $user->can('toggle', $ledger),
            ]);

        return Inertia::render('Ledgers/Index', [
            'ledgers' => $ledgers,
            'filters' => $filters,
            'kinds' => collect(LedgerKind::cases())->map(fn (LedgerKind $k) => ['value' => $k->value, 'label' => $k->label()])->values(),
            'canCreate' => $user->can('createAny', Ledger::class),
        ]);
    }

    public function create(Request $request): Response
    {
        $kinds = $this->creatableKinds($request);

        abort_if($kinds === [], 403, 'You cannot add ledgers here.');

        return Inertia::render('Ledgers/Create', $this->formOptions($request, $kinds));
    }

    public function store(SaveLedgerRequest $request): RedirectResponse
    {
        $kind = LedgerKind::from($request->validated('kind'));
        $shared = $request->boolean('is_shared');
        $tenantId = $request->user()->tenant_id;

        $group = ChartOfAccounts::groupFor($tenantId, $kind);

        $ledger = Ledger::create([
            'tenant_id' => $tenantId,
            'branch_id' => $request->resolvedBranchId(),
            'is_shared' => $shared,
            'group_id' => $group->id,
            'kind' => $kind,
            'name' => $request->validated('name'),
            'phone' => $request->validated('phone'),
        ]);

        return redirect()->route('ledgers.index')->with('success', "{$ledger->name} was added.");
    }

    public function edit(Request $request, Ledger $ledger): Response
    {
        $this->authorize('update', $ledger);

        return Inertia::render('Ledgers/Edit', [
            'ledger' => [
                'id' => $ledger->id,
                'name' => $ledger->name,
                'phone' => $ledger->phone,
                'kind_label' => $ledger->kind->label(),
                'office' => $ledger->is_shared ? 'All offices' : $ledger->branch?->name,
            ],
        ]);
    }

    public function update(SaveLedgerRequest $request, Ledger $ledger): RedirectResponse
    {
        $ledger->update($request->safe()->only(['name', 'phone']));

        return redirect()->route('ledgers.index')->with('success', "Saved changes to {$ledger->name}.");
    }

    public function toggle(Request $request, Ledger $ledger): RedirectResponse
    {
        $this->authorize('toggle', $ledger);

        $ledger->forceFill(['is_active' => ! $ledger->is_active])->save();

        return back()->with('success', $ledger->is_active
            ? "{$ledger->name} is switched on."
            : "{$ledger->name} is switched off. It stays in reports, but cannot be used in new transactions.");
    }

    /** @return list<LedgerKind> */
    private function creatableKinds(Request $request): array
    {
        return array_values(array_filter(
            LedgerKind::creatable(),
            fn (LedgerKind $k) => $request->user()->can('create', [Ledger::class, $k->value]),
        ));
    }

    private function formOptions(Request $request, array $kinds): array
    {
        $context = app(TenantContext::class);

        return [
            'kinds' => array_map(fn (LedgerKind $k) => ['value' => $k->value, 'label' => $k->label(), 'help' => $k->help()], $kinds),
            'canShare' => $request->user()->isTenantAdmin(),
            'chooseOffice' => $context->viewingAllBranches(),
            'activeOffice' => $context->branch()?->name,
            'branches' => $context->viewingAllBranches()
                ? $request->user()->accessibleBranches()->map->only(['id', 'name'])->values()
                : [],
        ];
    }
}
