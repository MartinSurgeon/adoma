<?php

namespace App\Domain\Ledgers;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\Branch;
use App\Domain\Tenancy\BelongsToTenant;
use App\Domain\Tenancy\TenancyViolation;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An account: a customer, supplier, bank, cash account, expense, another branch,
 * or a system account such as Sales.
 *
 * Who can see it:
 *   - a branch ledger is seen by its own branch
 *   - a shared ledger (is_shared, no branch) is seen by every branch of the business
 *   - owners and admins viewing all branches see everything in their business
 * With no business or office in context nothing is visible.
 */
class Ledger extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'branch_id', 'is_shared', 'group_id', 'kind', 'name', 'phone',
        'linked_branch_id', 'system_key', 'is_system',
    ];

    /**
     * In-memory defaults that match the database defaults, so a ledger that was just
     * created behaves the same as one loaded from the database.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'is_shared' => false,
        'is_system' => false,
    ];

    protected function casts(): array
    {
        return [
            'kind' => LedgerKind::class,
            'is_shared' => 'boolean',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('visibility', function (Builder $query) {
            $context = app(TenantContext::class);

            if ($context->tenantId() === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            if ($context->viewingAllBranches()) {
                return;
            }

            $branchId = $context->branchId();

            if ($branchId === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $table = $query->getModel()->getTable();
            $query->where(fn (Builder $w) => $w->where("$table.branch_id", $branchId)->orWhereNull("$table.branch_id"));
        });

        static::creating(function (Ledger $ledger) {
            if ($ledger->is_shared) {
                if ($ledger->branch_id !== null) {
                    throw new TenancyViolation('A shared ledger cannot belong to one branch.');
                }

                return;
            }

            if ($ledger->branch_id === null) {
                throw new TenancyViolation('A ledger needs a branch, or must be marked as shared.');
            }

            $context = app(TenantContext::class);

            if (! $context->viewingAllBranches()
                && $context->branchId() !== null
                && (int) $ledger->branch_id !== $context->branchId()) {
                throw new TenancyViolation('A ledger cannot be created for a different branch.');
            }

            $sameBusiness = Branch::withoutGlobalScopes()
                ->whereKey($ledger->branch_id)
                ->where('tenant_id', $ledger->tenant_id)
                ->exists();

            if (! $sameBusiness) {
                throw new TenancyViolation('The branch does not belong to this business.');
            }
        });

        // What a ledger is, where it lives and which group it sits in never change.
        // Only its name, phone and active flag do.
        static::updating(function (Ledger $ledger) {
            $locked = ['tenant_id', 'branch_id', 'is_shared', 'kind', 'group_id', 'linked_branch_id', 'system_key', 'is_system'];

            if ($ledger->isDirty($locked)) {
                throw new TenancyViolation('A ledger cannot be moved or changed to another kind.');
            }
        });
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(LedgerGroup::class, 'group_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function linkedBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'linked_branch_id');
    }
}
