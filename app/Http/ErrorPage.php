<?php

namespace App\Http;

use App\Domain\Documents\Document;
use App\Domain\Ledgers\Ledger;
use App\Domain\Tenancy\TenantContext;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Errors as a page of the app: what happened, in plain words, and a way on.
 *
 * Without this, Laravel answers with its bare error page, and Inertia shows that in a dark box over the
 * screen. Requests for JSON keep their JSON errors. While the app is being built (debug on), a crash still
 * shows its full technical detail, which is what a developer needs.
 *
 * Not found and not allowed replace the page: it cannot be used. A passing trouble (a crash, the system being
 * updated) replaces a page that was being opened, but never a form that was being sent: Inertia's box then
 * opens over the form, and closing it leaves the form and everything typed into it, ready to send again.
 * (An expired page, 419, only happens when a form is sent, so it is left to that box too.)
 */
final class ErrorPage
{
    private const PAGE_UNUSABLE = [403, 404];

    private const PASSING = [500, 503];

    public static function respond(Response $response, Throwable $e, Request $request): Response
    {
        $status = $response->getStatusCode();
        $shown = in_array($status, self::PAGE_UNUSABLE, true) || ($request->isMethodSafe() && in_array($status, self::PASSING, true));

        if (! $shown || ($request->expectsJson() && ! $request->inertia())) {
            return $response;
        }

        if ($status >= 500 && config('app.debug')) {
            return $response;
        }

        return AfterBranchSwitch::landing($request, $e, $status) ?? self::page($request, $e, $status);
    }

    private static function page(Request $request, Throwable $e, int $status): Response
    {
        // An address naming a missing record fails before the page's shared data is gathered. Gather it here,
        // so someone signed in still has the menu and the branch switcher around the message. The version too,
        // or the next click would reload the whole app.
        $inertia = app(HandleInertiaRequests::class);
        Inertia::version(fn () => $inertia->version($request));

        if ($request->hasSession()) {
            Inertia::share($inertia->share($request));
        }

        // A form that failed to send keeps the address of the page it was sent from, so reloading shows that page
        // again rather than asking the browser to open a send-only address.
        if (! $request->isMethodSafe()) {
            Inertia::resolveUrlUsing(fn () => Str::start(Str::after(url()->previous(), $request->getSchemeAndHttpHost()), '/'));
        }

        try {
            $page = Inertia::render('Error', ['status' => $status, ...self::words($request, $e, $status)]);
        } finally {
            Inertia::resolveUrlUsing(null);
        }

        return $page->toResponse($request)->setStatusCode($status);
    }

    /** @return array{title: string, message: string, primary: array, secondary: ?array} */
    private static function words(Request $request, Throwable $e, int $status): array
    {
        $context = app(TenantContext::class);
        $home = ['label' => 'Go to Home', 'href' => route('dashboard')];

        return match ($status) {
            404 => self::notFound($request, $e, $context, $home),
            403 => [
                'title' => 'You cannot open this page',
                'message' => self::ownReason($e) ?? self::notAllowed($context),
                // Signed in, but stopped before any branch was set (the account or business is switched off):
                // every page answers the same, so Home would lead straight back here. Signing out is the way on.
                'primary' => $request->user() !== null && $context->tenant() === null
                    ? ['label' => 'Log out', 'logout' => true]
                    : $home,
                'secondary' => null,
            ],
            503 => [
                'title' => 'The system is being updated',
                'message' => 'It will be back in a few minutes. Try again shortly.',
                'primary' => ['label' => 'Try again', 'reload' => true],
                'secondary' => null,
            ],
            default => [
                'title' => 'Something went wrong',
                'message' => 'The system could not finish this. Try again. If it keeps happening, tell whoever looks after the system.',
                'primary' => ['label' => 'Try again', 'reload' => true],
                'secondary' => $home,
            ],
        };
    }

    /**
     * A transaction or a ledger lives in one branch, so the usual reason one cannot be found is that it belongs
     * to another. Say so, to someone who can switch, and offer its list.
     */
    private static function notFound(Request $request, Throwable $e, TenantContext $context, array $home): array
    {
        $missing = $e->getPrevious() instanceof ModelNotFoundException ? $e->getPrevious()->getModel() : null;

        [$title, $list] = match ($missing) {
            Document::class => ['Transaction not found', ['label' => 'Go to transactions', 'href' => route('documents.index')]],
            Ledger::class => ['Ledger not found', ['label' => 'Go to ledgers', 'href' => route('ledgers.index')]],
            default => ['Page not found', null],
        };

        $user = $request->user();
        $branch = $context->viewingAllBranches() ? null : $context->branch();
        $canSwitch = $user !== null && ($user->isTenantAdmin() || $user->accessibleBranches()->count() > 1);

        return [
            'title' => $title,
            'message' => $list !== null && $branch !== null && $canSwitch
                ? "It is not in {$branch->name}. If it belongs to another branch, choose that branch at the top of the page, then open it again."
                : 'The link may be wrong or out of date.',
            'primary' => $list ?? $home,
            'secondary' => $list !== null ? $home : null,
        ];
    }

    /** A reason the app gave in its own words (abort(403, '...')), rather than a permission check's stock reply. */
    private static function ownReason(Throwable $e): ?string
    {
        return $e instanceof HttpExceptionInterface && $e->getPrevious() === null && $e->getMessage() !== ''
            ? $e->getMessage()
            : null;
    }

    private static function notAllowed(TenantContext $context): string
    {
        if ($context->viewingAllBranches()) {
            return 'This page works in one branch at a time. Choose one branch at the top of the page, then try again.';
        }

        $branch = $context->branch()?->name;

        return $branch !== null
            ? "Your role in $branch does not allow this. If you need it, ask an owner or admin."
            : 'You do not have access to this page.';
    }
}
