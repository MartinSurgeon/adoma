<?php

namespace App\Domain\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Scopes a model to the active branch and stamps branch_id on create.
 * Use it together with BelongsToTenant. Owners and admins viewing all branches
 * see every branch of their own business, never another business.
 * Fails closed: with no branch in context, queries match no rows.
 */
trait BelongsToBranch
{
    public static function bootBelongsToBranch(): void
    {
        static::addGlobalScope('branch', function (Builder $query) {
            $context = app(TenantContext::class);

            if ($context->viewingAllBranches() && $context->tenantId() !== null) {
                return;
            }

            $branchId = $context->branchId();
            $column = $query->getModel()->getTable().'.branch_id';

            $branchId === null
                ? $query->whereRaw('1 = 0')
                : $query->where($column, $branchId);
        });

        static::creating(function (Model $model) {
            $context = app(TenantContext::class);

            if ($model->branch_id === null) {
                if ($context->branchId() === null) {
                    throw new TenancyViolation(
                        class_basename($model).' needs a branch. Choose one before saving.'
                    );
                }

                $model->branch_id = $context->branchId();

                return;
            }

            // Someone working in one branch cannot write into another.
            if (! $context->viewingAllBranches()
                && $context->branchId() !== null
                && (int) $model->branch_id !== $context->branchId()) {
                throw new TenancyViolation(
                    class_basename($model).' cannot be created for a different branch.'
                );
            }

            // The branch must belong to the same business as the record.
            $sameBusiness = Branch::withoutGlobalScopes()
                ->whereKey($model->branch_id)
                ->where('tenant_id', $model->tenant_id ?? $context->tenantId())
                ->exists();

            if (! $sameBusiness) {
                throw new TenancyViolation('The branch does not belong to this business.');
            }
        });
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
