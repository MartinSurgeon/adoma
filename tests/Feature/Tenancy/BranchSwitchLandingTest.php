<?php

use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentType;
use App\Domain\Documents\PostingService;
use App\Domain\Ledgers\ChartOfAccounts;
use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use App\Domain\Tenancy\Branch;
use App\Domain\Tenancy\Role;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Tests\Support\World;

/*
 * Switching branch goes back to the page you were on, now for the new branch. A page that cannot come along
 * (the old branch's transaction or ledger, or a form your role in the new branch does not allow) lands on its
 * list, or Home, with a note saying why: never on an error.
 */

beforeEach(function () {
    $this->w = World::build();
    $this->customer = switchLedger($this->w->kumasi, 'Passion Gold', LedgerKind::Customer);
    $this->cash = switchLedger($this->w->kumasi, 'Cash Kumasi', LedgerKind::Cash);
});

function switchLedger(Branch $branch, string $name, LedgerKind $kind): Ledger
{
    return Ledger::create([
        'tenant_id' => $branch->tenant_id, 'branch_id' => $branch->id, 'is_shared' => false,
        'group_id' => ChartOfAccounts::groupFor($branch->tenant_id, $kind)->id, 'kind' => $kind, 'name' => $name,
    ]);
}

/** A receipt recorded in Kumasi. */
function switchReceipt(object $t): Document
{
    return app(TenantContext::class)->run($t->w->adoma, $t->w->kumasi, fn () => app(PostingService::class)->postCash(
        DocumentType::Receipt, $t->w->kumasi, now()->toDateString(), $t->customer, $t->cash, 100000, 'Part payment', null, $t->w->kumasiClerk,
    ));
}

it('takes you to the transactions list when the transaction you had open stays in the branch you left', function () {
    $receipt = switchReceipt($this);
    $this->actingAs($this->w->owner)->withSession(['active_branch_id' => $this->w->kumasi->id]);
    $this->get(route('documents.show', $receipt))->assertOk();

    // The bug: back to /transactions/{id}, which Central cannot find, so a 404 in a dark box.
    $this->from(route('documents.show', $receipt))
        ->post(route('branch.switch'), ['branch' => $this->w->central->id])
        ->assertRedirect(route('documents.show', $receipt));

    $this->get(route('documents.show', $receipt))
        ->assertRedirect(route('documents.index'))
        ->assertSessionHas('notice', 'You are now working in Central. The transaction you had open belongs to another branch.');

    $this->get(route('documents.index'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('Documents/Index')
        ->where('flash.notice', 'You are now working in Central. The transaction you had open belongs to another branch.'));

    // The note is said once.
    $this->get(route('documents.index'))->assertInertia(fn ($page) => $page->where('flash.notice', null));
});

it('stays on the transaction when the new choice can still see it', function () {
    $receipt = switchReceipt($this);
    $this->actingAs($this->w->owner)->withSession(['active_branch_id' => $this->w->kumasi->id]);

    $this->from(route('documents.show', $receipt))
        ->post(route('branch.switch'), ['branch' => 'all'])
        ->assertRedirect(route('documents.show', $receipt));

    $this->get(route('documents.show', $receipt))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Documents/Show')->where('flash.notice', null));
});

it('takes you to the ledgers list when the ledger you had open belongs to the branch you left', function () {
    $this->actingAs($this->w->owner)->withSession(['active_branch_id' => $this->w->kumasi->id]);

    $this->from(route('ledgers.statement', $this->customer))
        ->post(route('branch.switch'), ['branch' => $this->w->central->id]);

    $this->get(route('ledgers.statement', $this->customer))
        ->assertRedirect(route('ledgers.index'))
        ->assertSessionHas('notice', 'You are now working in Central. The ledger you had open belongs to another branch.');
});

it('takes you Home from a page that works in one branch at a time, once you look at all branches', function () {
    $this->actingAs($this->w->owner)->withSession(['active_branch_id' => $this->w->kumasi->id]);
    $this->get(route('transfers.incoming'))->assertOk();

    $this->from(route('transfers.incoming'))->post(route('branch.switch'), ['branch' => 'all']);

    $this->get(route('transfers.incoming'))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('notice', 'You are now looking at all branches. The page you had open works in one branch at a time.');
});

it('keeps you on a form that asks you to choose one branch, once you look at all branches', function () {
    $this->actingAs($this->w->owner)->withSession(['active_branch_id' => $this->w->kumasi->id]);

    $this->from(route('sales.create'))->post(route('branch.switch'), ['branch' => 'all']);

    $this->get(route('sales.create'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Documents/CreateGold')->where('needsOffice', true));
});

it('takes you Home from a form your role in the new branch does not allow', function () {
    // A manager in Kumasi who may only look in Central.
    $both = User::factory()->make();
    $both->forceFill(['tenant_id' => $this->w->adoma->id])->save();
    $both->branches()->attach($this->w->kumasi->id, ['role' => Role::Manager->value]);
    $both->branches()->attach($this->w->central->id, ['role' => Role::Viewer->value]);

    $this->actingAs($both)->withSession(['active_branch_id' => $this->w->kumasi->id]);
    $this->get(route('transfers.create'))->assertOk();

    $this->from(route('transfers.create'))->post(route('branch.switch'), ['branch' => $this->w->central->id]);

    $this->get(route('transfers.create'))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('notice', 'You are now working in Central. Your role here does not allow the page you had open.');
});

it('keeps you on a list, from its first page, since the new branch has pages of its own', function () {
    $this->actingAs($this->w->owner)->withSession(['active_branch_id' => $this->w->kumasi->id]);

    $this->from(route('documents.index', ['type' => 'receipt', 'page' => 3]))
        ->post(route('branch.switch'), ['branch' => $this->w->central->id])
        ->assertRedirect(route('documents.index', ['type' => 'receipt']));

    $this->get(route('documents.index', ['type' => 'receipt']))->assertOk()
        ->assertInertia(fn ($page) => $page->where('flash.notice', null));
});

it('shows a real error, not a detour, when you were not switching', function () {
    $receipt = switchReceipt($this);

    $this->actingAs($this->w->owner)->withSession(['active_branch_id' => $this->w->central->id])
        ->get(route('documents.show', $receipt))
        ->assertNotFound()
        ->assertInertia(fn ($page) => $page->component('Error'));
});
