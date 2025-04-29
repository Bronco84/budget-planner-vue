<?php

namespace App\Providers;

use App\Services\RecurringTransactionService;
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
        //
    }
}
