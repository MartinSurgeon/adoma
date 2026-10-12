<?php

namespace App\Domain\Tenancy;

/**
 * Holds the business and office the current request, job or command works for.
 * Models that use the tenancy traits read this to scope every query.
 * With no context set, scoped models return nothing: the scopes fail closed.
 */
final class TenantContext
{
    private ?Tenant $tenant = null;

    private ?Branch $branch = null;

    private bool $allBranches = false;

    public function set(Tenant $tenant, ?Branch $branch = null, bool $allBranches = false): void
    {
        if ($branch !== null && $branch->tenant_id !== $tenant->id) {
            throw new TenancyViolation('The office does not belong to this business.');
        }

        $this->tenant = $tenant;
        $this->branch = $allBranches ? null : $branch;
        $this->allBranches = $allBranches;
    }

    public function clear(): void
    {
        $this->tenant = null;
        $this->branch = null;
        $this->allBranches = false;
    }

    /** Run a callback inside a context, then restore the previous one. */
    public function run(Tenant $tenant, ?Branch $branch, callable $callback, bool $allBranches = false): mixed
    {
        $previous = [$this->tenant, $this->branch, $this->allBranches];

        $this->set($tenant, $branch, $allBranches);

        try {
            return $callback();
        } finally {
            [$this->tenant, $this->branch, $this->allBranches] = $previous;
        }
    }

    public function tenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function tenantId(): ?int
    {
        return $this->tenant?->id;
    }

    public function branch(): ?Branch
    {
        return $this->branch;
    }

    public function branchId(): ?int
    {
        return $this->branch?->id;
    }

    public function viewingAllBranches(): bool
    {
        return $this->allBranches;
    }
}
