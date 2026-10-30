<?php

use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentType;
use App\Domain\Documents\PostingService;
use App\Domain\Ledgers\ChartOfAccounts;
use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use App\Domain\Tenancy\TenantContext;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Support\Facades\Route;
use Tests\Support\World;

/*
 * Errors are a page of the app, in plain words, with a way on. Never Laravel's bare error page, which Inertia
 * shows in a dark box over the screen. Requests for JSON keep their JSON errors.
 */

beforeEach(function () {
    $this->w = World::build();
});

/** A receipt recorded in Kumasi. */
function errorPageReceipt(World $w): Document
{
    $ledger = fn (string $name, LedgerKind $kind) => Ledger::create([
        'tenant_id' => $w->adoma->id, 'branch_id' => $w->kumasi->id, 'is_shared' => false,
        'group_id' => ChartOfAccounts::groupFor($w->adoma->id, $kind)->id, 'kind' => $kind, 'name' => $name,
    ]);

    return app(TenantContext::class)->run($w->adoma, $w->kumasi, fn () => app(PostingService::class)->postCash(
        DocumentType::Receipt, $w->kumasi, now()->toDateString(), $ledger('Passion Gold', LedgerKind::Customer),
        $ledger('Cash Kumasi', LedgerKind::Cash), 100000, 'Part payment', null, $w->kumasiClerk,
    ));
}

it('says a transaction from another branch is not found, and how to reach it', function () {
    $receipt = errorPageReceipt($this->w);

    $this->actingAs($this->w->owner)->withSession(['active_branch_id' => $this->w->central->id])
        ->get(route('documents.show', $receipt))
        ->assertNotFound()
        ->assertInertia(fn ($page) => $page
            ->component('Error')
            ->where('status', 404)
            ->where('title', 'Transaction not found')
            ->where('message', 'It is not in Central. If it belongs to another branch, choose that branch at the top of the page, then open it again.')
            ->where('primary', ['label' => 'Go to transactions', 'href' => route('documents.index')])
            ->where('secondary', ['label' => 'Go to Home', 'href' => route('dashboard')])
            // The record is looked up before the page's shared data is gathered: the frame still gets it.
            ->where('tenancy.branch.name', 'Central')
            ->where('auth.user.id', $this->w->owner->id));
});

it('does not suggest switching to someone with one branch', function () {
    $receipt = errorPageReceipt($this->w);

    $this->actingAs($this->w->centralManager)
        ->get(route('documents.show', $receipt))
        ->assertNotFound()
        ->assertInertia(fn ($page) => $page->where('message', 'The link may be wrong or out of date.'));
});

