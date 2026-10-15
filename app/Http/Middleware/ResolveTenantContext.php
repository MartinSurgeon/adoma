<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Works out which business and office this request is for, and checks the
 * signed-in user may work there. The business always comes from the user,
 * never from the request. The branch comes from the X-Branch-Id header or
 * the session, and is always checked against what the user may access.
 */
class ResolveTenantContext
{
    public function __construct(private TenantContext $context)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->tenant_id === null) {
            // No business: scoped models return nothing.
            return $next($request);
        }

        abort_unless($user->is_active, 403, 'This account is switched off.');

        $tenant = $user->tenant;
        abort_unless($tenant && $tenant->isActive(), 403, 'This business account is not active.');

        $branches = $user->accessibleBranches();
        $header = $request->header('X-Branch-Id');
        $requested = $header ?? ($request->hasSession() ? $request->session()->get('active_branch_id') : null);

        if ($requested === 'all') {
            abort_unless($user->isTenantAdmin(), 403, 'Only admins can view all branches.');
            $this->context->set($tenant, null, true);

            return $next($request);
        }

        if ($requested !== null) {
            $branch = $branches->firstWhere('id', (int) $requested);

            // A header naming a branch the user cannot use is an error.
            // A stale session value just falls back to the default office.
            abort_if($branch === null && $header !== null, 403, 'You do not have access to this branch.');
        }

        $this->context->set($tenant, $branch ?? $branches->first());

        return $next($request);
    }
}
