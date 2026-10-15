<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Ledgers\Ledger;
use App\Domain\Tenancy\Branch;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** A read-only record of who did what, for owners and admins. */
class ActivityController extends Controller
{
    private const SIGN_IN_EVENTS = ['login', 'logout', 'login_failed'];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', AuditLog::class);

        $type = (string) $request->query('type', '');

        $logs = AuditLog::query()
            ->when($type === 'ledger', fn ($q) => $q->where('auditable_type', Ledger::class))
            ->when($type === 'user', fn ($q) => $q->where('auditable_type', User::class)->whereNotIn('event', self::SIGN_IN_EVENTS))
            ->when($type === 'branch', fn ($q) => $q->where('auditable_type', Branch::class))
            ->when($type === 'signin', fn ($q) => $q->whereIn('event', self::SIGN_IN_EVENTS))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'when' => $log->created_at->toIso8601String(),
                'actor' => $log->actor_name ?? 'The system',
                'description' => $log->describe(),
                'changes' => in_array($log->event, ['updated', 'access_changed'], true)
                    ? collect($log->changes())->map(fn ($pair, $field) => [
                        'field' => AuditLog::fieldLabel($field),
                        'old' => $pair[0],
                        'new' => $pair[1],
                    ])->values()
                    : [],
            ]);

        return Inertia::render('Activity/Index', [
            'logs' => $logs,
            'type' => $type,
            'types' => [
                ['value' => 'ledger', 'label' => 'Ledgers'],
                ['value' => 'user', 'label' => 'Users'],
                ['value' => 'branch', 'label' => 'Branches'],
                ['value' => 'signin', 'label' => 'Sign-ins'],
            ],
        ]);
    }
}
