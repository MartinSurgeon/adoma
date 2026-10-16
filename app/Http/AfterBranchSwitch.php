<?php

namespace App\Http;

use App\Domain\Documents\Document;
use App\Domain\Ledgers\Ledger;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Throwable;

/**
 * Where you land after switching branch when the page you were on cannot come with you.
 *
 * Switching branch takes you back to the page you were on, now for the new branch. Most pages come along:
 * a list, a report, the stock. Some cannot. A transaction, or a branch's own ledger, stays in the branch you
 * left, so the new branch cannot find it. A page may need a role you do not have in the new branch, or one
 * branch when you chose all of them (accepting incoming gold). Such a page sends you on to its list
 * (Transactions, Ledgers), or Home when it has none, with a note saying why. Never to an error.
 */
final class AfterBranchSwitch
{
    /** Flashed by the switch, so it holds for the one page load that follows it. */
    public const FLAG = 'branch_just_switched';

    public static function landing(Request $request, Throwable $e, int $status): ?RedirectResponse
    {
        if (! in_array($status, [403, 404], true) || ! $request->isMethod('GET')
            || ! $request->hasSession() || ! $request->session()->get(self::FLAG)) {
            return null;
        }

        $context = app(TenantContext::class);

        // Stopped before a branch was even set (an account switched off, say): a real error, shown as one.
        if ($context->tenant() === null) {
            return null;
        }

        $page = $request->route()?->getName() ?? '';
        $list = Str::before($page, '.').'.index';
        $to = $list !== $page && Route::has($list) ? $list : 'dashboard';

        if ($to === $page) {
            return null;
        }

        // Should the list fail as well, the next stop is Home.
        if ($to !== 'dashboard') {
            $request->session()->flash(self::FLAG, true);
        }

        return redirect()->route($to)->with('notice', self::note($context, $e, $status));
    }

    /** Where you are now, and why you are not on the page you had open. */
    private static function note(TenantContext $context, Throwable $e, int $status): string
    {
        $all = $context->viewingAllBranches();
        $now = $all ? 'You are now looking at all branches.' : 'You are now working in '.($context->branch()?->name ?? 'another branch').'.';

        $missing = $e->getPrevious() instanceof ModelNotFoundException ? $e->getPrevious()->getModel() : null;

        $why = match (true) {
            $status === 403 && $all => 'The page you had open works in one branch at a time.',
            $status === 403 => 'Your role here does not allow the page you had open.',
            $missing === Document::class => 'The transaction you had open belongs to another branch.',
            $missing === Ledger::class => 'The ledger you had open belongs to another branch.',
            default => 'The page you had open is not in this branch.',
        };

        return "$now $why";
    }
}
