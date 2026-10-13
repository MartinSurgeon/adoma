<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Documents\Document;
use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The home page. Shows what the signed-in person's office has, and the one thing
 * they are most likely to do next. Every number respects the same office scoping
 * as the rest of the app, so an office never sees another office's figures.
 */
class DashboardController extends Controller
{
    private const STATS = [
        ['kind' => LedgerKind::Customer, 'label' => 'Customers'],
        ['kind' => LedgerKind::Supplier, 'label' => 'Suppliers'],
        ['kind' => LedgerKind::Bank, 'label' => 'Banks'],
        ['kind' => LedgerKind::Cash, 'label' => 'Cash accounts'],
    ];

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $context = app(TenantContext::class);

        $counts = Ledger::query()
            ->where('is_active', true)
            ->selectRaw('kind, count(*) as total')
            ->groupBy('kind')
            ->pluck('total', 'kind');

        $recent = Ledger::query()
            ->with('branch')
            ->where('is_system', false)
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(fn (Ledger $ledger) => [
                'id' => $ledger->id,
                'name' => $ledger->name,
                'kind_label' => $ledger->kind->label(),
                'office' => $ledger->is_shared ? 'All offices' : $ledger->branch?->name,
                'added' => $ledger->created_at->toIso8601String(),
            ]);

        return Inertia::render('Dashboard', [
            'where' => $context->viewingAllBranches() ? 'All offices' : $context->branch()?->name,
            'stats' => collect(self::STATS)->map(fn (array $stat) => [
                'label' => $stat['label'],
                'count' => (int) ($counts[$stat['kind']->value] ?? 0),
                'href' => route('ledgers.index', ['kind' => $stat['kind']->value]),
            ])->values(),
            'recent' => $recent,
            'canCreate' => $user->can('createAny', Ledger::class),
            'canRecord' => $user->can('create', [Document::class, 'receipt']),
            'activity' => $user->can('viewAny', AuditLog::class)
                ? AuditLog::query()->orderByDesc('id')->limit(5)->get()->map(fn (AuditLog $log) => [
                    'id' => $log->id,
                    'actor' => $log->actor_name ?? 'The system',
                    'description' => $log->describe(),
                    'when' => $log->created_at->toIso8601String(),
                ])
                : null,
        ]);
    }
}
