<?php

namespace App\Providers;

use App\Observers\PasskeyObserver;
use App\Services\RecurringTransactionService;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;

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

        // Passkey ceremonies run through the app's WebAuthn controllers
        // (routes/web.php), which keep the front end's /webauthn/* contract.
        Passkeys::ignoreRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register observers
        Passkey::observe(PasskeyObserver::class);

        // Force HTTPS in production
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Escape Markdown in values echoed into Markdown mail ({{ }}), so a
        // user-authored budget or profile name like "[Verify](https://...)"
        // renders as text instead of a link sent from our domain.
        Markdown::withSecuredEncoding();
    }
}
