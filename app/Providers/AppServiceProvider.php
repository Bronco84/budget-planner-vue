<?php

namespace App\Providers;

use App\Observers\WebAuthnCredentialObserver;
use App\Services\RecurringTransactionService;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laragear\WebAuthn\Models\WebAuthnCredential;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(RecurringTransactionService::class, function ($app) {
            return new RecurringTransactionService;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register observers
        WebAuthnCredential::observe(WebAuthnCredentialObserver::class);

        // Force HTTPS in production
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Escape Markdown in values echoed into Markdown mail ({{ }}), so a
        // user-authored budget or profile name like "[Verify](https://...)"
        // renders as text instead of a link sent from our domain.
        Markdown::withSecuredEncoding();

        // Allow all authenticated users to register WebAuthn credentials
        Gate::define('webauthn.register', function ($user) {
            return true; // All authenticated users can register passkeys
        });

        // Allow all authenticated users to use WebAuthn for login
        Gate::define('webauthn.login', function ($user = null) {
            return true; // Anyone can attempt to login with passkeys
        });
    }
}
