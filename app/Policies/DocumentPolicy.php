<?php

namespace App\Policies;

use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentType;
use App\Domain\Tenancy\Role;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Who may record, read and reverse transactions.
 *
 *  - Anyone with access to an office can read its transactions, and nobody else's.
 *  - A clerk, manager, admin or owner can record one, in the one office they are working in.
 *    Recording needs a single office: someone viewing all offices must choose one first.
 *  - Reversing needs a manager (or an admin or owner) in the document's office. A document can be
 *    reversed once, and a reversal cannot itself be reversed.
 */
class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        $context = app(TenantContext::class);

        if ($context->viewingAllBranches()) {
            return $user->isTenantAdmin();
        }

        return $context->branchId() !== null && $user->roleIn($context->branchId()) !== null;
    }

    public function view(User $user, Document $document): bool
    {
        return $user->roleIn($document->branch_id) !== null;
    }

    public function create(User $user, string $type = 'receipt'): bool
    {
        $documentType = DocumentType::tryFrom($type);

        if ($documentType === null || ! $documentType->isRecordable()) {
            return false;
        }

        $context = app(TenantContext::class);

        if ($context->viewingAllBranches() || $context->branchId() === null) {
            return false;
        }

        // Moving gold to another office is a manager's decision. Everything else a clerk may record.
        $minimum = $documentType === DocumentType::Transfer ? Role::Manager : Role::Clerk;

        return $user->roleIn($context->branchId())?->atLeast($minimum) ?? false;
    }

    public function reverse(User $user, Document $document): bool
    {
        if ($document->status !== Document::POSTED || $document->isReversal()) {
            return false;
        }

        // A transfer is put right by the office that sent it, which undoes both halves together.
        if ($document->type === DocumentType::TransferIn) {
            return false;
        }

        return $user->roleIn($document->branch_id)?->atLeast(Role::Manager) ?? false;
    }
}
