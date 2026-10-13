<?php

namespace App\Providers;

use App\Domain\Audit\Audit;
use App\Domain\Audit\AuditLog;
use App\Domain\Documents\Document;
use App\Domain\Ledgers\Ledger;
use App\Models\User;
use App\Policies\AuditLogPolicy;
use App\Policies\DocumentPolicy;
use App\Policies\LedgerPolicy;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
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
        Gate::policy(AuditLog::class, AuditLogPolicy::class);
        Gate::policy(Document::class, DocumentPolicy::class);

        // Who signed in, who signed out, and failed attempts, in the same log as every change.
        Event::listen(Login::class, fn (Login $event) => Audit::record('login', $event->user, actor: $event->user));
        Event::listen(Logout::class, fn (Logout $event) => $event->user
            ? Audit::record('logout', $event->user, actor: $event->user)
            : null);
        Event::listen(Failed::class, function (Failed $event) {
            $email = $event->credentials['email'] ?? null;

            // A switched-off account is filtered out before Laravel finds it, so the event has no user.
            // Look the person up by email, so the attempt still shows in their business's activity.
            $user = $event->user ?? ($email ? User::where('email', $email)->first() : null);

            Audit::record(
                'login_failed',
                $user,
                new: ['email' => $email],
                actorName: 'Someone using '.($email ?? 'an unknown email'),
            );
        });
    }
}
