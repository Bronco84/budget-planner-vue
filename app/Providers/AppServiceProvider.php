<?php

namespace App\Providers;

use App\Services\RecurringTransactionService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(RecurringTransactionService::class, function ($app) {
            return new RecurringTransactionService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Force HTTPS in production
        if ($this->app->environment('production')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

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
