<?php

namespace App\Providers;

use App\Domain\Ledgers\Ledger;
use App\Policies\LedgerPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped, not singleton: a fresh, empty context for every request, job and test.
        $this->app->scoped(\App\Domain\Tenancy\TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Ledger lives under app/Domain, so Laravel cannot guess its policy.
        Gate::policy(Ledger::class, LedgerPolicy::class);
    }
}
