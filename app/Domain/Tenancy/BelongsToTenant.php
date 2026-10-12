<?php

namespace App\Domain\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Scopes a model to the current business and stamps tenant_id on create.
 * Fails closed: with no business in context, queries match no rows.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $query) {
            $tenantId = app(TenantContext::class)->tenantId();
            $column = $query->getModel()->getTable().'.tenant_id';

            $tenantId === null
                ? $query->whereRaw('1 = 0')
                : $query->where($column, $tenantId);
        });

        static::creating(function (Model $model) {
            $contextId = app(TenantContext::class)->tenantId();

            if ($model->tenant_id === null) {
                if ($contextId === null) {
                    throw new TenancyViolation(
                        class_basename($model).' cannot be created without a business in context.'
                    );
                }

                $model->tenant_id = $contextId;

                return;
            }

            // An explicit tenant_id is allowed with no context (seeders, imports),
            // but never one that differs from the business we are working for.
            if ($contextId !== null && (int) $model->tenant_id !== $contextId) {
                throw new TenancyViolation(
                    class_basename($model).' cannot be created for a different business.'
                );
            }
        });
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
