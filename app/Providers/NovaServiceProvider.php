<?php

namespace App\Providers;

use App\Models\User;
use App\Nova\Dashboards\Main;
use Illuminate\Support\Facades\Gate;
use Laravel\Nova\Dashboard;
use Laravel\Nova\Nova;
use Laravel\Nova\NovaApplicationServiceProvider;
use Laravel\Nova\Tool;

class NovaServiceProvider extends NovaApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        //
    }

    /**
     * Register the configurations for Laravel Fortify.
     */
    protected function fortify(): void
    {
        Nova::fortify()
            // Passwords are not used (passkeys / magic links only), so Nova offers
            // no password update, verification or two-factor features.
            ->features([])
            ->register();
    }

    /**
     * Register the Nova routes.
     *
     * Nova has no login or password reset of its own: it shares the app's web
     * session, guests are sent to the app's passkey / magic link login, and
     * signing out uses the app's logout route.
     */
    protected function routes(): void
    {
        Nova::routes()
            ->withoutAuthenticationRoutes(login: '/login', logout: '/logout')
            ->withoutPasswordResetRoutes(forgotPassword: false, resetPassword: false)
            ->withoutEmailVerificationRoutes()
            ->register();
    }

    /**
     * Register the Nova gate.
     *
     * This gate determines who can access Nova in non-local environments.
     * Access depends on the users.is_admin flag only.
     */
    protected function gate(): void
    {
        Gate::define('viewNova', function (User $user) {
            return $user->is_admin === true;
        });
    }

    /**
     * Get the dashboards that should be listed in the Nova sidebar.
     *
     * @return array<int, Dashboard>
     */
    protected function dashboards(): array
    {
        return [
            new Main,
        ];
    }

    /**
     * Get the tools that should be listed in the Nova sidebar.
     *
     * @return array<int, Tool>
     */
    public function tools(): array
    {
        return [];
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        parent::register();

        //
    }
}
