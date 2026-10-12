<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accounts an admin created or reset carry a temporary password.
 * Until the person chooses their own, the only pages they can use are
 * the change-password page and sign out.
 */
class EnsurePasswordIsChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password && ! $request->routeIs('password.force.*', 'logout')) {
            abort_if($request->expectsJson(), 403, 'You must choose a new password first.');

            return redirect()->route('password.force.edit');
        }

        return $next($request);
    }
}