it('answers Inertia with a page it can show, not a bare error', function () {
    $receipt = errorPageReceipt($this->w);

    $this->actingAs($this->w->owner)->withSession(['active_branch_id' => $this->w->central->id])
        ->get(route('documents.show', $receipt), ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
        ->assertNotFound()
        ->assertHeader('X-Inertia', 'true')
        ->assertJsonPath('component', 'Error');
});

it('keeps the page address when a form sent from a stale page fails', function () {
    $receipt = errorPageReceipt($this->w);
    $this->actingAs($this->w->owner)->withSession(['active_branch_id' => $this->w->central->id]);

    $this->from(route('documents.show', $receipt))
        ->post(route('documents.reverse', $receipt), ['reason' => 'Mistake'], ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
        ->assertNotFound()
        ->assertJsonPath('component', 'Error')
        ->assertJsonPath('url', '/transactions/'.$receipt->id);

    // Only that one answer: the next page has its own address.
    $this->get(route('documents.index'))->assertOk()->assertInertia(fn ($page) => $page->url('/transactions'));
});

it('gives the reason the app wrote, when it wrote one', function () {
    $this->actingAs($this->w->kumasiViewer)
        ->get(route('ledgers.create'))
        ->assertForbidden()
        ->assertInertia(fn ($page) => $page
            ->component('Error')
            ->where('title', 'You cannot open this page')
            ->where('message', 'You cannot add ledgers here.')
            ->where('primary', ['label' => 'Go to Home', 'href' => route('dashboard')]));
});

it('explains a refusal in words of its own when the reason is only a permission check', function () {
    $this->actingAs($this->w->kumasiViewer)
        ->get(route('sales.create'))
        ->assertForbidden()
        ->assertInertia(fn ($page) => $page->where('message', 'Your role in Kumasi does not allow this. If you need it, ask an owner or admin.'));

    $this->actingAs($this->w->owner)->withSession(['active_branch_id' => 'all'])
        ->get(route('transfers.incoming'))
        ->assertForbidden()
        ->assertInertia(fn ($page) => $page->where('message', 'This page works in one branch at a time. Choose one branch at the top of the page, then try again.'));
});

it('shows an unknown address on its own, to someone signed out', function () {
    $this->get('/no-such-page')
        ->assertNotFound()
        ->assertInertia(fn ($page) => $page
            ->component('Error')
            ->where('title', 'Page not found')
            ->where('primary', ['label' => 'Go to Home', 'href' => route('dashboard')])
            ->missing('auth')
            // The app's version, so the next click is a normal one rather than a reload of the whole app.
            ->version((string) app(HandleInertiaRequests::class)->version(request())));
});

it('shows a crash as a page in use, and in full detail while the app is built', function () {
    Route::middleware('web')->get('/_crash', fn () => throw new RuntimeException('Boom'));

    config(['app.debug' => false]);
    $this->get('/_crash')->assertStatus(500)->assertInertia(fn ($page) => $page
        ->component('Error')
        ->where('title', 'Something went wrong')
        ->where('primary', ['label' => 'Try again', 'reload' => true]));

    config(['app.debug' => true]);
    $this->get('/_crash')->assertStatus(500)->assertSee('Boom');
});

it('never replaces a form that crashed while sending, so what was typed stays', function () {
    Route::middleware('web')->post('/_crash', fn () => throw new RuntimeException('Boom'));
    config(['app.debug' => false]);

    // Not an Inertia page: Inertia opens its box over the form instead, and closing it leaves the form as it was.
    $this->post('/_crash', [], ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
        ->assertStatus(500)
        ->assertHeaderMissing('X-Inertia');
});

it('keeps JSON errors for requests that ask for JSON', function () {
    $receipt = errorPageReceipt($this->w);

    $this->actingAs($this->w->owner)->withSession(['active_branch_id' => $this->w->central->id])
        ->getJson(route('documents.show', $receipt))
        ->assertNotFound()
        ->assertJsonMissingPath('component');
});

// A form sent from a page left open longer than a session lasts. Tests skip the real token check, so this stands in for it.
function expiredForm(): void
{
    Route::middleware('web')->post('/_expired', fn () => throw new \Illuminate\Session\TokenMismatchException('CSRF token mismatch.'));
}

it('opens the login page again, email kept, when it was left open too long', function () {
    expiredForm();

    $this->from('/login')->withHeader('X-Inertia', 'true')
        ->post('/_expired', ['email' => 'clerk@example.com', 'password' => 'secret'])
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'The page had been open a long time, so it was refreshed. Log in again.');

    $this->flushHeaders()->get(route('login'))->assertInertia(fn ($page) => $page
        ->component('Auth/Login')
        ->where('email', 'clerk@example.com')
        ->where('status', 'The page had been open a long time, so it was refreshed. Log in again.'));
});

it('sends someone still signed in back to the page, with a note, when it was left open too long', function () {
    expiredForm();

    $this->actingAs($this->w->owner)->from('/documents')->withHeader('X-Inertia', 'true')
        ->post('/_expired')
        ->assertRedirect('/documents')
        ->assertSessionHas('notice', 'The page had been open a long time, so it was refreshed. Try again.');
});

it('keeps the JSON answer for an expired page asked for as JSON', function () {
    expiredForm();

    $this->postJson('/_expired')->assertStatus(419);
});
